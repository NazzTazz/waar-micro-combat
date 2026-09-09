<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\CombatResolver;
use Waar\MicroCombat\CombatRuleset;
use Waar\MicroCombat\CombatSide;
use Waar\MicroCombat\CombatTieBreakPolicy;
use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\Lcg31;
use Waar\MicroCombat\PreparedArmy;
use Waar\MicroCombat\PreparedBattle;
use Waar\MicroCombat\PreparedUnit;
use Waar\MicroCombat\UnitType;

require_once dirname(__DIR__).'/autoload.php';

final class MicroCombatResolverTest extends TestCase
{
    public function testExampleAResolvesOneSimultaneousRoundAndAllThreeMetrics(): void
    {
        $battle = new PreparedBattle(
            CombatRuleset::neutral('example-a', '1', 1),
            $this->army(['soldier' => ['count' => 10, 'attack' => 2, 'structure' => 5, 'cost' => 10]]),
            $this->army(['spearman' => ['count' => 4, 'attack' => 3, 'structure' => 10, 'defense' => '1.5', 'cost' => 25]]),
            42,
        );

        $result = (new CombatResolver())->resolve($battle);

        self::assertSame(CombatSide::Attacker, $result->winner);
        self::assertSame('round-limit-preservation', $result->reason);
        self::assertSame(1, $result->roundsPlayed);
        self::assertSame(18_000_000, $result->rounds[0]['damageByTargetMicro']['attacker']['soldier']);
        self::assertSame(20_000_000, $result->rounds[0]['damageByTargetMicro']['defender']['spearman']);
        self::assertSame(7, $result->attacker->unit(UnitType::Soldier)->survivors);
        self::assertSame(32_000_000, $result->attacker->unit(UnitType::Soldier)->remainingStructureMicro);
        self::assertSame(2, $result->defender->unit(UnitType::Spearman)->survivors);
        self::assertSame(20_000_000, $result->defender->unit(UnitType::Spearman)->remainingStructureMicro);
        self::assertSame(['numerator' => 7, 'denominator' => 10], $result->attacker->metrics()['survivors']);
        self::assertSame(['numerator' => 32_000_000, 'denominator' => 50_000_000], $result->attacker->metrics()['structure']);
        self::assertSame(['numerator' => 70, 'denominator' => 100], $result->attacker->metrics()['economicValue']);
        self::assertSame(['numerator' => 2, 'denominator' => 4], $result->defender->metrics()['survivors']);
        self::assertSame(['numerator' => 20_000_000, 'denominator' => 40_000_000], $result->defender->metrics()['structure']);
        self::assertSame(['numerator' => 50, 'denominator' => 100], $result->defender->metrics()['economicValue']);
    }

    public function testExampleBUsesCountExposureCounterAndNominalSurvivorValue(): void
    {
        $ruleset = CombatRuleset::neutral('example-b', '1', 1)
            ->withDamageFactor(UnitType::Archer, UnitType::Knight, 2);
        $battle = new PreparedBattle(
            $ruleset,
            $this->army(['archer' => ['count' => 2, 'attack' => 10, 'structure' => 1]]),
            $this->army([
                'soldier' => ['count' => 6, 'attack' => 0, 'structure' => 5, 'cost' => 1],
                'knight' => ['count' => 2, 'attack' => 0, 'structure' => 20, 'cost' => 10],
            ]),
            7,
        );

        $result = (new CombatResolver())->resolve($battle);

        self::assertSame(15_000_000, $result->rounds[0]['damageByTargetMicro']['defender']['soldier']);
        self::assertSame(10_000_000, $result->rounds[0]['damageByTargetMicro']['defender']['knight']);
        self::assertSame(3, $result->defender->unit(UnitType::Soldier)->survivors);
        self::assertSame(2, $result->defender->unit(UnitType::Knight)->survivors);
        self::assertSame(30_000_000, $result->defender->unit(UnitType::Knight)->remainingStructureMicro);
        self::assertSame(['numerator' => 5, 'denominator' => 8], $result->defender->metrics()['survivors']);
        self::assertSame(['numerator' => 45_000_000, 'denominator' => 70_000_000], $result->defender->metrics()['structure']);
        self::assertSame(['numerator' => 23, 'denominator' => 26], $result->defender->metrics()['economicValue']);
    }

