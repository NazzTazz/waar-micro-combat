# Relais T31 — première recherche bornée et reproductible

9 septembre 2026 — tranche terminée, prête pour contre-recette Astra.

## Décision d'entrée

La contre-recette T30d est close sans réserve et Tristan autorise le passage à
T31. Le manifeste `t30.1-proposed` reste inchangé pour préserver son empreinte ;
le plan T31 consigne explicitement que ses bornes sont acceptées pour ce run.
T32 n'est pas commencé.

## Commandes de recette

Depuis la racine du dépôt, vers des répertoires absents ou vides :

```powershell
php packages/waar-micro-combat/bin/search-monotype-candidates.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json 314159 8 var/waar-micro-combat/t31-smoke-seed-314159

php packages/waar-micro-combat/bin/search-monotype-candidates.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json 314159 128 var/waar-micro-combat/t31-standard-seed-314159
```

Un septième argument facultatif fixe le plafond de propositions. Sans cet
argument, il vaut dix fois le budget d'évaluations. Une sortie non vide est
refusée avant tout combat.

## Stratégie exacte

Le candidat initial est évalué en premier et compte dans le budget. Pour un
budget `B`, les `floor(B / 2)` candidats uniques suivants sont globaux. Chaque
paramètre reçoit un état successif de `Lcg31`, réduit modulo la taille de sa
grille inclusive. Les candidats restants sont des perturbations du meilleur
courant : pour chaque paramètre, un nouvel état est réduit modulo la largeur
`2 × ceil(10 % de l'étendue) + 1`, puis le décalage obtenu est borné aux limites.
Cette réduction modulo fait partie du contrat reproductible ; aucune uniformité
mathématique parfaite n'est revendiquée.

Chaque proposition est quantifiée au pas `0.001` par T30 avant calcul de son
identité. La clé est le SHA-256 du JSON compact des quinze valeurs dans l'ordre
du manifeste ; identifiant, libellé et version en sont exclus. Un doublon ne
consomme pas le budget d'évaluation, mais consomme une proposition. L'arrêt
survient au budget de candidats uniques ou au plafond de propositions.

Le classement compare uniquement la perte continue T29c croissante. Une égalité
exacte est départagée par l'empreinte paramétrique croissante. Le nombre
d'objectifs atteints et le pire excès restent des diagnostics. Aucun optimum
global n'est revendiqué.

## Exécution et cache témoin

`ExperimentRunner::runWithBaselineReport()` réutilise seulement les agrégats du
témoin lorsque variante, corpus ordonné, répétitions et seed de combat
correspondent exactement. Les combats candidat sont toujours exécutés. Un test
compare le rapport compact au rapport autoritaire complet et exige leur égalité
stricte ; un contrat de sampling différent est rejeté.

Sur le run standard, la recherche exécute `409 600` combats candidat et `3 200`
combats témoin. Les trois finalistes sont ensuite rejoués par le runner complet,
soit `9 600` combats candidat et `9 600` témoin supplémentaires. Total réel :
`432 000` combats. Les trois évaluations rejouées correspondent exactement à
celles de la recherche.

## Résultats

| Run | Évalués | Propositions | Meilleure perte | Objectifs | Nuls | Finalistes | Stricts |
|---|---:|---:|---:|---:|---:|---:|---:|
| smoke | 8/8 | 8/80 | 9,306561977486 | 0/32 | 0 | 3 | 0 |
| standard | 128/128 | 128/1 280 | 6,173725600720 | 0/32 | 0 | 3 | 0 |

La perte initiale vaut `9,739174155519`. Un candidat intermédiaire du run
standard atteint `2/32` objectifs avec une perte `6,725911717271`, mais le
classement contractuel retient ensuite une perte plus faible à `0/32`. Cela
confirme que le nombre atteint ne remplace pas silencieusement T29c.

Finalistes standard, tous sans nul et étiquetés `exploratory` :

