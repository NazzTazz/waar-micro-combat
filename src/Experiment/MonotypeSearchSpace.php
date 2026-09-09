<?php

namespace Waar\MicroCombat\Experiment;

use Waar\MicroCombat\FixedPoint;
use Waar\MicroCombat\UnitType;

final readonly class MonotypeSearchSpace
{
    public const SCHEMA_VERSION = 'waar-monotype-search-space/0.1';
    public const REFERENCE_CANONICALIZATION = 'experiment-definition-json-v1';

    /** @param list<array{path: string, label: string, unit: string, initial: string, minimum: string, maximum: string, quantization: string, initialMicro: int, minimumMicro: int, maximumMicro: int}> $parameters */
    private function __construct(
        public string $id,
        public string $version,
        public string $status,
        public string $quantization,
        public array $parameters,
        private ExperimentDefinition $reference,
    ) {
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest, ExperimentDefinition $reference): self
    {
        if (self::SCHEMA_VERSION !== ($manifest['schemaVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported monotype search-space schema.');
        }
        foreach (['id', 'version'] as $field) {
            if (!is_string($manifest[$field] ?? null) || '' === trim($manifest[$field])) {
                throw new \InvalidArgumentException(sprintf('Search-space %s must be a non-empty string.', $field));
            }
        }
        if ('proposed' !== ($manifest['status'] ?? null)) {
            throw new \InvalidArgumentException('T30 search-space status must be "proposed".');
        }
        self::validateSource($manifest['source'] ?? null, $reference);
        $step = self::validateQuantization($manifest['quantization'] ?? null);
        self::validateFrozenDeclaration($manifest['frozen'] ?? null, $reference);

        $rawParameters = $manifest['parameters'] ?? null;
        if (!is_array($rawParameters) || !array_is_list($rawParameters) || 15 !== count($rawParameters)) {
            throw new \InvalidArgumentException('T30 must declare exactly 15 open parameters.');
        }
        $expectedInitials = self::parameterValues($reference->candidate);
        if (15 !== count($expectedInitials)) {
            throw new \InvalidArgumentException('The reference candidate must expose the 12 unit fields and exactly three directed counters.');
        }

        $parameters = [];
        $seen = [];
        foreach ($rawParameters as $raw) {
            if (!is_array($raw) || array_is_list($raw)) {
                throw new \InvalidArgumentException('Every search parameter must be an object.');
            }
            $path = self::text($raw, 'path');
            if (isset($seen[$path])) {
                throw new \InvalidArgumentException(sprintf('Duplicate search parameter path "%s".', $path));
            }
            if (!array_key_exists($path, $expectedInitials)) {
                throw new \InvalidArgumentException(sprintf('Unknown or unopened search parameter path "%s".', $path));
            }
            $seen[$path] = true;
            $initial = self::decimal($raw, 'initial');
            $minimum = self::decimal($raw, 'minimum');
            $maximum = self::decimal($raw, 'maximum');
            $parameterStep = self::decimal($raw, 'quantization');
            $initialMicro = FixedPoint::parse($initial);
            $minimumMicro = FixedPoint::parse($minimum);
            $maximumMicro = FixedPoint::parse($maximum);
            if ($parameterStep !== FixedPoint::format($step)) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" must use the global quantization.', $path));
            }
            if ($initial !== $expectedInitials[$path]) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" initial value differs from the T28 candidate.', $path));
            }
            if ($minimumMicro > $initialMicro || $initialMicro > $maximumMicro) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" bounds do not contain its initial value.', $path));
            }
            if (str_ends_with($path, '.structure') && 0 === $minimumMicro) {
                throw new \InvalidArgumentException('Structure bounds must remain strictly positive.');
            }
            if ((str_ends_with($path, '.defendingEfficiency') || str_ends_with($path, '.factor')) && $maximumMicro > 10 * FixedPoint::SCALE) {
                throw new \InvalidArgumentException('Multiplier bounds cannot exceed the resolver domain.');
            }
            foreach ([$initialMicro, $minimumMicro, $maximumMicro] as $value) {
                if (0 !== $value % $step) {
                    throw new \InvalidArgumentException(sprintf('Parameter "%s" values must respect quantization.', $path));
                }
            }
            $parameters[] = [
                'path' => $path,
                'label' => self::text($raw, 'label'),
                'unit' => self::text($raw, 'unit'),
                'initial' => $initial,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'quantization' => $parameterStep,
                'initialMicro' => $initialMicro,
                'minimumMicro' => $minimumMicro,
                'maximumMicro' => $maximumMicro,
            ];
        }
        if (array_diff_key($expectedInitials, $seen) || array_diff_key($seen, $expectedInitials)) {
            throw new \InvalidArgumentException('The T30 parameter set is incomplete or opens an unsupported field.');
        }

        return new self($manifest['id'], $manifest['version'], $manifest['status'], FixedPoint::format($step), $parameters, $reference);
    }

    public static function fromFile(string $path, ExperimentDefinition $reference): self
    {
        $json = @file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException(sprintf('Unable to read search-space manifest "%s".', $path));
        }
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid search-space JSON: '.$exception->getMessage(), 0, $exception);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new \InvalidArgumentException('The search-space manifest must be a JSON object.');
        }

        return self::fromArray($manifest, $reference);
    }

    public static function canonicalReferenceFingerprint(ExperimentDefinition $reference): string
    {
        $canonical = $reference->toArray();
        foreach (['baseline', 'candidate'] as $variant) {
            usort(
                $canonical[$variant]['counters'],
                static fn (array $left, array $right): int => [$left['acting'], $left['target']] <=> [$right['acting'], $right['target']],
            );
        }

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, string> */
    public function initialValues(): array
    {
        return array_column($this->parameters, 'initial', 'path');
    }

    /** @return array<string, string> */
    public function boundValues(string $bound): array
    {
        if (!in_array($bound, ['minimum', 'maximum'], true)) {
            throw new \InvalidArgumentException('Bound must be "minimum" or "maximum".');
        }

        return array_column($this->parameters, $bound, 'path');
    }

    /**
     * @param array<string, string|int> $values
     * @return array<string, string>
     */
    public function quantize(array $values): array
    {
        $definitions = array_column($this->parameters, null, 'path');
        if (array_diff_key($values, $definitions) || array_diff_key($definitions, $values)) {
            throw new \InvalidArgumentException('A proposal must contain exactly the 15 known parameter paths.');
        }
        $step = FixedPoint::parse($this->quantization);
        $quantized = [];
        foreach ($this->parameters as $parameter) {
            $path = $parameter['path'];
            $raw = $values[$path];
            if (!is_string($raw) && !is_int($raw)) {
                throw new \InvalidArgumentException(sprintf('Proposal "%s" must be a fixed-point decimal.', $path));
            }
            $micro = FixedPoint::parse($raw);
            if ($micro < $parameter['minimumMicro'] || $micro > $parameter['maximumMicro']) {
                throw new \InvalidArgumentException(sprintf('Proposal "%s" is outside its inclusive bounds.', $path));
            }
            $quotient = intdiv($micro, $step);
            $remainder = $micro % $step;
            if ($remainder >= intdiv($step, 2) + ($step % 2)) {
                ++$quotient;
            }
            $quantizedMicro = FixedPoint::checkedMultiply($quotient, $step);
            if ($quantizedMicro < $parameter['minimumMicro'] || $quantizedMicro > $parameter['maximumMicro']) {
                throw new \InvalidArgumentException(sprintf('Quantized proposal "%s" is outside its inclusive bounds.', $path));
            }
            $quantized[$path] = FixedPoint::format($quantizedMicro);
        }

        return $quantized;
    }

    /**
     * @param array<string, string|int> $values
     * @return array<string, mixed>
     */
    public function candidateFromValues(array $values, string $id, string $label, string $version): array
    {
        $values = $this->quantize($values);
        $candidate = $this->reference->candidate->toArray();
        $candidate['id'] = $id;
        $candidate['label'] = $label;
        $candidate['version'] = $version;
        foreach ($values as $path => $value) {
            $parts = explode('.', $path);
            if ('units' === $parts[0]) {
                $candidate['units'][$parts[1]][$parts[2]] = $value;
                continue;
            }
            foreach ($candidate['counters'] as &$counter) {
                if ($parts[1] === $counter['acting'] && $parts[2] === $counter['target']) {
                    $counter['factor'] = $value;
                    break;
                }
            }
            unset($counter);
        }
        $variant = ExperimentVariant::fromArray($candidate);
        $this->validateCandidate($variant);

        return $variant->toArray();
    }

    /** @return array<string, string> */
    public function validateExperiment(ExperimentDefinition $experiment): array
    {
        if ($experiment->id !== $this->reference->id
            || $experiment->label !== $this->reference->label
            || $experiment->iterations !== $this->reference->iterations
            || $experiment->baseSeed !== $this->reference->baseSeed
            || $experiment->baseline->toArray() !== $this->reference->baseline->toArray()
            || array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $experiment->scenarios)
                !== array_map(static fn (ExperimentScenario $scenario): array => $scenario->toArray(), $this->reference->scenarios)) {
            throw new \InvalidArgumentException('The experiment modifies a field frozen by T30.');
        }

        return $this->validateCandidate($experiment->candidate);
    }

    /** @return array<string, string> */
    public function validateCandidate(ExperimentVariant $candidate): array
    {
        $reference = $this->reference->candidate->toArray();
        $values = $candidate->toArray();
        foreach (['maxRounds', 'randomSpread', 'tieBreakPolicy'] as $field) {
            if (($values[$field] ?? null) !== ($reference[$field] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Candidate field "%s" is frozen by T30.', $field));
            }
        }
        foreach (UnitType::cases() as $type) {
            if (($values['units'][$type->value]['cost'] ?? null) !== $reference['units'][$type->value]['cost']) {
                throw new \InvalidArgumentException(sprintf('Candidate cost for "%s" is frozen by T30.', $type->value));
            }
        }
        $parameterValues = self::parameterValues($candidate);
        $expectedPaths = array_column($this->parameters, null, 'path');
        if (array_diff_key($parameterValues, $expectedPaths) || array_diff_key($expectedPaths, $parameterValues)) {
            throw new \InvalidArgumentException('Candidate counter identities are frozen by T30.');
        }
        $step = FixedPoint::parse($this->quantization);
        foreach ($this->parameters as $parameter) {
            $path = $parameter['path'];
            $micro = FixedPoint::parse($parameterValues[$path]);
            if ($micro < $parameter['minimumMicro'] || $micro > $parameter['maximumMicro']) {
                throw new \InvalidArgumentException(sprintf('Candidate parameter "%s" is outside its inclusive bounds.', $path));
            }
            if (0 !== $micro % $step) {
                throw new \InvalidArgumentException(sprintf('Candidate parameter "%s" does not respect quantization.', $path));
            }
        }

        return $parameterValues;
    }

    /** @return array<string, string> */
    private static function parameterValues(ExperimentVariant $variant): array
    {
        $candidate = $variant->toArray();
        $values = [];
        foreach (UnitType::cases() as $type) {
            foreach (['attack', 'structure', 'defendingEfficiency'] as $field) {
                $values['units.'.$type->value.'.'.$field] = $candidate['units'][$type->value][$field];
            }
        }
        $seen = [];
        foreach ($candidate['counters'] as $counter) {
            $identity = $counter['acting'].'->'.$counter['target'];
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException(sprintf('Duplicate directed counter "%s".', $identity));
            }
            $seen[$identity] = true;
            $values['counters.'.$counter['acting'].'.'.$counter['target'].'.factor'] = FixedPoint::format(FixedPoint::parse($counter['factor']));
        }

        return $values;
    }

    /** @param mixed $source */
    private static function validateSource(mixed $source, ExperimentDefinition $reference): void
    {
        if (!is_array($source) || array_is_list($source)
            || ($source['experimentId'] ?? null) !== $reference->id
            || ($source['candidateId'] ?? null) !== $reference->candidate->id
            || ($source['candidateVersion'] ?? null) !== $reference->candidate->ruleset->version) {
            throw new \InvalidArgumentException('Search-space source does not match the T28 experiment.');
        }
        $fingerprint = $source['reference'] ?? null;
        if (!is_array($fingerprint) || array_is_list($fingerprint)
            || self::REFERENCE_CANONICALIZATION !== ($fingerprint['canonicalization'] ?? null)
            || !is_string($fingerprint['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint['sha256'])) {
            throw new \InvalidArgumentException('Search-space reference fingerprint declaration is invalid.');
        }
        $actual = self::canonicalReferenceFingerprint($reference);
        if (!hash_equals($fingerprint['sha256'], $actual)) {
            throw new \InvalidArgumentException(sprintf(
                'T30 canonical reference fingerprint mismatch: expected %s, got %s.',
                $fingerprint['sha256'],
                $actual,
            ));
        }
    }

    /** @param mixed $quantization */
    private static function validateQuantization(mixed $quantization): int
    {
        if (!is_array($quantization) || array_is_list($quantization)
            || 'nearest-half-up' !== ($quantization['rounding'] ?? null)
            || true !== ($quantization['appliedBeforeCandidateIdentity'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported search-space quantization contract.');
        }
        $step = self::decimal($quantization, 'step');
        $stepMicro = FixedPoint::parse($step);
        if (0 === $stepMicro) {
            throw new \InvalidArgumentException('Search-space quantization must be positive.');
        }

        return $stepMicro;
    }

    /** @param mixed $frozen */
    private static function validateFrozenDeclaration(mixed $frozen, ExperimentDefinition $reference): void
    {
        $candidate = $reference->candidate->toArray();
        $expected = [
            'experiment' => [
                'iterations' => $reference->iterations,
                'baseSeed' => $reference->baseSeed,
                'scenarioCount' => count($reference->scenarios),
            ],
            'baseline' => 'entire-variant',
            'candidate' => [
                'maxRounds' => $candidate['maxRounds'],
                'randomSpread' => $candidate['randomSpread'],
                'tieBreakPolicy' => $candidate['tieBreakPolicy'] ?? null,
                'costs' => array_map(static fn (array $unit): int => $unit['cost'], $candidate['units']),
                'counterIdentities' => array_map(static fn (array $counter): string => $counter['acting'].'->'.$counter['target'], $candidate['counters']),
                'implicitCounterFactor' => '1',
            ],
        ];
        if (is_array($frozen)
            && is_array($frozen['candidate'] ?? null)
            && is_array($frozen['candidate']['counterIdentities'] ?? null)
            && array_is_list($frozen['candidate']['counterIdentities'])) {
            sort($frozen['candidate']['counterIdentities'], SORT_STRING);
            sort($expected['candidate']['counterIdentities'], SORT_STRING);
        }
        if ($frozen !== $expected) {
            throw new \InvalidArgumentException('Frozen-field declaration does not match the T28 experiment.');
        }
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
    private static function decimal(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" must be a fixed-point decimal.', $key));
        }

        return FixedPoint::format(FixedPoint::parse($value));
    }
}
