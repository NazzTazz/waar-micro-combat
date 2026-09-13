<?php

namespace Waar\MicroCombat;

final readonly class BattleResult
{
    /** @param list<array<string, mixed>> $rounds */
    public function __construct(
        public ?CombatSide $winner,
        public string $reason,
        public int $roundsPlayed,
        public SideOutcome $attacker,
        public SideOutcome $defender,
        public array $rounds,
        public string $rulesetId,
        public string $rulesetVersion,
        public int $seed,
        public ?string $targetingModel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return (null === $this->targetingModel ? [] : ['targetingModel'=>$this->targetingModel]) + [
            'winner' => $this->winner?->value,
            'reason' => $this->reason,
            'roundsPlayed' => $this->roundsPlayed,
            'attacker' => $this->attacker->toArray(),
            'defender' => $this->defender->toArray(),
            'rounds' => $this->rounds,
            'rulesetId' => $this->rulesetId,
            'rulesetVersion' => $this->rulesetVersion,
            'seed' => $this->seed,
            'numericModel' => 'micro-6-muldiv-nearest-v1',
        ];
    }
}
