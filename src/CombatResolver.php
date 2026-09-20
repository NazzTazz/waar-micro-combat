<?php

namespace Waar\MicroCombat;

final class CombatResolver
{
    public function resolve(PreparedBattle $battle, ?callable $onRound = null): BattleResult
    {
        $states = [
            CombatSide::Attacker->value => $this->initialState($battle->attacker),
            CombatSide::Defender->value => $this->initialState($battle->defender),
        ];
        $initialOutcome = $this->extinctionOutcome($states, $battle->ruleset->tieBreakPolicy);
        if (null !== $initialOutcome) {
            return $this->result($battle, $states, $initialOutcome[0], $initialOutcome[1], []);
        }

        $random = new Lcg31($battle->seed);
        $rounds = [];
        for ($round = 1; $round <= $battle->ruleset->maxRounds; ++$round) {
            $before = null === $onRound ? null : $this->traceStates($states);
            $attackerContributions = $defenderContributions = null === $onRound ? null : [];
            $attackerFactor = $random->nextFactor($battle->ruleset->randomSpreadMicro);
            $defenderFactor = $random->nextFactor($battle->ruleset->randomSpreadMicro);
            $attackerDamage = $this->damage(
                $states[CombatSide::Attacker->value],
                $states[CombatSide::Defender->value],
                false,
                $attackerFactor,
                $battle->ruleset,
                $attackerContributions,
            );
            $defenderDamage = $this->damage(
                $states[CombatSide::Defender->value],
                $states[CombatSide::Attacker->value],
                true,
                $defenderFactor,
                $battle->ruleset,
                $defenderContributions,
            );
            $this->apply($states[CombatSide::Defender->value], $attackerDamage);
            $this->apply($states[CombatSide::Attacker->value], $defenderDamage);
            if (null !== $onRound) {
                $onRound(['number'=>$round, 'before'=>$before, 'after'=>$this->traceStates($states),
                    'attacks'=>['attacker'=>$attackerContributions, 'defender'=>$defenderContributions]]);
            }
            $rounds[] = [
                'number' => $round,
                'randomFactorMicro' => [
                    CombatSide::Attacker->value => $attackerFactor,
                    CombatSide::Defender->value => $defenderFactor,
                ],
                'damageByTargetMicro' => [
                    CombatSide::Attacker->value => $defenderDamage,
                    CombatSide::Defender->value => $attackerDamage,
                ],
            ];

            $outcome = $this->extinctionOutcome($states, $battle->ruleset->tieBreakPolicy);
            if (null !== $outcome) {
                return $this->result($battle, $states, $outcome[0], $outcome[1], $rounds);
            }
        }

        $comparison = self::compareFractions(
            $this->totalRemaining($states[CombatSide::Attacker->value]),
            $this->totalInitial($states[CombatSide::Attacker->value]),
            $this->totalRemaining($states[CombatSide::Defender->value]),
            $this->totalInitial($states[CombatSide::Defender->value]),
        );
        [$winner, $reason] = match ($comparison) {
            1 => [CombatSide::Attacker, 'round-limit-preservation'],
            -1 => [CombatSide::Defender, 'round-limit-preservation'],
            default => CombatTieBreakPolicy::Defender === $battle->ruleset->tieBreakPolicy
                ? [CombatSide::Defender, 'defender-tie-break-round-limit-equality']
                : [null, 'round-limit-equality'],
        };

        return $this->result($battle, $states, $winner, $reason, $rounds);
    }

    /** @return array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> */
    private function initialState(PreparedArmy $army): array
    {
        $state = [];
        foreach ($army->units() as $unit) {
            $structure = FixedPoint::checkedMultiply($unit->count, $unit->structureMicro);
            $state[$unit->type->value] = [
                'unit' => $unit,
                'initial' => $unit->count,
                'survivors' => $unit->count,
                'initialStructureMicro' => $structure,
                'remainingStructureMicro' => $structure,
            ];
        }

        return $state;
    }

