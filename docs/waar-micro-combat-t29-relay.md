# Relais T29c — objectif continu de recherche monotype

9 septembre 2026 — réserve numérique R1 corrigée, prête pour contre-recette Astra.

La contre-recette T29 a reproduit une annulation flottante juste hors de la
frontière. T29c applique la correction demandée et ajoute les deux
non-régressions décrites dans
`docs/waar-micro-combat-t29-astra-review.md`. Le corpus livré et le contrat
fonctionnel restent inchangés.

## Contrat livré

T29 ajoute une entrée continue distincte du prévol binaire accepté en T27B et
du départage T28. Pour chaque objectif canonique, avec l'observation `(x, y)`,
le centre `(cx, cy)` et les rayons `(rx, ry)` :

```text
q = ((x - cx) / rx)² + ((y - cy) / ry)²
ε = 10⁻¹²
b = 1 + ε
excès(q) = 0                              si q ≤ b
           (q - b) / (√q + √b)            sinon
perte = (1 / 32) × somme des 32 excès
```

L'excès est exprimé en rayons normalisés au-delà de la frontière numérique
acceptée. Une observation placée n'importe où dans sa zone vaut zéro : le calcul
n'attire jamais le candidat vers le centre après satisfaction. La normalisation
par `rx` et `ry` rend les deux axes comparables malgré leurs tolérances propres.
La forme rationalisée hors zone est algébriquement équivalente à `√q - √b`,
mais évite que deux racines presque égales soient arrondies au même flottant.

Les 32 zones `survivors` actives et confirmées ont chacune le poids `1/32`.
Valeur économique et structure ont zéro contribution. Le rapport publie la
moyenne, la somme, le pire excès et l'identifiant concerné. Une perte nulle
équivaut aux 32 contributions binaires satisfaites, car les deux évaluations
partagent exactement la même tolérance de frontière.

## Séparation des décisions

La perte continue sert uniquement à classer de futurs candidats. Les contrôles
stricts restent séparés et autoritaires : les 32 objectifs doivent être atteints
et le nombre de nuls doit rester nul. Une moyenne plus faible ne constitue pas
une acceptation du ruleset.

T29 ne définit aucun espace de paramètres et ne lance aucun optimiseur. Le
candidat, le témoin, le corpus, les 200 répétitions, la seed 42, les coûts et le
départage défensif proviennent sans modification du manifeste T28. La tranche
suivante peut cadrer les paramètres ouverts et leurs bornes avant toute recherche.

## Code et artefacts

- Pénalité pure : `packages/waar-micro-combat/src/Experiment/NormalizedEllipseBoundaryPenalty.php`
- Agrégation canonique : `packages/waar-micro-combat/src/Experiment/CanonicalMonotypeSearchObjectiveEvaluator.php`
- Commande : `php packages/waar-micro-combat/bin/evaluate-monotype-search-objective.php`
- Sortie : `var/waar-micro-combat/t29-search-objective/`

La mesure du candidat T28 courant donne :

- perte moyenne : **9,739174155519** rayons normalisés ;
- pire excès : **19,953557618553** sur
  `spearman-vs-knight-defender-tip-survivors` ;
- objectifs atteints : **0 / 32** ;
- nuls : **0 / 3 200**.

Le contrôle d'absence de nuls passe. Le contrôle global reste en échec tant que
les 32 objectifs ne sont pas tous atteints.

## Vérifications

- 33 tests PHP du paquet, 8 953 assertions.
- 13 tests Legacy/export, 201 assertions.
- Test Node, lints PHP et JSON, `git diff --check` réussis.
- Cas unitaires : centre, frontière exacte, marge numérique, croissance hors
  zone, distance invalide, poids égaux, somme des contributions, pire objectif
  et équivalence perte nulle / 32 objectifs atteints.
- Le flottant `1.0000000000010003` et le cas géométrique exact de la réserve R1
  sont `outside` avec une pénalité strictement positive.
- Le prévol T28 régénéré après extraction de la distance conserve exactement ses
  cinq empreintes publiées.

SHA-256 T29 :

- expérience : `66AD161BAAA105F318E603B2C0278101EF38E03F5B74D4F272278EBCFD6E65D4`
- rapport micro : `7714F87417A1083C20D83C210ED55EAB4F4536197DA11580922BDFF3636EE513`
- objectifs : `F4399119A6A82ABFE7FD14965CFE125D5645FBBC2CF5311D3B78F86F76DB8C79`
- évaluation continue : `2B611BB207E15DD9BD1C8DA4F1E8BFACF880D766CA7E6EF8F1574B9F66D8CA15`
- rapport Markdown : `64811798D2BFA91D879984B5C57060BE6D6961928C290B0345984B8B2CF4F41A`
