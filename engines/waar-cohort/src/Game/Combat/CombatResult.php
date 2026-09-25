<?php

namespace App\Game\Combat;

use App\Game\Combat\Preparation\PreparedCombatSide;
use App\Game\Combat\Rules\CombatRuleset;

final readonly class CombatResult
{
    /** @param list<RoundResult> $rounds @param array<string,mixed> $decision */
    public function __construct(
        public CombatArmy $attackerArmy,
        public CombatArmy $defenderArmy,
        public array $rounds,
        public ?CombatSide $winner,
        public VictoryReason $reason,
        public CombatSnapshot $snapshot,
        public string $rulesetVersion,
        public PreparedCombatSide $attackerPrepared,
        public PreparedCombatSide $defenderPrepared,
        public array $decision,
        public string $replayHash
    ) {
    }
    /** @return array<string,mixed> */
    public function toArray(CombatRuleset $ruleset): array
    {
        $trace = $this->snapshot->traceLevel === 'full';
        return ['schemaVersion' => 'waar-combat-result/2', 'modelVersion' => CombatRuleset::MODEL_VERSION, 'winner' => $this->winner?->value, 'reason' => $this->reason->value,
            'decision' => $this->decision, 'rulesetVersion' => $this->rulesetVersion, 'replayHash' => $this->replayHash, 'snapshot' => $this->snapshot->toArray(),
            'ruleset' => $ruleset->toArray(), 'initialArmies' => ['attacker' => $this->attackerArmy->initialCounts(), 'defender' => $this->defenderArmy->initialCounts()],
            'attacker' => $this->attackerArmy->toArray($this->attackerPrepared, $ruleset->woundDamageThreshold), 'defender' => $this->defenderArmy->toArray($this->defenderPrepared, $ruleset->woundDamageThreshold),
            'rounds' => array_map(static fn (RoundResult $round) => $round->toArray($trace), $this->rounds)];
    }
}
