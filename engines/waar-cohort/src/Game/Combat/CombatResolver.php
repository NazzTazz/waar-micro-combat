<?php

namespace App\Game\Combat;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;
use App\Game\Combat\Preparation\PreparedCombatSide;
use App\Game\Combat\Rules\CombatRuleset;
use App\Game\Random\AccuracySampler;
use App\Game\Random\RandomGeneratorFactory;

final readonly class CombatResolver implements CombatResolverInterface
{
    private RandomGeneratorFactory $randomFactory;
    private AccuracySampler $accuracySampler;
    public function __construct(private RoundResolver $roundResolver, ?RandomGeneratorFactory $randomFactory = null, ?AccuracySampler $accuracySampler = null)
    {
        $this->randomFactory = $randomFactory ?? new RandomGeneratorFactory();
        $this->accuracySampler = $accuracySampler ?? new AccuracySampler();
    }

    public function resolve(CombatArmy $attacker, CombatArmy $defender, CombatRuleset $ruleset, CombatSnapshot $snapshot): CombatResult
    {
        if ($snapshot->rulesetVersion !== $ruleset->version || $snapshot->numericModelVersion !== CombatFixedPoint::VERSION) {
            throw new \InvalidArgumentException('Snapshot and ruleset versions do not match.');
        }
        if ($attacker->initialCount() === 0 && $defender->initialCount() === 0) {
            throw new \InvalidArgumentException('Both armies cannot be empty.');
        }
        $initial = ['attacker' => $attacker->initialCounts(), 'defender' => $defender->initialCounts()];
        $hash = hash('sha256', CanonicalJson::encode(['ruleset' => $ruleset->toArray(), 'snapshot' => $snapshot->toArray(), 'armies' => $initial]));
        if ($attacker->livingCount() === 0 || $defender->livingCount() === 0) {
            $winner = $attacker->livingCount() > 0 ? CombatSide::Attacker : CombatSide::Defender;
            return $this->result($attacker, $defender, [], $winner, VictoryReason::InitialEmpty, $snapshot, $ruleset, ['criterion' => 'initial_empty'], $hash);
        }
        $attacker = clone $attacker;
        $defender = clone $defender;
        $random = $this->randomFactory->create(\App\Game\Random\StochasticEngineVersion::Lcg31NormalApproximationV1, $snapshot->seed);
        $rounds = [];
        for ($round = 1;$round <= $ruleset->maxRounds;++$round) {
            $attackerStart = clone $attacker;
            $defenderStart = clone $defender;
            $aRandom = $snapshot->addressed($round, 'attacker');
            $dRandom = $snapshot->addressed($round, 'defender');
            [$aAccuracy,$aTrace] = $this->accuracies($attackerStart, $snapshot->attacker, $snapshot->seed, $round, 'attacker', $aRandom);
            [$dAccuracy,$dTrace] = $this->accuracies($defenderStart, $snapshot->defender, $snapshot->seed, $round, 'defender', $dRandom);
            $aAction = $this->roundResolver->resolveAttacks($attackerStart, $defenderStart, $snapshot->attacker, $ruleset, $random, $aAccuracy, false, $aRandom);
            $dAction = $this->roundResolver->resolveAttacks($defenderStart, $attackerStart, $snapshot->defender, $ruleset, $random, $dAccuracy, true, $dRandom);
            $defender = $aAction->targetArmy;
            $attacker = $dAction->targetArmy;
            $rounds[] = new RoundResult($round, $aAction, $dAction, ['attacker' => $aTrace, 'defender' => $dTrace], $attacker->deathRatioText(), $defender->deathRatioText());
            $aEmpty = $attacker->livingCount() === 0;
            $dEmpty = $defender->livingCount() === 0;
            if ($aEmpty || $dEmpty) {
                if ($aEmpty && $dEmpty) {
                    [$winner,$decision] = $this->tieBreak($attacker, $defender, $snapshot->attacker, $snapshot->defender, $ruleset, 'double_elimination');
                } else {
                    $winner = $aEmpty ? CombatSide::Defender : CombatSide::Attacker;
                    $decision = ['criterion' => 'elimination', 'attackerLiving' => $attacker->livingCount(), 'defenderLiving' => $defender->livingCount()];
                }
                return $this->result($attacker, $defender, $rounds, $winner, VictoryReason::Elimination, $snapshot, $ruleset, $decision, $hash);
            }
            if ($ruleset->surrenderEnabled) {
                $aSurrenders = $attacker->reachesDeathRatio($ruleset->surrenderDeadRatio);
                $dSurrenders = $defender->reachesDeathRatio($ruleset->surrenderDeadRatio);
                if ($aSurrenders || $dSurrenders) {
                    if ($aSurrenders && $dSurrenders) {
                        [$winner,$decision] = $this->tieBreak($attacker, $defender, $snapshot->attacker, $snapshot->defender, $ruleset, 'double_surrender');
                    } else {
                        $winner = $aSurrenders ? CombatSide::Defender : CombatSide::Attacker;
                        $decision = ['criterion' => 'surrender', 'threshold' => CombatFixedPoint::format($ruleset->surrenderDeadRatio), 'attackerDeadRatio' => $attacker->deathRatioText(), 'defenderDeadRatio' => $defender->deathRatioText()];
                    }
                    return $this->result($attacker, $defender, $rounds, $winner, VictoryReason::Surrender, $snapshot, $ruleset, $decision, $hash);
                }
            }
        }
        [$winner,$decision] = $this->tieBreak($attacker, $defender, $snapshot->attacker, $snapshot->defender, $ruleset, 'round_limit');
        return $this->result($attacker, $defender, $rounds, $winner, VictoryReason::RoundLimit, $snapshot, $ruleset, $decision, $hash);
    }

    /** @return array{array<string,string>,array<string,array<string,mixed>>} */
    private function accuracies(CombatArmy $army, PreparedCombatSide $prepared, int $seed, int $round, string $role, ?\App\Game\Random\AddressedRandom $addressed = null): array
    {
        $values = $trace = [];
        foreach (UnitType::cases() as $type) {
            $unit = $prepared->unit($type);
            if ($army->livingCount($type) === 0) {
                $values[$type->value] = \App\Game\Combat\Numeric\CombatFixedPoint::format($unit->baseAccuracy);
                $trace[$type->value] = ['sampled' => false, 'lower' => null, 'upper' => null, 'value' => null, 'substream' => null];
                continue;
            }
            $sample = $this->accuracySampler->sample($seed, $round, $role, $type, $unit->baseAccuracy, $unit->accuracySpread, $addressed);
            $values[$type->value] = $sample['value'];
            $trace[$type->value] = ['sampled' => true, ...$sample];
        }
        return [$values, $trace];
    }

    /** @return array{?CombatSide,array<string,mixed>} */
    private function tieBreak(CombatArmy $attacker, CombatArmy $defender, PreparedCombatSide $aPrepared, PreparedCombatSide $dPrepared, CombatRuleset $ruleset, string $trigger): array
    {
        if ($ruleset->tieBreakCriterion === 'economic') {
            $a = $attacker->remainingValue($aPrepared);
            $d = $defender->remainingValue($dPrepared);
            $comparison = $a <=> $d;
            $values = ['attacker' => $a, 'defender' => $d];
        } else {
            $an = $attacker->totalStructureUnits();
            $ad = $attacker->initialStructureUnits($aPrepared);
            $dn = $defender->totalStructureUnits();
            $dd = $defender->initialStructureUnits($dPrepared);
            $comparison = CombatFixedPoint::compareProducts($an, $dd, $dn, $ad);
            $values = ['attacker' => ['remainingUnits' => $an, 'initialUnits' => $ad], 'defender' => ['remainingUnits' => $dn, 'initialUnits' => $dd]];
        }
        if ($comparison === 0) {
            $winner = $ruleset->equalityPolicy === 'defender' ? CombatSide::Defender : null;
        } else {
            $winner = $comparison > 0 ? CombatSide::Attacker : CombatSide::Defender;
        }
        return [$winner, ['trigger' => $trigger, 'criterion' => $ruleset->tieBreakCriterion, 'values' => $values, 'comparison' => $comparison, 'equalityPolicy' => $ruleset->equalityPolicy]];
    }

    /** @param list<RoundResult> $rounds @param array<string,mixed> $decision */
    private function result(CombatArmy $a, CombatArmy $d, array $rounds, ?CombatSide $winner, VictoryReason $reason, CombatSnapshot $snapshot, CombatRuleset $ruleset, array $decision, string $hash): CombatResult
    {
        return new CombatResult($a, $d, $rounds, $winner, $reason, $snapshot, $ruleset->version, $snapshot->attacker, $snapshot->defender, $decision, $hash);
    }
}
