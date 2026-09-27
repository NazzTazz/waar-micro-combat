<?php

namespace Waar\MicroCombat\Bagaar;

/** Explicit first-version behavior; a scripted policy can later implement PlayerPolicy. */
final readonly class BuiltinPolicy implements PlayerPolicy
{
    public const NAMES = ['rageux', 'grenouille', 'ascenseur', 'fermier', 'scripteur', 'casual'];

    public function __construct(private string $name)
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException('Profil joueur inconnu.');
        }
    }

    public function afterCombat(array $observation): ?array
    {
        return $this->name === 'scripteur' && array_sum($observation['self']['hospital']) > 0
            ? ['type' => 'heal'] : null;
    }

    public function intention(array $self, int $tick, int $totalTicks, array $names): array
    {
        if (($self['status'] ?? 'active') === 'abandoned') {
            return ['goal' => 'Jeu abandonné', 'method' => 'Aucune action prévue ; le compte reste attaquable.'];
        }
        if (($self['status'] ?? 'active') === 'pause') {
            return ['goal' => 'Faire une pause', 'method' => 'Revenir au tick '.$self['pauseUntil'].'.'];
        }
        if ($this->name === 'scripteur' && ($self['hacker'] ?? false)) {
            return ['goal' => "Emmerder l’admin qui regarde la simulation",
                'method' => "Faire abandonner des comptes placés pour écrire « PD » sur le plan ; faire descendre ceux qui ont choisi la reddition automatique pour dégager le dessin."];
        }
        if ($this->name === 'casual' && ($self['protester'] ?? false)) {
            return ['goal' => 'COUCOU JE SUIS UNE BALISE',
                'method' => 'Acheter deux soldats séparément à chaque connexion pour faire clignoter deux fois le point en bleu.'];
        }
        if ($this->name === 'rageux' && ($self['rageTarget'] ?? null) !== null && $tick <= ($self['rageUntil'] ?? 0)) {
            return ['goal' => 'Détruire '.($names[$self['rageTarget']] ?? $self['rageTarget']),
                'method' => 'Répliquer jusqu’à trois fois par heure pendant trois heures.'];
        }
        if (($self['villageFailures'] ?? []) !== []) {
            return ['goal' => 'Agrandir mon armée',
                'method' => 'Limiter les attaques de villages à une par jour, espionner avant et attendre que leur Or soit plein.'];
        }
        if ($this->name === 'fermier' && $tick >= 0.75 * $totalTicks) {
            return ['goal' => 'Gagner la couronne', 'method' => 'Espionner les joueurs en tête et attaquer les cibles abordables.'];
        }
        $mine = HostRules::mineUpgrade($self['mineLevel']);
        if ($self['glory'] >= $mine['glory'] && $self['gold'] < $mine['gold']) {
            return ['goal' => 'Monter la mine suivante',
                'method' => $this->name === 'scripteur'
                    ? 'Suspendre le recrutement et économiser '.($mine['gold'] - $self['gold']).' Or pour gagner '
                        .(HostRules::mineProduction($mine['level']) - HostRules::mineProduction($self['mineLevel'])).' Or par tick.'
                    : 'Économiser '.($mine['gold'] - $self['gold']).' Or tout en recrutant.'];
        }
        $mineGloryGap = $mine['glory'] - $self['glory'];
        if ($this->name === 'scripteur' && $mineGloryGap >= 1 && $mineGloryGap <= 5) {
            return ['goal' => 'Monter la mine suivante',
                'method' => 'Gagner '.$mineGloryGap.' Glwaare par des attaques très favorables, puis épargner '
                    .$mine['gold'].' Or pour gagner '
                    .(HostRules::mineProduction($mine['level']) - HostRules::mineProduction($self['mineLevel'])).' Or par tick.'];
        }
        return match ($this->name) {
            'rageux' => ['goal' => 'Agrandir mon armée', 'method' => 'Recruter des archers et riposter aux attaques.'],
            'grenouille' => $tick < 0.75 * $totalTicks
                ? ['goal' => 'Préparer la percée', 'method' => 'Accumuler une armée sans monter trop vite en Glwaare.']
                : ['goal' => 'Prendre la tête', 'method' => 'Engager l’armée accumulée contre les rivaux.'],
            'ascenseur' => ['goal' => 'Remonter en puissance', 'method' => 'Alterner raids, reddition et reconstruction.'],
            'fermier' => ['goal' => 'Exploiter les villages', 'method' => 'Attaquer les villages à portée ; au-dessus du dernier village, gagner de la Glwaare contre les joueurs pour faire apparaître le suivant.'],
            'scripteur' => ['goal' => 'Gagner la couronne', 'method' => 'Optimiser mine, renseignement, combats et soins.'],
            'casual' => ['goal' => 'Agrandir mon armée', 'method' => 'Jouer un ou deux ticks par jour, recruter et saisir une occasion.'],
        };
    }

    public function next(array $observation): ?array
    {
        $self = $observation['self'];
        $attempts = $observation['attempts'];
        $costs = $observation['costs'];
        if ($this->name === 'ascenseur' && $self['autoSurrender'] !== ($self['cyclePhase'] === 'surrender')
            && !self::attempted($attempts, 'autoSurrender')) {
            return ['type' => 'autoSurrender', 'enabled' => $self['cyclePhase'] === 'surrender'];
        }
        if ($this->name === 'ascenseur' && !self::attempted($attempts, 'phase')) {
            $armyGold = HostRules::armyValue($self['army'], $costs);
            if ($self['cyclePhase'] === 'build' && $armyGold >= 2000) {
                return ['type' => 'phase', 'value' => 'raid'];
            }
            if ($self['cyclePhase'] === 'raid' && $self['glory'] >= 10
                && $armyGold < max(500, $self['peakArmyGold'] * 0.4)) {
                return ['type' => 'phase', 'value' => 'surrender'];
            }
            if ($self['cyclePhase'] === 'rebuild' && $armyGold >= max(2000, $self['peakArmyGold'] * 0.6)) {
                return ['type' => 'phase', 'value' => 'raid'];
            }
        }
        if (!$self['autoSurrender'] && $self['defenseLossStreak'] >= HostRules::SURRENDER_LOSSES
            && ($self['villageCautious'] ?? false) && $self['glory'] >= 40
            && !self::attempted($attempts, 'surrender')) {
            return ['type' => 'surrender'];
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
        if ($this->name === 'casual' && ($self['protester'] ?? false)) {
            return self::attemptCount($attempts, 'recruit') < 2 && $self['gold'] >= $costs['soldier']
                ? ['type' => 'recruit', 'units' => ['soldier' => 1]] : null;
        }
        if (!self::attempted($attempts, 'hospital') && $self['hospitalLevel'] === 0
            && !($this->name === 'scripteur' && self::savingForMine($self))) {
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
        if ($this->name === 'ascenseur' && in_array($self['cyclePhase'], ['build', 'surrender'], true)) {
            return null;
        }
        if ($this->name === 'fermier' && ($observation['tick'] % 6 === 0 || $observation['tick'] >= 0.75 * $observation['totalTicks'])
            && !self::attempted($attempts, 'spy')) {
            $spy = $this->spyCandidate($observation);
            if ($spy !== null) {
                return ['type' => 'spy', 'target' => $spy];
            }
        }
        if ($this->name === 'scripteur' || $this->name === 'casual' || ($this->name === 'ascenseur' && $self['cyclePhase'] !== 'rebuild')) {
            $spy = self::attempted($attempts, 'spy') ? null : $this->spyCandidate($observation);
            if ($spy !== null) {
                return ['type' => 'spy', 'target' => $spy];
            }
        }
        if (self::attemptCount($attempts, 'spy') < 2
            && !($this->name === 'ascenseur' && $self['cyclePhase'] === 'surrender')
            && !($this->name === 'rageux' && ($self['rageTarget'] ?? null) !== null && $observation['tick'] <= ($self['rageUntil'] ?? 0))) {
            $spy = $this->villageSpyCandidate($observation);
            if ($spy !== null) {
                return ['type' => 'spy', 'target' => $spy];
            }
        }
        return $this->attack($observation);
    }

    private function recruitment(array $view): ?array
    {
        $self = $view['self'];
        $mine = HostRules::mineUpgrade($self['mineLevel']);
        $urgentRaid = ($this->name === 'rageux' && ($self['rageTarget'] ?? null) !== null
                && $view['tick'] <= ($self['rageUntil'] ?? 0))
            || ($this->name === 'fermier' && $view['tick'] >= 0.75 * $view['totalTicks']);
        $savingForMine = !$urgentRaid && $self['glory'] >= $mine['glory'] && $self['gold'] < $mine['gold'];
        if ($this->name === 'scripteur' && $savingForMine) {
            return null;
        }
        $budget = (int) floor($self['gold'] * ($savingForMine ? 0.25 : ($this->name === 'grenouille' ? 0.7 : 0.5)));
        if ($budget < min($view['costs'])) {
            return null;
        }
        $weights = match ($this->name) {
            'rageux' => ['archer' => 0.75, 'soldier' => 0.25],
            'grenouille' => ($self['soldierParadigm'] ?? false) ? ['soldier' => 1.0] : ['soldier' => 0.5, 'spearman' => 0.3, 'knight' => 0.2],
            'ascenseur' => $self['cyclePhase'] === 'surrender'
                ? ['spearman' => 1.0] : ['knight' => 0.55, 'spearman' => 0.45],
            'fermier' => ['soldier' => 0.65, 'spearman' => 0.35],
            'scripteur' => ['soldier' => 0.4, 'spearman' => 0.25, 'archer' => 0.2, 'knight' => 0.15],
            'casual' => ['soldier' => 0.6, 'archer' => 0.4],
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
        $targets = $view['targets'];
        $mineGap = self::mineGloryGap($self);
        $minePlan = $this->name === 'scripteur' && !($self['hacker'] ?? false) && $mineGap >= 1 && $mineGap <= 5;
        if ($this->name === 'fermier' && $view['tick'] >= 0.75 * $view['totalTicks']) {
            usort($targets, static fn (array $a, array $b): int =>
                [($b['id'] === ($view['rwaa'] ?? null) ? 1 : 0), $b['glory'], $a['id']]
                <=> [($a['id'] === ($view['rwaa'] ?? null) ? 1 : 0), $a['glory'], $b['id']]);
        }
        foreach ($targets as $target) {
            if ($target['kind'] !== 'player' || abs($target['glory'] - $self['glory']) > ($view['spyRange'] ?? 30)
                || ($minePlan && abs($target['glory'] - $self['glory']) > HostRules::ATTACK_RANGE)
                || self::attempted($view['attempts'], 'spy', $target['id'])) {
                continue;
            }
            $report = $view['reports'][$target['id']] ?? null;
            if ($report === null || ($view['tick'] - ($report['tick'] ?? -100)) >= ($minePlan || ($self['hacker'] ?? false) ? 2 : 6)) {
                return $target['id'];
            }
        }
        return null;
    }

    private function villageSpyCandidate(array $view): ?string
    {
        $self = $view['self'];
        if ($self['gold'] < HostRules::espionageCost($self['glory'])) {
            return null;
        }
        $ownValue = HostRules::armyValue($self['army'], $view['costs']);
        foreach ($view['targets'] as $target) {
            if ($target['kind'] !== 'village' || abs($target['glory'] - $self['glory']) > HostRules::ATTACK_RANGE
                || abs($target['glory'] - $self['glory']) > ($view['spyRange'] ?? 30)
                || self::attempted($view['attempts'], 'spy', $target['id'])
                || self::villageCoolingDown($self, $view['tick'])
                || $ownValue < 1.25 * ($self['villageFailures'][$target['id']] ?? 0)) {
                continue;
            }
            $report = $view['reports'][$target['id']] ?? null;
            if ($report !== null && $view['tick'] - $report['tick'] < 6
                && ($report['tick'] === $view['tick']
                    || $ownValue < 1.5 * self::estimatedVillageArmyValue($view['costs'], $report))) {
                continue;
            }
            return $target['id'];
        }
        return null;
    }

    private static function villageTarget(array $view, array $villages): ?string
    {
        $self = $view['self'];
        $ownValue = HostRules::armyValue($self['army'], $view['costs']);
        foreach ($villages as $village) {
            $report = $view['reports'][$village['id']] ?? null;
            if (($report['tick'] ?? null) !== $view['tick']
                || $ownValue < 1.5 * self::estimatedVillageArmyValue($view['costs'], $report)
                || self::villageCoolingDown($self, $view['tick'])
                || (($self['villageCautious'] ?? false) && ($report['gold'] ?? 0) < self::villageGoldMax($village['glory']))
                || $ownValue < 1.25 * ($self['villageFailures'][$village['id']] ?? 0)) {
                continue;
            }
            return $village['id'];
        }
        return null;
    }

    /** Estimate from the reported headcount, never from the hidden village army. */
    private static function estimatedVillageArmyValue(array $costs, array $report): float
    {
        $progress = min(1.0, max(0, $report['glory'] ?? 0) / 300);
        $estimatedUnitCost = $costs['soldier'] * (0.9 - 0.55 * $progress)
            + $costs['spearman'] * (0.1 + 0.4 * $progress)
            + $costs['knight'] * (0.15 * $progress);
        return $report['armyTotal'] * $estimatedUnitCost;
    }

    private static function villageCoolingDown(array $self, int $tick): bool
    {
        return ($self['villageCautious'] ?? false) && $self['lastVillageAttackTick'] !== null
            && $tick - $self['lastVillageAttackTick'] < 24;
    }

    private static function villageGoldMax(int $glory): int
    {
        return 24 * HostRules::mineProduction(8 + intdiv($glory, 20));
    }

    private function attack(array $view): ?array
    {
        $self = $view['self'];
        $eligible = array_values(array_filter($view['targets'], static fn (array $target): bool =>
            abs($target['glory'] - $self['glory']) <= HostRules::ATTACK_RANGE));
        $villages = array_values(array_filter($eligible, static fn (array $target): bool => $target['kind'] === 'village'));
        $players = array_values(array_filter($eligible, static fn (array $target): bool => $target['kind'] === 'player'));
        if ($this->name === 'rageux') {
            $rageTarget = $self['rageTarget'] ?? null;
            if ($rageTarget !== null && $view['tick'] <= ($self['rageUntil'] ?? 0)) {
                foreach ($players as $player) {
                    if ($player['id'] === $rageTarget && self::attemptCount($view['attempts'], 'attack', $rageTarget) < min(3, self::attackLimit($self, 3))) {
                        return ['type' => 'attack', 'target' => $rageTarget];
                    }
                }
            }
            foreach ($view['events'] as $event) {
                $enemy = $event['attacker'] ?? null;
                if (($event['defender'] ?? null) !== $self['id'] || $enemy === null || ($event['tick'] ?? -1) < $view['tick'] - 1) {
                    continue;
                }
                foreach ($players as $player) {
                    if ($player['id'] === $enemy && self::attemptCount($view['attempts'], 'attack', $enemy) < self::attackLimit($self, 3)) {
                        return ['type' => 'attack', 'target' => $enemy];
                    }
                }
            }
            if ($players !== [] && self::attemptCount($view['attempts'], 'attack') < self::attackLimit($self, 1)) {
                return ['type' => 'attack', 'target' => $players[0]['id']];
            }
            $target = self::villageTarget($view, $villages);
            return $target !== null && self::attemptCount($view['attempts'], 'attack') < self::attackLimit($self, 1)
                ? ['type' => 'attack', 'target' => $target] : null;
        }
        if ($this->name === 'grenouille' && $view['tick'] < 0.75 * $view['totalTicks']) {
            $target = self::villageTarget($view, $villages);
            return $target !== null && self::attemptCount($view['attempts'], 'attack') < self::attackLimit($self, 1)
                ? ['type' => 'attack', 'target' => $target] : null;
        }
        if ($this->name === 'fermier') {
            if (self::attemptCount($view['attempts'], 'attack') >= min(3, self::attackLimit($self, 3))) {
                return null;
            }
            $endgame = $view['tick'] >= 0.75 * $view['totalTicks'];
            $opportunity = self::playerOpportunity($view, $players, $endgame);
            if ($endgame && $opportunity !== null && $opportunity === ($view['rwaa'] ?? null)) {
                return ['type' => 'attack', 'target' => $opportunity];
            }
            $target = self::villageTarget($view, $villages);
            if ($target !== null) {
                return ['type' => 'attack', 'target' => $target];
            }
            if ($opportunity !== null) {
                return ['type' => 'attack', 'target' => $opportunity];
            }
            foreach ($players as $player) {
                if ($villages === [] && in_array($player['id'], $self['fridges'] ?? [], true)
                    && isset($view['reports'][$player['id']]) && $view['tick'] - $view['reports'][$player['id']]['tick'] <= 6) {
                    return ['type' => 'attack', 'target' => $player['id']];
                }
            }
            return null;
        }
        if ($this->name === 'casual') {
            $target = self::playerOpportunity($view, $players, false);
            if ($target === null) {
                $target = self::villageTarget($view, $villages);
            }
            return $target !== null && !self::attempted($view['attempts'], 'attack')
                ? ['type' => 'attack', 'target' => $target] : null;
        }
        if ($this->name === 'scripteur') {
            if ($self['hacker'] ?? false) {
                if (self::attemptCount($view['attempts'], 'attack') >= min(5, self::attackLimit($self, 5))) {
                    return null;
                }
                $target = HackerPlan::choose($players, $view['reports'], $view['costs'],
                    HostRules::armyValue($self['army'], $view['costs']), $view['tick'], $self['hackerVictims'] ?? []);
                return $target === null ? null : ['type' => 'attack', 'target' => $target];
            }
            if (self::attemptCount($view['attempts'], 'attack') >= self::attackLimit($self, 1)) {
                return null;
            }
            $ownValue = HostRules::armyValue($self['army'], $view['costs']);
            $mineGap = self::mineGloryGap($self);
            if ($mineGap >= 1 && $mineGap <= 5) {
                $safe = [];
                foreach ($players as $player) {
                    $report = $view['reports'][$player['id']] ?? null;
                    if ($report === null || $view['tick'] - ($report['tick'] ?? -100) > 2) {
                        continue;
                    }
                    $estimatedValue = $report['armyTotal'] * array_sum($view['costs']) / count($view['costs']);
                    if ($ownValue >= 3 * $estimatedValue) {
                        $safe[] = ['id' => $player['id'], 'value' => $estimatedValue];
                    }
                }
                usort($safe, static fn (array $a, array $b): int => [$a['value'], $a['id']] <=> [$b['value'], $b['id']]);
                if ($safe !== []) {
                    return ['type' => 'attack', 'target' => $safe[0]['id']];
                }
            }
            foreach ($players as $player) {
                $report = $view['reports'][$player['id']] ?? null;
                if ($report === null || $view['tick'] - ($report['tick'] ?? -100) > 6) {
                    continue;
                }
                $meanCost = array_sum($view['costs']) / count($view['costs']);
                $estimatedValue = $report['armyTotal'] * $meanCost;
                $aggression = ($self['aggressionPercent'] ?? 100) / 100;
                if ($ownValue >= 1.5 / $aggression * $estimatedValue
                    && $report['gold'] * 0.1 > max(1, $estimatedValue * 0.03 / $aggression)) {
                    return ['type' => 'attack', 'target' => $player['id']];
                }
            }
            $target = self::villageTarget($view, $villages);
            return $target === null ? null : ['type' => 'attack', 'target' => $target];
        }
        if ($this->name === 'ascenseur') {
            if ($self['cyclePhase'] === 'rebuild') {
                $target = self::villageTarget($view, $villages);
                return $target !== null && self::attemptCount($view['attempts'], 'attack') < min(3, self::attackLimit($self, 3))
                    ? ['type' => 'attack', 'target' => $target] : null;
            }
            if (self::attemptCount($view['attempts'], 'attack') >= self::attackLimit($self, 2)) {
                return null;
            }
            usort($players, static function (array $a, array $b) use ($view): int {
                $aReport = $view['reports'][$a['id']] ?? [];
                $bReport = $view['reports'][$b['id']] ?? [];
                $aScore = ($aReport['armyTotal'] ?? 0) / ($a['glory'] + 1);
                $bScore = ($bReport['armyTotal'] ?? 0) / ($b['glory'] + 1);
                return [-$aScore, $a['id']] <=> [-$bScore, $b['id']];
            });
            $target = $players[0] ?? null;
            if ($target === null) {
                $villageTarget = self::villageTarget($view, $villages);
                return $villageTarget === null ? null : ['type' => 'attack', 'target' => $villageTarget];
            }
            return ['type' => 'attack', 'target' => $target['id']];
        }
        $target = $players[0] ?? null;
        if (self::attemptCount($view['attempts'], 'attack') >= self::attackLimit($self, 2)) {
            return null;
        }
        if ($target !== null) {
            return ['type' => 'attack', 'target' => $target['id']];
        }
        $villageTarget = self::villageTarget($view, $villages);
        return $villageTarget === null ? null : ['type' => 'attack', 'target' => $villageTarget];
    }

    private static function mineGloryGap(array $self): int
    {
        return HostRules::mineUpgrade($self['mineLevel'])['glory'] - $self['glory'];
    }

    private static function savingForMine(array $self): bool
    {
        $mine = HostRules::mineUpgrade($self['mineLevel']);
        return $self['glory'] >= $mine['glory'] && $self['gold'] < $mine['gold'];
    }

    private static function playerOpportunity(array $view, array $players, bool $endgame): ?string
    {
        $self = $view['self'];
        $ownValue = HostRules::armyValue($self['army'], $view['costs']);
        if ($endgame) {
            usort($players, static fn (array $a, array $b): int =>
                [($b['id'] === ($view['rwaa'] ?? null) ? 1 : 0), $b['glory'], $a['id']]
                <=> [($a['id'] === ($view['rwaa'] ?? null) ? 1 : 0), $a['glory'], $b['id']]);
        }
        $unitCost = $view['costs']['soldier'] * 0.5 + $view['costs']['spearman'] * 0.3
            + $view['costs']['archer'] * 0.1 + $view['costs']['knight'] * 0.1;
        foreach ($players as $player) {
            $report = $view['reports'][$player['id']] ?? null;
            if ($report === null || $view['tick'] - ($report['tick'] ?? -100) > 6) {
                continue;
            }
            if ($ownValue >= ($endgame ? 1.1 : 1.3) * $report['armyTotal'] * $unitCost) {
                return $player['id'];
            }
        }
        return null;
    }

    private static function attempted(array $attempts, string $type, ?string $target = null): bool
    {
        return self::attemptCount($attempts, $type, $target) > 0;
    }

    private static function attackLimit(array $self, int $base): int
    {
        return max(1, (int) round($base * ($self['aggressionPercent'] ?? 100) / 100));
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