    public function testExampleCUsesTheRoundedMicroFactorsBeforeDamage(): void
    {
        $random = new Lcg31(42);

        $attackerFactor = $random->nextFactor(100_000);
        $defenderFactor = $random->nextFactor(100_000);

        self::assertSame(1_016_462, $attackerFactor);
        self::assertSame(1_003_964, $defenderFactor);
        self::assertSame(101_646_200, FixedPoint::mulDivNearest(100_000_000, $attackerFactor, FixedPoint::SCALE));
    }

    public function testExampleDAcceptsTheLargeFinalValueWithoutBuildingTheOverflowingProduct(): void
    {
        $basePressure = FixedPoint::checkedMultiply(250_000, FixedPoint::parse(100));
        self::assertSame(25_000_000_000_000, $basePressure);
        self::assertSame(27_500_000_000_000, FixedPoint::mulDivNearest($basePressure, FixedPoint::parse('1.1'), FixedPoint::SCALE));

        $battle = new PreparedBattle(
            CombatRuleset::neutral('example-d', '1', 1),
            $this->army(['soldier' => ['count' => 1, 'attack' => 0, 'structure' => 30_000_000]]),
            $this->army(['spearman' => ['count' => 250_000, 'attack' => 100, 'structure' => 1, 'defense' => '1.1']]),
            1,
        );
        $result = (new CombatResolver())->resolve($battle);

        self::assertSame(27_500_000_000_000, $result->rounds[0]['damageByTargetMicro']['attacker']['soldier']);
        self::assertSame(2_500_000_000_000, $result->attacker->unit(UnitType::Soldier)->remainingStructureMicro);
        self::assertSame(250_001, $battle->attacker->totalCount() + $battle->defender->totalCount());
    }

    public function testRoundLimitComparesPreservationWithoutCrossMultiplicationOrEconomicCost(): void
    {
        $battle = new PreparedBattle(
            CombatRuleset::neutral('ratio-tie-break', '1', 1),
            $this->army(['soldier' => ['count' => 1, 'attack' => 10, 'structure' => 10, 'cost' => 1]]),
            $this->army(['spearman' => ['count' => 1, 'attack' => 2, 'structure' => 20, 'cost' => 1000]]),
            8,
        );

        $result = (new CombatResolver())->resolve($battle);

        self::assertSame(8_000_000, $result->attacker->metrics()['structure']['numerator']);
        self::assertSame(10_000_000, $result->defender->metrics()['structure']['numerator']);
        self::assertSame(CombatSide::Attacker, $result->winner, 'Preservation 8/10 must beat 10/20 despite lower absolute structure and cost.');
        self::assertSame('round-limit-preservation', $result->reason);
        self::assertSame(1, CombatResolver::compareFractions(8_000_000, 10_000_000, 10_000_000, 20_000_000));
    }

    public function testExactPreservationEqualityProducesADraw(): void
    {
        $battle = new PreparedBattle(
            CombatRuleset::neutral('ratio-equality', '1', 1),
            $this->army(['soldier' => ['count' => 1, 'attack' => 4, 'structure' => 10]]),
            $this->army(['spearman' => ['count' => 1, 'attack' => 2, 'structure' => 20]]),
            9,
        );

        $result = (new CombatResolver())->resolve($battle);

        self::assertNull($result->winner);
        self::assertSame('round-limit-equality', $result->reason);
        self::assertSame(0, CombatResolver::compareFractions(8, 10, 16, 20));
    }

