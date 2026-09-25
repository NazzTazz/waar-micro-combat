<?php

namespace App\Game\Combat;

final readonly class RoundResult
{
    /** @param array<string,array<string,mixed>> $accuracy */
    public function __construct(public int $number, public AttackResult $attackerAction, public AttackResult $defenderAction, public array $accuracy, public string $attackerDeathRatio, public string $defenderDeathRatio)
    {
    }
    /** @return array<string,mixed> */
    public function toArray(bool $trace = true): array
    {
        $data = ['number' => $this->number, 'accuracy' => $this->accuracy, 'attackerDeathRatio' => $this->attackerDeathRatio, 'defenderDeathRatio' => $this->defenderDeathRatio];
        if ($trace) {
            $data['attackerAction'] = $this->attackerAction->toArray();
            $data['defenderAction'] = $this->defenderAction->toArray();
        }
        return $data;
    }
}
