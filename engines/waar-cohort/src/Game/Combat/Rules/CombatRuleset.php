<?php

namespace App\Game\Combat\Rules;

use App\Game\Army\UnitType;
use App\Game\Combat\Numeric\CombatFixedPoint;

final readonly class CombatRuleset
{
    public const SCHEMA_VERSION = 'waar-cohort-ruleset/2';
    public const MODEL_VERSION = 'waar-cohort-v2';

    /** @var array<string,UnitDefinition> */
    private array $definitions;
    public float $surrenderDeadRatio;

    /** @param iterable<UnitDefinition> $definitions */
    public function __construct(
        public string $version,
        iterable $definitions,
        public TargetPreferenceMatrix $targeting,
        public EngagementMatrix $engagements,
        public int $maxRounds = 3,
        public bool $surrenderEnabled = false,
        float|int|string $surrenderDeadRatio = 0.20,
        public string $tieBreakCriterion = 'economic',
        public string $equalityPolicy = 'defender',
    ) {
        if ('' === trim($version) || $maxRounds < 1 || $maxRounds > 100) throw new \InvalidArgumentException('Invalid combat ruleset metadata.');
        $this->surrenderDeadRatio=CombatFixedPoint::canonicalize($surrenderDeadRatio);
        if ($this->surrenderDeadRatio < 0 || $this->surrenderDeadRatio > 1) throw new \InvalidArgumentException('Surrender threshold must be between 0 and 1.');
        if (!in_array($tieBreakCriterion, ['economic', 'structure'], true)) throw new \InvalidArgumentException('Unknown tie-break criterion.');
        if (!in_array($equalityPolicy, ['defender', 'draw'], true)) throw new \InvalidArgumentException('Unknown equality policy.');
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->type->value])) throw new \InvalidArgumentException('Duplicate unit definition.');
            $indexed[$definition->type->value] = $definition;
        }
        if (count($indexed) !== count(UnitType::cases())) throw new \InvalidArgumentException('A definition is required for every unit type.');
        $this->definitions = $indexed;
    }

    public function unit(UnitType $type): UnitDefinition { return $this->definitions[$type->value]; }
    /** @return list<UnitDefinition> */
    public function units(): array { return array_map(fn(UnitType $type) => $this->unit($type), UnitType::cases()); }
    public function targetWeight(UnitType $acting, UnitType $target): float { return $this->targeting->weight($acting, $target); }
    public function attackFactor(UnitType $acting, UnitType $target): float { return $this->engagements->get($acting, $target)->attackFactor; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'modelVersion' => self::MODEL_VERSION,
            'version' => $this->version,
            'units' => array_map(static fn(UnitDefinition $unit) => $unit->toArray(), $this->units()),
            'targetingMode' => 'proportional',
            'targeting' => $this->targeting->toArray(),
            'engagements' => $this->engagements->toArray(),
            'maxRounds' => $this->maxRounds,
            'surrender' => ['enabled' => $this->surrenderEnabled, 'deadRatio' => CombatFixedPoint::format($this->surrenderDeadRatio)],
            'tieBreak' => ['criterion' => $this->tieBreakCriterion, 'equality' => $this->equalityPolicy],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if($unknown=array_diff(array_keys($data),['schemaVersion','modelVersion','version','units','targetingMode','targeting','engagements','maxRounds','surrender','tieBreak']))throw new \InvalidArgumentException('Unknown ruleset field: '.reset($unknown));
        if (($data['schemaVersion'] ?? self::SCHEMA_VERSION) !== self::SCHEMA_VERSION || ($data['modelVersion'] ?? self::MODEL_VERSION) !== self::MODEL_VERSION) throw new \InvalidArgumentException('Unsupported cohort ruleset schema or model.');
        if (($data['targetingMode'] ?? 'proportional') !== 'proportional') throw new \InvalidArgumentException('waar-cohort-v2 only supports proportional targeting.');
        $surrender = (array) ($data['surrender'] ?? []);
        $tieBreak = (array) ($data['tieBreak'] ?? []);
        if(array_diff(array_keys($surrender),['enabled','deadRatio'])||array_diff(array_keys($tieBreak),['criterion','equality']))throw new \InvalidArgumentException('Unknown surrender or tie-break field.');
        return new self(
            (string) ($data['version'] ?? ''),
            array_map(static fn(array $unit) => UnitDefinition::fromArray($unit), (array) ($data['units'] ?? [])),
            isset($data['targeting']) ? TargetPreferenceMatrix::fromArray((array) $data['targeting']) : TargetPreferenceMatrix::neutral(),
            isset($data['engagements']) ? EngagementMatrix::fromArray((array) $data['engagements']) : EngagementMatrix::neutral(),
            self::integer($data, 'maxRounds', 3), self::boolean($surrender, 'enabled', false),
            $surrender['deadRatio'] ?? '0.2', (string) ($tieBreak['criterion'] ?? 'economic'), (string) ($tieBreak['equality'] ?? 'defender'),
        );
    }

    /** @param array<string,mixed> $data */
    private static function integer(array $data, string $key, int $default): int { $value=$data[$key]??$default; if(!is_int($value))throw new \InvalidArgumentException("{$key} must be an integer."); return $value; }
    /** @param array<string,mixed> $data */
    private static function boolean(array $data, string $key, bool $default): bool { $value=$data[$key]??$default; if(!is_bool($value))throw new \InvalidArgumentException("{$key} must be a boolean."); return $value; }
}
