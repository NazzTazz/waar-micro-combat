# Relais T34 — observation des compositions mixtes T24

9 septembre 2026 — état à la livraison : prête pour contre-recette Astra.

Mise à jour du point de reprise : la [contre-recette légère Astra](waar-micro-combat-t34-astra-review.md)
a depuis reproduit les mesures et posé la réserve R1 de provenance avant clôture.
Voir le [handoff actualisé](waar-micro-combat-t34-handoff.md). Les commandes et
chemins ci-dessous restent ceux de la livraison dans Waar v3.

## Décision d’entrée

Tristan a accepté T33 sans réserve et autorisé T34. Les quatre variantes conservent
leur statut T33 `objectifs-non-atteints` : l’observation mixte ne transforme pas
l’échec des objectifs monotypes en acceptation. Leur ordre T31 et leurs paramètres
sont figés.

## Commande et manifeste préalable

Depuis la racine du dépôt, vers une sortie absente ou vide :

```powershell
php packages/waar-micro-combat/bin/observe-mixed-compositions.php
```

La commande lit le corpus accepté
`packages/waar-micro-combat/experiments/t24-astra-vector-corrections.json` et la
sortie T33, vérifie leurs empreintes, puis copie le corpus, l’initial et les trois
finalistes sous `inputs/`. Elle écrit `observation-plan.json` avant la première
simulation. Son SHA-256 officiel est
`08D7AD8FC1CAC621A59700DE9FF9958E967B340C0D13AE29E18C3766FE41140A`.

Le plan contient les six identifiants, compositions et budgets T24 exacts. Il
conserve les budgets inégaux ; il n’utilise aucun générateur monotype. Toutes les
variantes emploient les coûts `80/110/130/350`, trois rounds, la dispersion `0.1`
et le départage `defender`. L’initial T28 et les finalistes partagent les mêmes
1 000 seeds dérivées par scénario depuis la seed réservée `32452843`.

## Mesures

Le runner calcule l’initial une fois puis le réutilise comme référence exacte pour
les trois comparaisons :

- initial : 6 000 combats ;
- trois finalistes : 18 000 combats ;
- total réel : 24 000 combats ;
- coût logique des trois comparaisons initial/finaliste : 36 000 combats ;
- durée officielle : 11,3742349 secondes sous PHP 8.2.33, Windows AMD64.

Chaque ligne publie victoires, défaites, nuls, taux de victoire, moyenne des
rounds, ratios de survivants, structure et valeur économique. Les agrégats exacts
des survivants et pertes sont aussi fournis pour Soldat, Lancier, Archer et
Chevalier, avec leur moyenne par combat. L’identité
`effectif initial = survivants + pertes` est vérifiée pour chaque type.

Sur les armées mixtes, le ratio d’effectifs et la valeur économique sont calculés
séparément depuis les résultats et divergent effectivement. Aucun alias monotype
n’est repris.

## Observations descriptives

Dix renversements de vainqueur majoritaire apparaissent par rapport à l’initial :
trois pour le rang T31 1, quatre pour le rang 2 et trois pour le rang 3. Les trois
finalistes renversent vers l’attaquant `spearman-screen`,
`spears-against-knights` et `archers-against-spears`. Le rang 2 renverse aussi
`soldier-control`.

Les plus grands écarts absolus du corpus sont portés par le rang 1 :

| Mesure | Scénario / camp | Initial | Finaliste | Delta |
|---|---|---:|---:|---:|
| victoire | `spears-against-knights` / attaquant | 0,225000 | 1,000000 | +0,775000 |
| survivants | `archers-against-spears` / attaquant | 0,043176 | 0,745735 | +0,702559 |
| structure | `archers-against-spears` / attaquant | 0,042638 | 0,718268 | +0,675630 |
| économie | `archers-against-spears` / attaquant | 0,033348 | 0,735366 | +0,702018 |
| rounds | `archers-against-spears` / attaquant | 3,000000 | 1,677000 | −1,323000 |

Ces constats n’introduisent aucun seuil, score T24, ellipse, verdict de fidélité
Legacy, classement ou sélection. Le corpus et ses résultats ne sont lus par
aucun chemin de score T31.

La référence historique T24 utilisait 200 répétitions, la seed `42` et le
départage `draw` implicite. Ses mesures restent intactes et ne sont pas tracées
dans T34 ; aucune différence de politique n’est attribuée aux paramètres.

## Rapport à ouvrir et parcours court

Ouvrir :

`C:\Users\trist\PhpstormProjects\waar-v3\var\waar-micro-combat\t34-mixed-composition-observation\report.html`

1. Choisir successivement les trois finalistes et vérifier leur ordre T31 et leur
   statut T33.
2. Passer de `Soldats seuls` à `Armées mixtes`, puis changer l’axe entre effectifs,
   structure et économie.
3. Lire les deux flèches attaquant/défenseur, orientées de l’initial vers le
   finaliste, et le message explicite lorsqu’une trajectoire est superposée.
4. Contrôler les victoires/défaites/nuls, puis les survivants et pertes des quatre
   types dans les tableaux.
5. Exporter JSON puis CSV ; les quatre lignes contiennent les valeurs mesurées de
   l’initial et du finaliste pour les deux camps.

Le navigateur ne simule rien, ne modifie aucun candidat et n’infère aucune
acceptation.

## Recette automatisée et artefacts

- quatre tests T34, 499 assertions ; conservation du corpus et des budgets,
  mutation rejetée, camps distincts, comptage des combats, métriques mixtes,
  pertes par type, observations et rendu autonome ;
- tests du runner commun : 6 tests, 141 assertions ;
- modèle Node T34 : sélection candidat/scénario/axe, quatre points distincts,
  superpositions explicites, exports mesurés et absence de mutation ;
- suite complète : 70 tests PHP / 10 408 assertions ; quatre suites Node et 62
  lints PHP réussis ; syntaxe JavaScript et `git diff --check` contrôlés ;
- navigateur Chrome réel : changement finaliste/scénario/axe sur les artefacts
  officiels, cas superposé explicite, valeurs détaillées lisibles, trois compteurs
  d'interdiction à zéro et aucune erreur console.

| Artefact | SHA-256 |
|---|---|
| `observation-plan.json` | `08D7AD8FC1CAC621A59700DE9FF9958E967B340C0D13AE29E18C3766FE41140A` |
| `result.json` | `A01C70A0D6EF762965D3EF39C5360B96EE3C3FB4FA49877EAC33517BC62C4BCD` |
| `presentation.json` | `FA80B4AB5B34453933A33FAC731D793F60554FC1A394FD3BC2456F5874AF9FE8` |
| `report.md` | `297F892D7F84AA0FE43580A40C834EF7A9BBBD1E02A00E3E5EEB050CFF0E915E` |
| `report.html` | `1DC9646378F3AC65788AC4687EB9C84A3C0773AE900C02D2DC22119CA3310237` |
| `execution-metadata.json` | `157CB43B0E89F435699109143C7395F392B97C775C7B6CA026E9BDA5B799D087` |

Le manifeste complet est `artifact-sha256.json`. La sortie officielle est
`var/waar-micro-combat/t34-mixed-composition-observation/`.

## Décision restante

T34 termine le programme T30–T34 sans candidat strict : initial et finalistes
restent à 0/32 en T33. Le PO peut retenir une variante pour ses effets de gameplay
observés ou demander une nouvelle expérience. Toute nouvelle recherche, intégration
Legacy ou activation versionnée constitue une tranche distincte à spécifier.
