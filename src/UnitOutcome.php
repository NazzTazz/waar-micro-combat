<?php

namespace Waar\MicroCombat;

final readonly class UnitOutcome
{
    public function __construct(
        public UnitType $type,
        public int $initial,
        public int $survivors,
        public int $dead,
        public int $initialStructureMicro,
        public int $remainingStructureMicro,
        public int $cost,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'initial' => $this->initial,
            'survivors' => $this->survivors,
            'dead' => $this->dead,
            'initialStructureMicro' => $this->initialStructureMicro,
            'remainingStructureMicro' => $this->remainingStructureMicro,
            'cost' => $this->cost,
        ];
    }
}
