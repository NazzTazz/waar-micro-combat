<?php

namespace Waar\MicroCombat\Bagaar;

/** Explicit first-version behavior; a scripted policy can later implement PlayerPolicy. */
final readonly class BuiltinPolicy implements PlayerPolicy
{
    public const NAMES = ['rageux', 'grenouille', 'ascenseur', 'fermier', 'scripteur'];

    public function __construct(private string $name)
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException('Profil joueur inconnu.');
        }
    }

    public function next(array $observation): ?array
    {
        $self = $observation['self'];
        $attempts = $observation['attempts'];
        $costs = $observation['costs'];
        if ($this->name === 'ascenseur' && !$self['autoSurrender'] && !self::attempted($attempts, 'autoSurrender')) {
            return ['type' => 'autoSurrender', 'enabled' => true];
        }
        if ($this->name === 'scripteur' && array_sum($self['hospital']) > 0 && !self::attempted($attempts, 'heal')) {
            return ['type' => 'heal'];
        }
        if (!self::attempted($attempts, 'mine')) {
            $mine = HostRules::mineUpgrade($self['mineLevel']);
            if ($self['gold'] >= $mine['gold'] && $self['glory'] >= $mine['glory']) {
                return ['type' => 'mine'];
            }
        }
        if (!self::attempted($attempts, 'hospital') && $self['hospitalLevel'] === 0) {
            $hospital = HostRules::hospitalUpgrade(0);
            if ($self['gold'] >= $hospital['gold']) {
                return ['type' => 'hospital'];
            }
        }
        if (!self::attempted($attempts, 'recruit')) {
            $recruitment = $this->recruitment($observation);
            if ($recruitment !== null) {
                return ['type' => 'recruit', 'units' => $recruitment];
            }
        }
        if (array_sum($self['army']) === 0 || $self['attacks'] === 0) {
            return null;
        }
        if (in_array($this->name, ['scripteur', 'ascenseur'], true)) {
            $spy = self::attempted($attempts, 'spy') ? null : $this->spyCandidate($observation);
            if ($spy !== null) {
                return ['type' => 'spy', 'target' => $spy];
            }
        }
        return $this->attack($observation);
    }

    private function recruitment(array $view): ?array
    {
        $self = $view['self'];
        $budget = (int) floor($self['gold'] * ($this->name === 'grenouille' ? 0.7 : 0.5));
        if ($budget < min($view['costs'])) {
            return null;
        }
        $weights = match ($this->name) {
            'rageux' => ['archer' => 0.75, 'soldier' => 0.25],
            'grenouille' => ($self['soldierParadigm'] ?? false) ? ['soldier' => 1.0] : ['soldier' => 0.5, 'spearman' => 0.3, 'knight' => 0.2],
            'ascenseur' => ($self['glory'] >= 30 && HostRules::armyValue($self['army'], $view['costs']) < 2000)
                ? ['spearman' => 1.0] : ['knight' => 0.55, 'spearman' => 0.45],
            'fermier' => ['soldier' => 0.65, 'spearman' => 0.35],
            'scripteur' => ['soldier' => 0.4, 'spearman' => 0.25, 'archer' => 0.2, 'knight' => 0.15],
        };
        $units = HostRules::emptyArmy();
        foreach ($weights as $type => $share) {
            $units[$type] = (int) floor($budget * $share / $view['costs'][$type]);
        }
        return array_sum($units) > 0 ? $units : null;
    }

    private function spyCandidate(array $view): ?string
    {
        $self = $view['self'];
        $price = HostRules::espionageCost($self['glory']);
        if ($self['gold'] < $price) {
            return null;
        }
        foreach ($view['targets'] as $target) {
            if ($target['kind'] !== 'player' || abs($target['glory'] - $self['glory']) > ($view['spyRange'] ?? 10)
                || self::attempted($view['attempts'], 'spy', $target['id'])) {
                continue;
            }
            $report = $view['reports'][$target['id']] ?? null;
            if ($report === null || ($view['tick'] - ($report['tick'] ?? -100)) >= 6) {
                return $target['id'];
            }
        }
        return null;
    }

    private function attack(array $view): ?array
    {
        $self = $view['self'];
        $eligible = array_values(array_filter($view['targets'], static fn (array $target): bool =>
            abs($target['glory'] - $self['glory']) <= HostRules::ATTACK_RANGE));
        $villages = array_values(array_filter($eligible, static fn (array $target): bool => $target['kind'] === 'village'));
        $players = array_values(array_filter($eligible, static fn (array $target): bool => $target['kind'] === 'player'));
        if ($this->name === 'rageux') {
            foreach ($view['events'] as $event) {
                $enemy = $event['attacker'] ?? null;
                if (($event['defender'] ?? null) !== $self['id'] || $enemy === null || ($event['tick'] ?? -1) < $view['tick'] - 1) {
                    continue;
                }
                foreach ($players as $player) {
                    if ($player['id'] === $enemy && self::attemptCount($view['attempts'], 'attack', $enemy) < 3) {
                        return ['type' => 'attack', 'target' => $enemy];
                    }
                }
            }
            return $players !== [] && self::attemptCount($view['attempts'], 'attack') < 1
                ? ['type' => 'attack', 'target' => $players[0]['id']] : null;
        }
        if ($this->name === 'grenouille' && $view['tick'] < 0.75 * $view['totalTicks']) {
            return $villages !== [] && self::attemptCount($view['attempts'], 'attack') < 1
                ? ['type' => 'attack', 'target' => $villages[0]['id']] : null;
        }
        if ($this->name === 'fermier') {
            if ($villages !== []) {
                return self::attemptCount($view['attempts'], 'attack') < 3
                    ? ['type' => 'attack', 'target' => $villages[0]['id']] : null;
            }
            foreach ($players as $player) {
                if (in_array($player['id'], $self['fridges'] ?? [], true) && self::attemptCount($view['attempts'], 'attack') < 3) {
                    return ['type' => 'attack', 'target' => $player['id']];
                }
            }
            return null;
        }
        if ($this->name === 'scripteur') {
            if (self::attemptCount($view['attempts'], 'attack') >= 1) {
                return null;
            }
            $ownValue = HostRules::armyValue($self['army'], $view['costs']);
            foreach ($players as $player) {
                $report = $view['reports'][$player['id']] ?? null;
                if ($report === null || $view['tick'] - ($report['tick'] ?? -100) > 6) {
                    continue;
                }
                $meanCost = array_sum($view['costs']) / count($view['costs']);
                $estimatedValue = $report['armyTotal'] * $meanCost;
                if ($ownValue >= 1.5 * $estimatedValue && $report['gold'] * 0.1 > $ownValue * 0.03) {
                    return ['type' => 'attack', 'target' => $player['id']];
                }
            }
            return null;
        }
        if ($this->name === 'ascenseur') {
            if (self::attemptCount($view['attempts'], 'attack') >= 2) {
                return null;
            }
            usort($players, static function (array $a, array $b) use ($view): int {
                $aReport = $view['reports'][$a['id']] ?? [];
                $bReport = $view['reports'][$b['id']] ?? [];
                $aScore = ($aReport['armyTotal'] ?? 0) / ($a['glory'] + 1);
                $bScore = ($bReport['armyTotal'] ?? 0) / ($b['glory'] + 1);
                return [-$aScore, $a['id']] <=> [-$bScore, $b['id']];
            });
            $target = $players[0] ?? $villages[0] ?? null;
            return $target === null ? null : ['type' => 'attack', 'target' => $target['id']];
        }
        $target = $players[0] ?? $villages[0] ?? null;
        return $target === null || self::attemptCount($view['attempts'], 'attack') >= 2
            ? null : ['type' => 'attack', 'target' => $target['id']];
    }

    private static function attempted(array $attempts, string $type, ?string $target = null): bool
    {
        return self::attemptCount($attempts, $type, $target) > 0;
    }

    private static function attemptCount(array $attempts, string $type, ?string $target = null): int
    {
        $count = 0;
        foreach ($attempts as $attempt) {
            if (($attempt['type'] ?? null) === $type && ($target === null || ($attempt['target'] ?? null) === $target)) {
                $count++;
            }
        }
        return $count;
    }
}
