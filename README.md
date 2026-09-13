# Waar Micro Combat

Standalone deterministic micro-combat workbench for Waar's soldier, spearman,
archer, and knight. The package is pure PHP 8.2+, with offline HTML reports and
small dependency-free JavaScript presentation tests.

The repository is public, but the project metadata remains `proprietary`. No
open-source license is granted by this repository. ECharts 5.6.0 is bundled for
offline reports under its own license in `resources/vendor/`.

La [note historique de reprise ergonomique](docs/ergonomie-creation-profil-2026-09-12.md)
décrit le parcours envisagé : créer un profil d’unités, essayer deux armées dans
les deux sens, puis affiner dans la soufflerie ou revenir aux réglages.
L’interface locale décrite plus bas en propose maintenant une première version.

## Install and verify

Requires PHP 8.2+, Composer, and Node.js for the JavaScript tests.

```bash
composer install
composer validate --strict
composer test
composer test:js
composer smoke
```

The smoke command writes an offline JSON/HTML report to `reports/smoke/`. Open
`reports/smoke/report.html` directly in a browser; it has no CDN dependency.

## Run a bounded search

This research smoke evaluates eight candidates with deterministic seed 314159:

```bash
php bin/search-monotype-candidates.php \
  experiments/t28-defender-tie-break.json \
  experiments/objectives/20260909-po-design-01-canonical/acceptance-zones.json \
  experiments/t30-proposed-search-space.json \
  314159 8 reports/search-smoke
```

The search output directory must be absent or empty. For another run, choose a
new output path instead of reusing `reports/search-smoke/`.

`composer smoke` runs the T24 report; it does not run this eight-candidate search.

The 128-candidate T31 search and full T33 five-batch validation are manual,
long-running experiments. A generated candidate is an observation, not an
approval, and a search never changes PO objectives.

## Frozen references

`experiments/references/` contains the legacy overlay input and frozen T28, T31,
T33, and T34 results. Render the T32 comparison from those references with:

```bash
php bin/render-finalist-comparison.php
```

The output is `reports/t32-finalist-comparison/report.html`. Its output directory
must be absent or empty. To render again into a new directory:

```bash
php bin/render-finalist-comparison.php experiments/references/t31-standard-seed-314159 experiments/references/t28-defender-tie-break/micro-report.json reports/t32-finalist-comparison-rerun
```

Reference files are
byte-preserved and must not be reformatted. Their origins and hashes are recorded
in `experiments/references/manifest.sha256` and `docs/extraction-provenance.md`.

## Current research status

T34's official measurements were reproduced in the Astra review, which left an
open provenance reservation (R1). T34 is not recorded as closed. See the
[T34 handoff](docs/waar-micro-combat-t34-handoff.md) for the remaining work.
The initial variant and all three finalists remain at 0/32 objectives in T33;
T34 observations do not approve a candidate.

## Layout

- `src/`: resolver and experiment services.
- `bin/`: CLI experiments and report renderers.
- `experiments/`: definitions, objectives, corpora, and frozen references.
- `resources/`: offline report assets and pinned ECharts bundle.
- `schema/`: JSON contracts.
- `tests/`: PHPUnit and Node tests.
- `docs/`: contracts and historical relay/review records.
- `reports/`: ignored local output.

Historical documents retain paths and commands from the original Waar v3 host.
Use the commands above for this repository. See `docs/historical-documents.md`.

## Compare candidate 116 with Legacy

```bash
php bin/render-candidate-legacy-comparison.php
```

Open `reports/t31-candidate-0116-legacy-comparison/report.html` for twelve
Legacy → candidate 116 vectors on the archived T24 compositions. The default
view multiplies Legacy losses by 20; a second view shows actual percentages.
Both operational headcount and common economic value are available. Negative
transformed indices are preserved. This descriptive report changes no acceptance
zones or objectives and runs no simulations. Pass a new empty output directory
as the sole argument to regenerate. Input hashes are checked before generation.

## Présentation du candidat 116 — monotypes

Le [guide de génération](docs/waar-candidate-116-presentation.md) décrit le HTML
autonome `reports/candidate-116-final/report.html` : 16 duels, ellipses centrées
sur les mesures Legacy (pertes ×20), vecteurs vers le candidat 116 et gameplay
narratif des quatre unités. Les ellipses sont descriptives, sans verdict PO.

La génération de nouvelles observations Legacy est une commande manuelle qui
requiert le dépôt frère `../waar-v3`. Les rendus et les tests courants ne chargent
pas ce moteur. Les [comparaisons à budgets natifs](docs/waar-micro-combat-native-budget-comparison.md)
restent une expérience locale distincte ; les rapports générés sous `reports/`
sont conservés localement et ne sont pas versionnés.

## Soufflerie guidée

La soufflerie locale permet de créer un profil, essayer deux armées dans les
deux sens, mesurer les 16 confrontations monotypes, dessiner 32 zones et lancer
une recherche bornée à huit candidats :

```bash
php bin/run-workshop.php
```

Ouvrir ensuite `http://127.0.0.1:8080`. Le serveur ne publie que
`public/workshop/`. Le brouillon et les compositions sont enregistrés dans le
navigateur ; l’export JSON reste disponible si ce stockage ne l’est pas. Il
s’agit d’un atelier local : aucun résultat n’est appliqué aux armées du jeu et
aucun candidat n’est approuvé automatiquement.

Les JSON de brouillon peuvent contenir des fiches absentes (`null`) : seules les
unités présentes dans un duel sont requises pour le simuler, tandis que
l’affinage exige les quatre fiches. Un champ explicitement invalide reste refusé.
« Valeurs proposées » demande confirmation et ne remplace que les fiches,
en préservant le nom, les relations, la météo et les paramètres de combat.

En affinage, les carrés représentent les observations de référence, les ellipses
les objectifs éditables et les flèches violettes les observations du candidat
explicitement choisi pour comparaison. Dessiner une ellipse ne déplace pas une
observation. Les zones sont importables uniquement pour le même profil, modèle
et contexte de mesure ; PHP contrôle cette provenance avant toute recherche.
La seed de mesure (42 par défaut) reste commune à la référence et aux candidats ;
la seed de recherche (314159) n’est pas une seed de mesure.
Modifier les objectifs ou réglages invalide les résultats, y compris une réponse
encore en cours. La dernière recherche reste exportable en JSON, avec son profil
de référence, après adoption d’un candidat comme nouveau brouillon.