| Rang | Candidat | Perte | Objectifs | Empreinte paramétrique |
|---:|---|---:|---:|---|
| 1 | `t31-candidate-0116-d0c5f6473d05` | 6,173725600720 | 0/32 | `d0c5f6473d05c033cfb623731ae0fc5ba95fa9846c5e84c5634270ca0f5fa88c` |
| 2 | `t31-candidate-0128-96382d7e8496` | 6,250069488043 | 0/32 | `96382d7e8496c7de0883f74ca29c76d76938d7b98804efae456c6ca93f268652` |
| 3 | `t31-candidate-0123-3805dc464a60` | 6,284652072411 | 0/32 | `3805dc464a600de1b6c03ea203bb3129b13ee036c951dcbb889cee60cf93e923` |

La recherche est achevée, mais **aucun candidat strictement satisfaisant n'a été
trouvé dans ce budget**. Ce résultat ne prouve pas l'impossibilité des objectifs
et ne déclenche aucun élargissement automatique des bornes.

## Artefacts

Chaque run contient les copies exactes `experiment.json`, `objectives.json` et
`search-space.json`, puis :

- `search-plan.json` : entrées, seeds, stratégie, limites et clé de cache ;
- `search-result.json` : état, compteurs, progression, initial, meilleur et finalistes ;
- `evaluations.jsonl` : une évaluation T29c complète par candidat unique ;
- `candidate-initial.json` et `evaluation-initial.json` : comparateur T28 ;
- `finalists/<rang>-<id>/variant.json`, `evaluation.json` et `micro-report.json` ;
- `execution-metadata.json` : dates, durée, PHP, OS, machine et temps de progression ;
- `report.md` : synthèse déterministe et tableau des finalistes.

Les métadonnées d'exécution sont isolées et exclues des empreintes de résultats.
Machine de recette : `DESKTOP-JFVSFN5`, Windows, PHP 8.2.33. Durées officielles
observées : smoke `14,749 s`, standard `157,169 s`, avec exécution concurrente
pendant une partie de la recette.

## Reproductibilité et empreintes

Après finalisation du schéma, une reproduction de chacun des deux profils
confirme l'identité octet pour octet de leurs 18 artefacts déterministes, dont
les 128 évaluations et les trois relectures du run standard. Les dates, durées
et temps de progression diffèrent uniquement dans `execution-metadata.json`.

SHA-256 du run standard :

- plan : `86AC820727B2291AF0EF04D8D2D8DA76E11DFC943F49A0F73EC6F43DE4065D6D`
- résultat : `DB2AE50C2AB870176F33EE6089179CAF280F15D8F300F0982A516A3052985E9F`
- évaluations JSONL : `9763C14E3F49FDA0CB8047A1275F218ACDBA380B3C32EA0120D9A4C9EF54C49B`
- rapport : `770E5B5047063713ED90D517FF79ECCD517161644D285CB1D2DC78E86D1428F8`
- candidat initial : `BA3F6E075E95A5636D6EE561E8A685745CA3E1A0942A3850CAD33B9C0E7057D3`
- évaluation initiale T29c : `2B611BB207E15DD9BD1C8DA4F1E8BFACF880D766CA7E6EF8F1574B9F66D8CA15`

Le finaliste classé premier porte les empreintes : variante
`14B78A6C9B85FC265D2722F35C8374F1DD0BBE7A85F80043B16FA831B6D2FBE1`,
évaluation `838DFA72C4F5E3EF665B294F046B489F2BF9A861D835B02DF921FB673435500E`
et rapport micro
`FD87EF67F50901B43D2E6556A9F4F48FB8F0BE6BC5173746E233B3E2294FC9B7`.

## Vérifications

- budget unique, initial en premier, déduplication et plafond de propositions ;
- déterminisme du générateur, départage par empreinte et conservation du meilleur ;
- exclusion des candidats avec nuls et diagnostic d'invariant ;
- distinction entre acceptation stricte et résultat exploratoire ;
- export/import des variantes et absence de mutation des sources ;
- cache témoin strictement équivalent au runner complet ;
- refus d'une sortie non vide, JSON/JSONL valides et copies d'entrées exactes ;
- 54 tests PHP du paquet et 9 037 assertions ; 13 tests Legacy/export et 201
  assertions ; test Node et lint des 46 fichiers PHP réussis.

T32 doit consommer les artefacts du run standard sans relancer ni réinterpréter
la recherche.
