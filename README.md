# Waar Micro Combat

Dans la soufflerie, l'axe des pertes mesure désormais **blessés + morts avant
compression**, sous l'identifiant `rawCasualtyRatio`. Les prisonniers sont un
indicateur séparé ; ils ne sont pas ajoutés aux blessés bruts. En monotype, le
ratio d'effectifs est aussi le ratio du coût perdu ou immobilisé. Les anciens
objectifs `rawLossRatio` (morts seuls) doivent être remesurés ; la géométrie peut
être réassociée explicitement. Reconstruire le runtime Rust après mise à jour.

La soufflerie dispose aussi d'un premier optimiseur évolutif : jusqu'à quatre
générations de huit profils, avec descendants issus des résultats précédents,
mutations combinées et export du candidat. La recherche historique à huit profils
reste disponible côté API. Voir
[`docs/spec-generateur-optimiseur-candidats.md`](docs/spec-generateur-optimiseur-candidats.md)
pour le contrat, les preuves attendues et les fonctions encore différées.

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

## PHP coding style

PHP code follows [PSR-12](https://www.php-fig.org/psr/psr-12/) with four spaces,
LF line endings, explicit control-structure braces, and one statement per line.
Array commas have one following space. A closing brace must not share its line
with the next statement (`} else {` and `} catch (...) {` remain conventional).
The 120-character line length is a soft review guideline, not an automatic rewrite.

`.php-cs-fixer.dist.php` covers the first-party PHP in `src/`, `tests/`, `bin/`,
`engines/waar-cohort/{src,bin}/`, `profiles/`, `ops/demo/`, and `autoload.php`.
Imported engine snapshots, experiment inputs, reports, and frozen references are
outside this formatting pass. The official PHP-CS-Fixer v3.95.15 PHAR used here
has SHA-256 `813717fc1c8e7ff01f6896c44ae81574f9d4302f26f01c14b1da838654464103`.
It is not committed. On Windows PowerShell:

```powershell
New-Item -ItemType Directory -Force tmp/tools | Out-Null
curl.exe -L --fail --output tmp/tools/php-cs-fixer-v3.95.15.phar https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v3.95.15/php-cs-fixer.phar
(Get-FileHash -Algorithm SHA256 tmp/tools/php-cs-fixer-v3.95.15.phar).Hash
php tmp/tools/php-cs-fixer-v3.95.15.phar fix --dry-run --using-cache=no --config=.php-cs-fixer.dist.php
php tmp/tools/php-cs-fixer-v3.95.15.phar fix --using-cache=no --config=.php-cs-fixer.dist.php
php bin/check-php-block-boundaries.php
```

Check the downloaded PHAR hash before running `fix` without `--dry-run`.
PHP-CS-Fixer alone does not detect all adjacent `}foreach` or `}$next`
boundaries; the final command catches those. Formatting must be a separate
change from gameplay edits and must not rebuild a campaign image in use.

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
cargo build --locked --release --manifest-path engines/waar-cohort/rust/Cargo.toml
php bin/run-workshop.php
```

Ouvrir ensuite `http://127.0.0.1:8080`. Le serveur ne publie que
`public/workshop/`. Le brouillon et les compositions sont enregistrés dans le
navigateur ; l’export JSON reste disponible si ce stockage ne l’est pas. Il
s’agit d’un atelier local : aucun résultat n’est appliqué aux armées du jeu et
aucun candidat n’est approuvé automatiquement.

Le duel, les mesures et la recherche utilisent le même moteur de cohortes Rust
via un travail JSONL par appel. L'absence ou l'incompatibilité du binaire est une
erreur explicite : il n'existe aucun repli silencieux vers l'ancien moteur. Le
runtime PHP de référence est réservé au diagnostic et se sélectionne
explicitement avec `WAAR_COHORT_RUNTIME=php`.

Un profil `waar-engine-profile/0.1` doit passer par l'action de migration de
l'interface avant son import. La migration crée un profil `0.2`, conserve les
choix encore représentables et marque les anciennes mesures comme obsolètes ;
elle ne réinterprète jamais silencieusement leur physique.

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

Le duel accepte jusqu’à 1 000 000 d’unités par type et par camp, dans la saisie
comme dans l’API. Son coût économique perdu est la somme des morts et blessés
après compression, valorisés au coût du profil ; les prisonniers sont exclus.
Cette correction est identifiée par `wounded-capture-then-compress/2` dans les
rapports PHP et Rust (recompiler le binaire Rust après mise à jour). Les anciens
rapports conservent leur ancien calcul. Les ellipses continuent à mesurer les
blessés + morts avant compression. Ni les vainqueurs ni les règles de combat
ne changent avec cette correction de valorisation.
