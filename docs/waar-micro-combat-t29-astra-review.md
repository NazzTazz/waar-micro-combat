# Contre-recette Astra T29 — 9 septembre 2026

**Statut final : T29/T29c acceptées dans leur périmètre, réserve R1 levée après
contre-recette du correctif le 9 septembre 2026.** Aucun code applicatif modifié
pendant cette revue. Les constats initiaux ci-dessous sont conservés comme historique.

## Contre-recette du correctif T29c

- La différence des racines utilise désormais la forme rationalisée proposée.
  Les deux régressions demandées sont couvertes par les tests livrés.
- Suite PHP du paquet rejouée sous PHP 8.2.33 : **33 tests, 8 953 assertions**,
  réussis. Suite Node réussie ; lints PHP du service et du test modifiés réussis.
- Reproduction indépendante du cas R1 : état `outside`,
  `q = 1.0000000000010003`, pénalité **1.1102230246246014e-16**, donc strictement
  positive. La réserve est levée.
- Régénération isolée : `var/waar-micro-combat/t29c-astra-review/`.
  Les cinq empreintes correspondent exactement au relais corrigé de Sol.
  Nouvelle empreinte de `search-evaluation.json` :
  `2B611BB207E15DD9BD1C8DA4F1E8BFACF880D766CA7E6EF8F1574B9F66D8CA15`.
- Comparaison avec la première contre-recette : les 32 états binaires et les
  contrôles stricts sont inchangés. Écart maximal des excès :
  **3.5527136788005009e-15**. Expérience, rapport micro, objectifs et rapport
  Markdown restent identiques octet pour octet aux empreintes initiales.
- Les suites Legacy/export déjà passées lors de la première revue n'ont pas
  été rejouées pour cette correction locale ; aucune modification de gameplay.

Cette acceptation valide la fonction de recherche, pas la calibration :
le candidat courant satisfait toujours **0/32 objectifs**, avec **0/3 200 nuls**.

## Réserve R1 — annulation numérique juste hors de la frontière

Dans `NormalizedEllipseBoundaryPenalty::fromSquaredDistance()`, la soustraction
`sqrt(q) - sqrt(1 + epsilon)` peut donner exactement zéro pour un `q` strictement
supérieur à la frontière. Les deux racines sont arrondies au même flottant.

Reproduction exécutée sous PHP 8.2.33 :

```php
require 'packages/waar-micro-combat/autoload.php';

$zone = [
    'center' => ['x' => 0.5, 'y' => 0.5],
    'radii' => ['x' => 0.1, 'y' => 0.1],
];
$x = 0.5999999555868335;
$y = 0.50009424776565492;
$evaluator = new Waar\MicroCombat\Experiment\AcceptanceZoneEvaluator();
$q = $evaluator->normalizedSquaredDistance($x, $y, $zone);
$state = $evaluator->evaluate($x, $y, $zone);
$penalty = (new Waar\MicroCombat\Experiment\NormalizedEllipseBoundaryPenalty())
    ->fromSquaredDistance($q);
// q = 1.0000000000010003 ; state = outside ; penalty = 0.0
```

Impact : un objectif refusé par le contrôle binaire peut contribuer zéro à la
perte continue. Le contrôle strict reste autoritaire et évite une acceptation
indue du ruleset ; le défaut concerne la garantie de la fonction de recherche,
à une distance de l'ordre de la précision machine. Aucun impact constaté sur
les résultats du candidat T28 courant.

Correction proposée, après la branche qui retourne zéro dans la zone : employer
la forme algébriquement équivalente et numériquement stable
`(q - boundary) / (sqrt(q) + sqrt(boundary))`, avec `boundary = 1 + epsilon`.
Ajouter un test du flottant immédiatement supérieur à la frontière et le cas
géométrique ci-dessus : état `outside`, pénalité strictement positive.
Le test actuel vérifie la frontière et q = 4, sans couvrir ce voisin immédiat.

## Vérifications exécutées

- Suite PHP du paquet : **32 tests, 8 950 assertions**, réussis.
- Suites `LegacyReferenceExporterTest` et `LegacyCombatRulesetTest` :
  **13 tests, 201 assertions**, réussis.
- Suite Node `acceptance-zones-model.test.js` : réussie.
- Lints PHP des six fichiers PHP nouveaux ou modifiés de T29 : réussis.
- `git diff --check` sur le périmètre T29 : réussi.
- Régénération isolée : `var/waar-micro-combat/t29-astra-review/`.
  Les cinq empreintes SHA-256 correspondent exactement au relais Sol T29.
- Prévol T28 régénéré : `var/waar-micro-combat/t29-astra-review-t28/`.
  Les cinq empreintes SHA-256 correspondent exactement au relais T28 accepté.
- Recalcul indépendant des 32 distances et excès à partir des observations et
  géométries exportées, avec la forme stable : accord à 1e-12 ; moyenne et
  identifiant du pire objectif vérifiés.

Résultats reproduits : perte moyenne **9,739174155519**, pire excès
**19,953557618553** sur `spearman-vs-knight-defender-tip-survivors`,
**0/32 objectifs atteints**, **0/3 200 nuls**. Le contrôle global reste en échec
sur les objectifs, comme annoncé ; ce n'est pas un échec d'exécution du CLI.

Cette contre-recette porte sur T29, sous PHP CLI 8.2.33. La suite applicative
complète, PHP 8.4, la CI et l'interface navigateur n'ont pas été exécutés.
Aucune recherche de paramètres ni calibration n'a été lancée.