    /**
     * @param array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> $acting
     * @param array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> $target
     * @return array<string, int>
     */
    private function damage(array $acting, array $target, bool $defending, int $randomFactorMicro, CombatRuleset $ruleset, ?array &$contributions = null): array
    {
        $damage = array_fill_keys(array_column(UnitType::cases(), 'value'), 0);
        $targetCount = $this->totalSurvivors($target);
        if (0 === $targetCount) {
            return $damage;
        }
        foreach (UnitType::cases() as $actingType) {
            $source = $acting[$actingType->value];
            if (0 === $source['survivors'] || 0 === $source['unit']->attackMicro) {
                continue;
            }
            $pressure = FixedPoint::checkedMultiply($source['survivors'], $source['unit']->attackMicro);
            $basePressure = $pressure;
            $roleFactor = $defending ? $source['unit']->defendingEfficiencyMicro : FixedPoint::SCALE;
            $pressure = FixedPoint::mulDivNearest($pressure, $roleFactor, FixedPoint::SCALE);
            $rolePressure = $pressure;
            $pressure = FixedPoint::mulDivNearest($pressure, $randomFactorMicro, FixedPoint::SCALE);
            foreach (UnitType::cases() as $targetType) {
                $exposedCount = $target[$targetType->value]['survivors'];
                if (0 === $exposedCount) {
                    continue;
                }
                $exposed = FixedPoint::mulDivNearest($pressure, $exposedCount, $targetCount);
                $contribution = FixedPoint::mulDivNearest($exposed, $ruleset->damageFactor($actingType, $targetType), FixedPoint::SCALE);
                $damage[$targetType->value] = FixedPoint::checkedAdd($damage[$targetType->value], $contribution);
                if (null !== $contributions) {
                    $contributions[] = ['source'=>$actingType->value, 'target'=>$targetType->value,
                        'sourceCount'=>$source['survivors'], 'targetCount'=>$exposedCount, 'totalTargetCount'=>$targetCount,
                        'unitAttackMicro'=>$source['unit']->attackMicro,
                        'damagePerTargetMicro'=>FixedPoint::mulDivNearest($contribution,1,$exposedCount),
                        'baseAttackMicro'=>$basePressure, 'defendingFactorMicro'=>$roleFactor,
                        'afterDefenseMicro'=>$rolePressure, 'randomFactorMicro'=>$randomFactorMicro,
                        'afterRandomMicro'=>$pressure, 'exposedAttackMicro'=>$exposed,
                        'counterFactorMicro'=>$ruleset->damageFactor($actingType, $targetType), 'damageMicro'=>$contribution];
                }
            }
        }

        return $damage;
    }

    /** Optional diagnostic snapshot, never used to resolve combat. */
    private function traceStates(array $states): array
    {
        $snapshot=[];
        foreach ($states as $side=>$units) foreach ($units as $type=>$row) {
            $attack=FixedPoint::checkedMultiply($row['survivors'],$row['unit']->attackMicro);
            $factor=$side===CombatSide::Defender->value?$row['unit']->defendingEfficiencyMicro:FixedPoint::SCALE;
            $snapshot[$side][$type]=['count'=>$row['survivors'], 'attackMicro'=>$attack,
                'defendingFactorMicro'=>$factor, 'effectiveAttackMicro'=>FixedPoint::mulDivNearest($attack,$factor,FixedPoint::SCALE),
                'structureMicro'=>$row['remainingStructureMicro']];
        }
        return $snapshot;
    }

    /**
     * @param array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> $target
     * @param array<string, int> $damage
     */
    private function apply(array &$target, array $damage): void
    {
        foreach (UnitType::cases() as $type) {
            $row = &$target[$type->value];
            $row['remainingStructureMicro'] = max(0, $row['remainingStructureMicro'] - $damage[$type->value]);
            $row['survivors'] = 0 === $row['remainingStructureMicro']
                ? 0
                : 1 + intdiv($row['remainingStructureMicro'] - 1, $row['unit']->structureMicro);
            unset($row);
        }
    }

