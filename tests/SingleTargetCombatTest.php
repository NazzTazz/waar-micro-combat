<?php

namespace Waar\MicroCombat\Tests;

use PHPUnit\Framework\TestCase;
use Waar\MicroCombat\{CombatRuleset, CombatSide, CombatTieBreakPolicy, Lcg31, PreparedArmy, PreparedBattle, PreparedUnit, SingleTargetCombatResolver, UnitType};

require_once dirname(__DIR__).'/autoload.php';

final class SingleTargetCombatTest extends TestCase
{
    private function army(array $counts, int $attack = 10, int $structure = 10, string $defense = '1'): PreparedArmy
    {
        return new PreparedArmy(array_map(static fn ($t) => PreparedUnit::fromDecimals($t, $counts[$t->value] ?? 0, $attack, $structure, $defense), UnitType::cases()));
    }

    private function battle(PreparedArmy $a, PreparedArmy $b, int $seed = 42, int $rounds = 1, ?CombatRuleset $rules = null): PreparedBattle
    {
        return new PreparedBattle($rules ?? CombatRuleset::neutral('individual', 'v1', $rounds, 0), $a, $b, $seed);
    }

    public function testOneFighterCannotDamageTwoTargetsOrSpillLethalDamage(): void
    {
        $battle = $this->battle($this->army(['soldier' => 1], 1000), $this->army(['archer' => 1, 'knight' => 1], 0, 1));
        $trace = [];
        $result = (new SingleTargetCombatResolver())->resolve($battle, static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        self::assertSame(1, $result->defender->metrics()['survivors']['numerator']);
        self::assertSame(1000000, $result->defender->metrics()['structure']['numerator']);
        self::assertSame(1, array_sum(array_column($trace[0]['attacks']['attacker'], 'impactCount')));
        self::assertSame(1, count(array_filter($trace[0]['attacks']['attacker'], static fn ($h) => $h['impactCount'] > 0)));
        self::assertSame(SingleTargetCombatResolver::MODEL, $result->toArray()['targetingModel']);
        self::assertSame(0, (new \Waar\MicroCombat\CombatResolver())->resolve($battle)->defender->metrics()['survivors']['numerator'], 'The historical pooled model reproduces the old multi-target behavior.');
    }

    public function testEveryLivingFighterStrikesOnceAndContributionsMatchTotals(): void
    {
        $battle = $this->battle($this->army(['soldier' => 13, 'spearman' => 7], 2), $this->army(['archer' => 5, 'knight' => 9], 3), 42, 3);
        $resolver = new SingleTargetCombatResolver();
        $trace = [];
        $result = $resolver->resolve($battle, static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        self::assertSame($result->toArray(), $resolver->resolve($battle)->toArray());
        foreach ($trace as $i => $round) {
            if ($i > 0) {
                self::assertSame($trace[$i - 1]['after'], $round['before']);
            }
            foreach (['attacker' => 'defender', 'defender' => 'attacker'] as $source => $target) {
                foreach (UnitType::cases() as $type) {
                    $hits = array_filter($round['attacks'][$source], static fn ($h) => $h['source'] === $type->value);
                    self::assertSame($round['before'][$source][$type->value]['count'], array_sum(array_column($hits, 'impactCount')));
                    $received = array_filter($round['attacks'][$source], static fn ($h) => $h['target'] === $type->value);
                    self::assertSame($result->rounds[$i]['damageByTargetMicro'][$target][$type->value], array_sum(array_column($received, 'damageMicro')));
                    $dead = $round['before'][$target][$type->value]['count'] - $round['after'][$target][$type->value]['count'];
                    self::assertLessThanOrEqual(array_sum(array_column($received, 'impactCount')), $dead);
                }
            }
        }
    }

    public function testSimultaneousLethalStrikesAndDefenderTieBreak(): void
    {
        $army = $this->army(['soldier' => 1], 100, 1);
        $rules = CombatRuleset::neutral('individual', 'v1', 3, 0, CombatTieBreakPolicy::Defender);
        $result = (new SingleTargetCombatResolver())->resolve($this->battle($army, $army, 42, 3, $rules));
        self::assertSame(0, $result->attacker->unit(UnitType::Soldier)->survivors);
        self::assertSame(0, $result->defender->unit(UnitType::Soldier)->survivors);
        self::assertSame(CombatSide::Defender, $result->winner);
        self::assertSame(1, $result->roundsPlayed);
    }

    public function testIndividualWoundsPersistAndDeadTargetsDisappearNextRound(): void
    {
        $a = $this->army(['soldier' => 1], 1, 100);
        $b = $this->army(['archer' => 1], 0, 3);
        $trace = [];
        $result = (new SingleTargetCombatResolver())->resolve($this->battle($a, $b, 42, 3), static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        self::assertSame([2000000, 1000000, 0], array_map(static fn ($r) => $r['after']['defender']['archer']['structureMicro'], $trace));
        self::assertSame([1, 1, 0], array_map(static fn ($r) => $r['after']['defender']['archer']['count'], $trace));
        self::assertSame(CombatSide::Attacker, $result->winner);
    }

    public function testCollisionsDoNotRetargetAndWoundsAreNotPooled(): void
    {
        $resolver = new SingleTargetCombatResolver();
        $collision = false;
        $split = false;
        for ($seed = 0;$seed < 30;++$seed) {
            $result = $resolver->resolve($this->battle($this->army(['soldier' => 2], 1), $this->army(['archer' => 2], 0, 2), $seed));
            $outcome = $result->defender->unit(UnitType::Archer);
            $collision = $collision || $outcome->survivors === 1;
            $split = $split || $outcome->survivors === 2;
            self::assertSame(2000000, $outcome->remainingStructureMicro);
            $lethal = $resolver->resolve($this->battle($this->army(['soldier' => 2], 100), $this->army(['archer' => 2], 0, 2), $seed));
            self::assertSame($outcome->survivors === 1 ? 1 : 0, $lethal->defender->unit(UnitType::Archer)->survivors);
        }
        self::assertTrue($collision, 'Two attackers may choose the same individual.');
        self::assertTrue($split, 'Separate wounded individuals must remain alive.');
    }

    public function testDefenseCounterAndPerHitRoundingAreVisible(): void
    {
        $rules = CombatRuleset::neutral('individual', 'v1', 1, 0)->withDamageFactor(UnitType::Archer, UnitType::Soldier, '2');
        $battle = $this->battle($this->army(['soldier' => 1], 0, 100), $this->army(['archer' => 1], 10, 100, '0.75'), 42, 1, $rules);
        $trace = [];
        (new SingleTargetCombatResolver())->resolve($battle, static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        $hit = $trace[0]['attacks']['defender'][0];
        self::assertSame(1, $hit['impactCount']);
        self::assertSame(7500000, $hit['unitAfterDefenseMicro']);
        self::assertSame(15000000, $hit['damagePerHitMicro']);
        self::assertSame(15000000, $hit['damageMicro']);
    }

    public function testTargetSamplerIsReproducibleAndNotModuloAlternation(): void
    {
        $a = new Lcg31(42);
        $b = new Lcg31(42);
        $counts = [0, 0, 0];
        for ($i = 0;$i < 12000;++$i) {
            $index = $a->nextIndex(3);
            self::assertSame($index, $b->nextIndex(3));
            ++$counts[$index];
        }
        foreach ($counts as $count) {
            self::assertGreaterThan(3600, $count);
            self::assertLessThan(4400, $count);
        }
    }

    public function testRoundingHappensPerFighterAndZeroDamageStillUsesOneTarget(): void
    {
        $tiny = new PreparedArmy(array_map(static fn ($t) => PreparedUnit::fromDecimals($t, $t === UnitType::Archer ? 2 : 0, '0.000001', 10, '0.5'), UnitType::cases()));
        $trace = [];
        $result = (new SingleTargetCombatResolver())->resolve($this->battle($this->army(['soldier' => 1], 0), $tiny), static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        self::assertSame(2, $result->rounds[0]['damageByTargetMicro']['attacker']['soldier']);
        self::assertSame(1, array_sum(array_column($trace[0]['attacks']['attacker'], 'impactCount')));
        self::assertSame(0, array_sum(array_column($trace[0]['attacks']['attacker'], 'damageMicro')));
        self::assertSame(2, array_sum(array_column($trace[0]['attacks']['defender'], 'impactCount')));
    }

    public function testEmptyBattleAndAllocationLimitAreExplicit(): void
    {
        $result = (new SingleTargetCombatResolver())->resolve($this->battle($this->army([]), $this->army([])));
        self::assertSame(0, $result->roundsPlayed);
        self::assertNull($result->winner);
        $this->expectException(\InvalidArgumentException::class);
        (new SingleTargetCombatResolver())->resolve($this->battle($this->army(['soldier' => 400401]), $this->army(['archer' => 1])));
    }

    public function testNextRoundChoosesOnlySurvivingIndividuals(): void
    {
        $trace = [];
        $result = (new SingleTargetCombatResolver())->resolve($this->battle($this->army(['soldier' => 1], 1000), $this->army(['archer' => 1, 'knight' => 1], 0, 1), 42, 3), static function ($r) use (&$trace) {
            $trace[] = $r;
        });
        self::assertSame(2, $result->roundsPlayed);
        self::assertCount(1, $trace[1]['attacks']['attacker']);
        $second = $trace[1]['attacks']['attacker'][0];
        self::assertSame(1, $second['impactCount']);
        self::assertSame(1, $trace[1]['before']['defender'][$second['target']]['count']);
        self::assertSame(0, $result->defender->metrics()['survivors']['numerator']);
    }
}
