# Explorateur paramétrique CLI

Cet outil construit des confrontations déclaratives avec la même validation et la même préparation que la synthèse web : `EngineProfile::fromArray()` puis `CohortRequestFactory::combat()`. L’exécution passe par le même runtime de processus et le même calcul batch, via son opération locale `campaignBatch`. Il ne modifie aucun profil partagé. Le plan de démonstration utilise la copie versionnée de l’export authentique, `experiments/campagne-coeur/reference-profile.json`.

## Commandes

```powershell
php bin/parametric-campaign.php preview experiments/parametric-demo/plan.json
php bin/parametric-campaign.php run experiments/parametric-demo/plan.json
php bin/parametric-campaign.php resume experiments/parametric-demo/plan.json
php bin/parametric-campaign.php export experiments/parametric-demo/plan.json
```

`run` et `resume` ont la même sémantique sûre : les lots complets déjà présents sont ignorés. `--stop-after-lots=N` sert à tester une interruption propre. `export` ne lance aucun combat.

## Format du plan

- `profile` : chemin relatif au plan vers un export complet `waar-engine-profile/0.2`. Il est validé mais jamais migré ou reconstruit.
- `sampling.repetitions` : nombre total de combats demandé par expérience et par sens, de 1 à 2 147 483 647. Les valeurs usuelles 1 000, 2 000 et 10 000 sont acceptées sous réserve de `limits.maxCombats`.
- `sampling.batchSize` : taille technique de chaque appel moteur, de 1 à 100. Elle ne change ni les répétitions demandées ni les graines.
- `sampling.baseSeed` : graine commune, de 0 à 2 147 483 647.
- `limits.maxCombats` : plafond bloquant, contrôlé avant le premier appel moteur.
- `axes` : identifiant stable, `path`, liste `values`. Chemins acceptés : champs réels de `units`, `combat`, `weather`, `relations.<source>><cible>.factor`, plus l’état conditionnel `combat.surrender` (`enabled`, `deadPercent` facultatif). Un chemin inconnu est rejeté.
- `crosses` : tous les axes seuls sont inclus. `pairs` et `triplets` valent `"all"` ou une liste explicite. Les témoins et interactions d’ordre inférieur sont générés, puis les configurations effectives identiques sont dédupliquées.
- `scenarios` : sens (`both`, `A-attacks-B`, `B-attacks-A`), météo et effets propres aux identités A/B, armées et variantes de composition.

Armées :

- `explicit` conserve les effectifs quand un prix varie ; le budget réel change.
- `budgetShares` recalcule les effectifs avec les prix de chaque variante. Chaque effectif vaut `floor(budget × part / coût)`. Le reliquat reste non dépensé, sauf `fillRemainderWith` explicite.

Opérations de composition : `scale`/`forceRatio`, `budgetScale`, `replaceBudget` et `add`. `replaceBudget` retire un nombre explicite d’unités source et achète `floor(valeur libérée / coût cible)` unités cibles ; le reliquat est enregistré. Il n’existe ni expression libre ni `eval`.

## Données et reprise

Le dossier de sortie contient les copies exactes `plan.json` et `profile.json`, `manifest.json`, une configuration effective par fichier, puis une requête/réponse par lot. Les écritures JSON sont temporaires puis renommées. La reprise refuse toute différence de plan, profil, sampling, HEAD, état dirty déclaré ou empreinte du binaire.

Les variantes comparables partagent `baseSeed`, `seedKey=0`, les indices globaux et les identités A/B. `startIteration` avance entre lots, `iterations` vaut la taille du lot courant et `totalIterations` reste égal à `sampling.repetitions`. Pour une série de 120, les découpages `100+20` et `40+40+40` utilisent tous deux exactement les indices globaux 0 à 119. Les compteurs entiers sont additionnés à l’export ; aucune moyenne de lots n’est moyennée à nouveau.

Le contrat web persistant `waar-combat-batch-request/2` reste limité à 100 pour `iterations` **et** `totalIterations`. Le CLI utilise l’opération locale `campaignBatch` et le schéma distinct `waar-combat-campaign-batch-request/1`. Cette opération ne change pas le calcul : elle appelle la même résolution batch et le même adressage `scenario_seed(baseSeed, seedKey, startIteration + offset)`. Seul son intervalle global peut dépasser 100 ; chaque appel natif conserve `iterations <= 100`.

`results.csv` expose victoires/nuls/défaites, rounds moyens, morts/blessés bruts, conséquences projetées par camp/type et budgets. `bench_economic_loss_rate` est la valeur, aux coûts du profil, des morts plus blessés projetés divisée par la valeur initiale ; les prisonniers sont exclus. Ce n’est pas une facture réelle de soins. Le batch ne fournit pas les observations individuelles : distributions, quantiles et différences appariées par répétition restent indisponibles.