    /** @param array<string, array<string, array{survivors: int}>> $states @return array{?CombatSide, string}|null */
    private function extinctionOutcome(array $states, CombatTieBreakPolicy $tieBreakPolicy): ?array
    {
        $attackerEmpty = 0 === $this->totalSurvivors($states[CombatSide::Attacker->value]);
        $defenderEmpty = 0 === $this->totalSurvivors($states[CombatSide::Defender->value]);

        return match (true) {
            $attackerEmpty && $defenderEmpty => CombatTieBreakPolicy::Defender === $tieBreakPolicy
                ? [CombatSide::Defender, 'defender-tie-break-mutual-extinction']
                : [null, 'mutual-extinction'],
            $attackerEmpty => [CombatSide::Defender, 'attacker-extinction'],
            $defenderEmpty => [CombatSide::Attacker, 'defender-extinction'],
            default => null,
        };
    }

    /**
     * @param array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> $state
     */
    private function totalSurvivors(array $state): int
    {
        $total = 0;
        foreach ($state as $row) {
            $total = FixedPoint::checkedAdd($total, $row['survivors']);
        }

        return $total;
    }

    /** @param array<string, array{remainingStructureMicro: int}> $state */
    private function totalRemaining(array $state): int
    {
        $total = 0;
        foreach ($state as $row) {
            $total = FixedPoint::checkedAdd($total, $row['remainingStructureMicro']);
        }

        return $total;
    }

    /** @param array<string, array{initialStructureMicro: int}> $state */
    private function totalInitial(array $state): int
    {
        $total = 0;
        foreach ($state as $row) {
            $total = FixedPoint::checkedAdd($total, $row['initialStructureMicro']);
        }

        return $total;
    }

    /**
     * Compares non-negative fractions without cross multiplication.
     */
    public static function compareFractions(int $leftNumerator, int $leftDenominator, int $rightNumerator, int $rightDenominator): int
    {
        if ($leftNumerator < 0 || $rightNumerator < 0 || $leftDenominator <= 0 || $rightDenominator <= 0) {
            throw new \InvalidArgumentException('Fractions must be non-negative with positive denominators.');
        }
        $direction = 1;
        while (true) {
            $leftWhole = intdiv($leftNumerator, $leftDenominator);
            $rightWhole = intdiv($rightNumerator, $rightDenominator);
            if ($leftWhole !== $rightWhole) {
                return $direction * ($leftWhole <=> $rightWhole);
            }
            $leftRemainder = $leftNumerator % $leftDenominator;
            $rightRemainder = $rightNumerator % $rightDenominator;
            if (0 === $leftRemainder || 0 === $rightRemainder) {
                return $direction * match (true) {
                    0 === $leftRemainder && 0 === $rightRemainder => 0,
                    0 === $leftRemainder => -1,
                    default => 1,
                };
            }
            [$leftNumerator, $leftDenominator] = [$leftDenominator, $leftRemainder];
            [$rightNumerator, $rightDenominator] = [$rightDenominator, $rightRemainder];
            $direction *= -1;
        }
    }

    /**
     * @param array<string, array{unit: PreparedUnit, initial: int, survivors: int, initialStructureMicro: int, remainingStructureMicro: int}> $state
     */
    private function sideOutcome(array $state): SideOutcome
    {
        $units = [];
        foreach (UnitType::cases() as $type) {
            $row = $state[$type->value];
            $units[] = new UnitOutcome(
                $type,
                $row['initial'],
                $row['survivors'],
                $row['initial'] - $row['survivors'],
                $row['initialStructureMicro'],
                $row['remainingStructureMicro'],
                $row['unit']->cost,
            );
        }

        return new SideOutcome($units);
    }

    /** @param array<string, array<string, mixed>> $states @param list<array<string, mixed>> $rounds */
    private function result(PreparedBattle $battle, array $states, ?CombatSide $winner, string $reason, array $rounds): BattleResult
    {
        return new BattleResult(
            $winner,
            $reason,
            count($rounds),
            $this->sideOutcome($states[CombatSide::Attacker->value]),
            $this->sideOutcome($states[CombatSide::Defender->value]),
            $rounds,
            $battle->ruleset->id,
            $battle->ruleset->version,
            $battle->seed,
        );
    }
}