    public function testDefenderTieBreakResolvesExactPreservationEqualityWithoutChangingLosses(): void
    {
        $drawBattle = new PreparedBattle(
            CombatRuleset::neutral('ratio-equality', 'legacy', 1),
            $this->army(['soldier' => ['count' => 1, 'attack' => 4, 'structure' => 10]]),
            $this->army(['spearman' => ['count' => 1, 'attack' => 2, 'structure' => 20]]),
            9,
        );
        $defenderBattle = new PreparedBattle(
            CombatRuleset::neutral('ratio-equality', 't28.0', 1, 0, CombatTieBreakPolicy::Defender),
            $drawBattle->attacker,
            $drawBattle->defender,
            $drawBattle->seed,
        );

        $draw = (new CombatResolver())->resolve($drawBattle);
        $result = (new CombatResolver())->resolve($defenderBattle);

        self::assertSame(CombatSide::Defender, $result->winner);
        self::assertSame('defender-tie-break-round-limit-equality', $result->reason);
        self::assertSame($draw->rounds, $result->rounds);
        self::assertSame($draw->attacker->toArray(), $result->attacker->toArray());
        self::assertSame($draw->defender->toArray(), $result->defender->toArray());
    }

    public function testDefenderTieBreakResolvesMutualExtinctionWithoutChangingLosses(): void
    {
        $drawBattle = new PreparedBattle(
            CombatRuleset::neutral('mutual-extinction', 'legacy', 1),
            $this->army(['soldier' => ['count' => 1, 'attack' => 10, 'structure' => 10]]),
            $this->army(['spearman' => ['count' => 1, 'attack' => 10, 'structure' => 10]]),
            13,
        );
        $defenderBattle = new PreparedBattle(
            CombatRuleset::neutral('mutual-extinction', 't28.0', 1, 0, CombatTieBreakPolicy::Defender),
            $drawBattle->attacker,
            $drawBattle->defender,
            $drawBattle->seed,
        );

        $draw = (new CombatResolver())->resolve($drawBattle);
        $result = (new CombatResolver())->resolve($defenderBattle);

        self::assertNull($draw->winner);
        self::assertSame('mutual-extinction', $draw->reason);
        self::assertSame(CombatSide::Defender, $result->winner);
        self::assertSame('defender-tie-break-mutual-extinction', $result->reason);
        self::assertSame($draw->rounds, $result->rounds);
        self::assertSame($draw->attacker->toArray(), $result->attacker->toArray());
        self::assertSame($draw->defender->toArray(), $result->defender->toArray());
    }

    public function testDefenderTieBreakKeepsAnEstablishedAttackerVictory(): void
    {
        $battle = new PreparedBattle(
            CombatRuleset::neutral('attacker-preservation', 't28.0', 1, 0, CombatTieBreakPolicy::Defender),
            $this->army(['soldier' => ['count' => 1, 'attack' => 10, 'structure' => 10]]),
            $this->army(['spearman' => ['count' => 1, 'attack' => 2, 'structure' => 20]]),
            8,
        );

        $result = (new CombatResolver())->resolve($battle);

        self::assertSame(CombatSide::Attacker, $result->winner);
        self::assertSame('round-limit-preservation', $result->reason);
    }

    public function testFractionComparatorMatchesSafeCrossProductsOnSmallValues(): void
    {
        for ($leftDenominator = 1; $leftDenominator <= 12; ++$leftDenominator) {
            for ($rightDenominator = 1; $rightDenominator <= 12; ++$rightDenominator) {
                for ($leftNumerator = 0; $leftNumerator <= $leftDenominator; ++$leftNumerator) {
                    for ($rightNumerator = 0; $rightNumerator <= $rightDenominator; ++$rightNumerator) {
                        self::assertSame(
                            ($leftNumerator * $rightDenominator) <=> ($rightNumerator * $leftDenominator),
                            CombatResolver::compareFractions($leftNumerator, $leftDenominator, $rightNumerator, $rightDenominator),
                        );
                    }
                }
            }
        }
    }

    /** @param array<string, array{count?: int, attack?: string|int, structure?: string|int, defense?: string|int, cost?: int}> $overrides */
    private function army(array $overrides): PreparedArmy
    {
        $units = [];
        foreach (UnitType::cases() as $type) {
            $values = $overrides[$type->value] ?? [];
            $units[] = PreparedUnit::fromDecimals(
                $type,
                $values['count'] ?? 0,
                $values['attack'] ?? 0,
                $values['structure'] ?? 1,
                $values['defense'] ?? 1,
                $values['cost'] ?? 0,
            );
        }

        return new PreparedArmy($units);
    }
}
