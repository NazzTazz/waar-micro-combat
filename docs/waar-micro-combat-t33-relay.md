# Relais T33 — stabilité sur les simulations réservées

9 septembre 2026 — corrections R1/R2 acceptées sans réserve ; T33 close.

## Décision d'entrée

Tristan a validé T32 et autorisé T33. La commande consomme sans les modifier
l'initial et les trois finalistes du run accepté
`var/waar-micro-combat/t31-standard-seed-314159/`. Leur ordre T31 est conservé ;
aucun reclassement n'est effectué à partir des résultats T33. Tristan a ensuite
accepté T33 et autorisé T34.

## Commande et ordre d'exécution

Depuis la racine du dépôt, vers une sortie absente ou vide :

```powershell
php packages/waar-micro-combat/bin/validate-finalist-stability.php var/waar-micro-combat/t31-standard-seed-314159 var/waar-micro-combat/t33-finalist-stability
```

La commande vérifie les SHA et l'ordre T31, copie les entrées figées, audite les
seeds, écrit `validation-plan.json`, puis seulement lance les combats. Elle somme
ensuite les compteurs exacts, réévalue les ellipses et vérifie à nouveau les
entrées figées.

Le plan officiel utilise cinq lots de 1 000 répétitions par scénario et candidat,
avec les seeds de base `104729`, `130363`, `155921`, `180749` et `205759`.
L'audit porte sur 3 200 seeds dérivées de la recherche T31 et 80 000 seeds
dérivées de validation, comparées scénario par scénario. Il constate zéro
collision ; aucune seed n'a été corrigée. Les empreintes des listes dérivées de
chaque lot figurent dans le plan.

## Résultat mesuré

Les quatre variantes présentent zéro nul, mais restent toutes à 0/32 objectifs
dans chacun des cinq lots et dans l'agrégat. Aucun objectif ne change d'état
entre lots. Leur statut descriptif est donc `objectifs-non-atteints`.

| Ordre | Candidat | Perte agrégée | Objectifs | Pire excès | Nuls | Statut |
|---:|---|---:|---:|---:|---:|---|
| initial | `roles-a` | 9,774216422227 | 0/32 | 19,953557618553 | 0 | `objectifs-non-atteints` |
| 1 | `t31-candidate-0116-d0c5f6473d05` | 6,218693324140 | 0/32 | 19,953557618553 | 0 | `objectifs-non-atteints` |
| 2 | `t31-candidate-0128-96382d7e8496` | 6,189716862833 | 0/32 | 19,953557618553 | 0 | `objectifs-non-atteints` |
| 3 | `t31-candidate-0123-3805dc464a60` | 6,355786203129 | 0/32 | 19,953557618553 | 0 | `objectifs-non-atteints` |

La perte agrégée du rang 2 est inférieure à celle du rang 1 sur ces lots. Cette
observation ne change pas l'ordre de recherche T31 et ne crée pas un nouveau
classement. Aucun candidat n'est stable au sens strict du contrat T33.

Pertes par lot, dans l'ordre des cinq seeds :

| Candidat | Lot 1 | Lot 2 | Lot 3 | Lot 4 | Lot 5 |
|---|---:|---:|---:|---:|---:|
| `roles-a` | 9,757326 | 9,770555 | 9,771197 | 9,797602 | 9,776923 |
| rang 1 | 6,216876 | 6,199057 | 6,234953 | 6,248371 | 6,198122 |
| rang 2 | 6,183508 | 6,201811 | 6,202510 | 6,188536 | 6,176231 |
| rang 3 | 6,374178 | 6,327995 | 6,348774 | 6,371183 | 6,358751 |

`variationBetweenBatches` publie pour chacun des 32 objectifs le minimum et le
maximum observés de victoire et de survivants, le nombre de lots satisfaisants
et l'éventuel changement d'état. Il s'agit d'une « variation entre lots », pas
d'un intervalle de confiance.

## Agrégation et coût

Chaque rapport de lot contient 32 observations, sa perte, son nombre d'objectifs,
son pire excès, ses nuls et ses contrôles stricts. L'agrégat contient les mêmes
informations après sommation exacte des cinq rapports. Aucune perte ni aucun
statut binaire de lot n'est moyenné pour produire le verdict agrégé.

- candidats : 320 000 combats ;
- témoin neutre, calculé une fois par lot : 80 000 combats ;
- total réellement exécuté : 400 000 combats ;
- coût logique des vingt rapports candidat + témoin : 640 000 combats ;
- 15 réutilisations du témoin validées par le contrat du runner.

Durée globale réelle : 98,5476979 secondes sous PHP 8.2.33, Windows AMD64. Les
timestamps sont isolés dans `execution-metadata.json`. Les anciennes valeurs
`batchDurationSeconds` de ce run commencent après le premier candidat et ne
doivent pas être utilisées ; R2 corrige la commande pour les prochains runs,
sans réécrire silencieusement cette métadonnée historique.

