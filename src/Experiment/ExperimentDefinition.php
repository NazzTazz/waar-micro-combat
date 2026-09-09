<?php

namespace Waar\MicroCombat\Experiment;

final readonly class ExperimentDefinition
{
    /** @param list<ExperimentScenario> $scenarios */
    public function __construct(
        public string $id,
        public string $label,
        public int $iterations,
        public int $baseSeed,
        public ExperimentVariant $baseline,
        public ExperimentVariant $candidate,
        public array $scenarios,
    ) {
        if ('' === trim($id) || '' === trim($label) || $iterations < 1 || $iterations > 100_000) {
            throw new \InvalidArgumentException('Invalid experiment metadata.');
        }
        if ($baseSeed < 0 || $baseSeed > 2_147_483_647 || [] === $scenarios) {
            throw new \InvalidArgumentException('Invalid experiment seed or empty corpus.');
        }
        $ids = array_map(static fn (ExperimentScenario $scenario): string => $scenario->id, $scenarios);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Scenario ids must be unique.');
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid experiment JSON: '.$exception->getMessage(), 0, $exception);
        }
        if (!is_array($values) || 'waar-micro-wind-tunnel/0.1' !== ($values['schemaVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported experiment schema.');
        }
        $scenarios = $values['scenarios'] ?? null;
        if (!is_array($scenarios) || !array_is_list($scenarios)) {
            throw new \InvalidArgumentException('Experiment scenarios must be a list.');
        }

        return new self(
            self::text($values, 'id'),
            self::text($values, 'label'),
            self::integer($values, 'iterations'),
            self::integer($values, 'baseSeed'),
            ExperimentVariant::fromArray(self::object($values, 'baseline')),
            ExperimentVariant::fromArray(self::object($values, 'candidate')),
            array_map(static fn (array $scenario): ExperimentScenario => ExperimentScenario::fromArray($scenario), $scenarios),
        );
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException(sprintf('Unable to read experiment "%s".', $path));
        }

        return self::fromJson($json);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => 'waar-micro-wind-tunnel/0.1',
            'id' => $this->id,
            'label' => $this->label,
            'iterations' => $this->iterations,
            'baseSeed' => $this->baseSeed,
            'baseline' => $this->baseline->toArray(),
            'candidate' => $this->candidate->toArray(),
            'scenarios' => array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $this->scenarios),
        ];
    }

    /** @param array<string, mixed> $values */
    private static function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function object(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be an object.', $key));
        }

        return $value;
    }
}