## Correction de la contre-recette Astra

La contre-recette `docs/waar-micro-combat-t33-astra-review.md` a relevé une
réserve R1 et une remarque mineure R2. Les deux sont corrigées :

- la seed et les répétitions de recherche viennent maintenant de
  `experiment.json`, dont l'empreinte T31 est vérifiée ;
- `search-plan.json` doit confirmer ce sampling et l'appariement, au lieu de
  pouvoir le remplacer ;
- identifiant plan/résultat, empreintes expérience/objectifs/espace/initial,
  budgets et clé du cache témoin doivent concorder avant l'audit ;
- une incohérence provoque un échec avant écriture de `validation-plan.json` et
  avant toute simulation ;
- le runner émet `batch-start` avant le premier candidat et son témoin, quatre
  événements `candidate-complete`, puis `batch-complete`. Le chronomètre CLI
  suit exactement ces bornes.

La reproduction Astra avec seed déclarée `43` et expérience réelle `42` renvoie
désormais le code 1 avec `The T31 search plan sampling differs from the verified
experiment.` Aucun plan ni résultat n'est créé. Des tests distincts couvrent
aussi les répétitions divergentes, l'identifiant, une empreinte d'entrée et la
clé de cache.

## Rapport à ouvrir et parcours court

Ouvrir :

`C:\Users\trist\PhpstormProjects\waar-v3\var\waar-micro-combat\t33-finalist-stability\report.html`

1. Vérifier les cinq lots et le statut `Objectifs non atteints` du rang 1.
2. Choisir le rang 2, puis `Archer attaque Lancier` et l'axe structure.
3. Cocher le témoin neutre : le tableau affiche six lignes de diagnostic et
   aucune ellipse de structure.
4. Revenir aux survivants, choisir plusieurs objectifs et lire leurs étendues
   et le nombre de lots satisfaisants dans « Variation entre lots ».
5. Choisir « Retenir ce candidat » ou « Demander une nouvelle expérience » :
   le choix reste local jusqu'au téléchargement du JSON de décision.

Le navigateur reçoit les observations et les états déjà calculés. Il ne simule
rien, ne réévalue aucune ellipse et ne modifie aucune entrée.

## Recette

- 66 tests PHP, 9 909 assertions : séparation et correction déterministe des
  collisions, ordre figé, mutation rejetée, agrégation exacte, reproductibilité,
  comptage des combats, quatre statuts, présentation et correspondance aux entrées.
- Trois suites Node, dont le modèle T33 : changements candidat/scénario/axe,
  points superposés, 32 objectifs uniques, exports et absence de mutation.
- Rejeu isolé du lot `104729` à 1 000 répétitions : les huit fichiers rapport et
  évaluation des quatre variantes ont des SHA-256 identiques au lot officiel.
- Reconstruction indépendante des quatre agrégats depuis les cinq rapports :
  évaluations identiques et rapports numériquement identiques après réimport JSON.
- Chrome réel : rang 2, confrontation `archer-vs-spearman`, structure, témoin et
  décision locale ; 32 objectifs, six lignes attendues, aucune erreur console.
  Audit axe-core : zéro violation et zéro résultat incomplet.
- Lints PHP et contrôles de syntaxe des deux scripts JavaScript T33.

## Artefacts et empreintes

| Artefact | SHA-256 |
|---|---|
| `validation-plan.json` | `E878D8E4208DAE322726E52033D0C18192ADC2311D8C520321B5EE682E1449B9` |
| `result.json` | `0FC05D32CC28D3ADD12B5CD19D1DB069179C76AEE63C2328FDDA4328C28C50E8` |
| `presentation.json` | `F339932DA16E256D88321624CDA5D9DB6C7DDBCE94889BCAD1A0E529EF30CAD0` |
| `report.md` | `D4313E5629CDA618DB2B8AAD8D94524310E480C0AE683FA700644F3C8C9C703B` |
| `report.html` | `636F82D87860DBA34FFEB98A07A343B8C78C47CAC56DA932284D10D2AE74DB85` |
| `execution-metadata.json` | `24EE677977C739C1FF5700FB3BEB16603312A92E8C360C199E85096177EB4BDB` |

Le manifeste complet se trouve dans `artifact-sha256.json`. Les entrées copiées,
les rapports et évaluations de chaque lot, les agrégats et les 32 variations par
candidat sont sous `inputs/`, `batches/` et `aggregates/`.

## Limites et suite

Les statuts décrivent uniquement ce plan de cinq lots. Ils ne garantissent pas
tous les combats futurs et n'établissent pas l'impossibilité mathématique des
objectifs. Une recherche inspirée par T33 serait une expérience distincte avec
un nouveau plan réservé. La contre-recette corrective a accepté T33 ; T34 est
livrée dans `docs/waar-micro-combat-t34-relay.md`.
