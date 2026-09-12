# Comparaison candidat 116 / Legacy sur T24 — spécification pour Sol

Date : 10 septembre 2026. Statut : implémenté ; compléments PO ci-dessous intégrés.

## Demande et résultat attendu

Tristan souhaite comparer le candidat T31 116 à la référence Legacy en amplifiant visuellement les pertes Legacy, très compactées près de 100 % de survivants. Livrer un rapport HTML autonome, hors ligne, sur les six compositions T24 exactes, avec deux vues : pourcentages réels et Legacy dilaté ×20. La seconde est une comparaison descriptive de profils, pas une équivalence de pertes réelles.

Aucune nouvelle simulation ni recherche n’est nécessaire. Consommer les résultats archivés du candidat 116 sur T24 dans T34, et non ses 16 confrontations monotypes T31. Ne pas modifier de gameplay, objectifs PO, zones d’acceptance, classement, candidat ou référence figée. Ne pas introduire de score, ellipse, verdict de fidélité ni approbation.

## Sources et appariement

Chemins relatifs à ce dépôt autonome :

- Legacy : `experiments/references/t25a1/legacy-reference.json`.
- Mesures micro : `experiments/references/t34-mixed-composition-observation/result.json`.
- Plan : `experiments/references/t34-mixed-composition-observation/observation-plan.json`.
- Corpus : `experiments/references/t34-mixed-composition-observation/inputs/t24-corpus.json`.
- Paramètres micro : `experiments/references/t34-mixed-composition-observation/inputs/candidate-01.json`.
- Identité T31 : `experiments/references/t31-standard-seed-314159/finalists/01-t31-candidate-0116-d0c5f6473d05/variant.json`.

Sélectionner explicitement `t31-candidate-0116-d0c5f6473d05` dans `result.finalists` ; ne pas déduire l’identité de sa seule position. Apparier les lignes par `(scenarioId, side)` : six scénarios, deux camps, exactement douze paires uniques et complètes. Conserver l’ordre du corpus T24. Vérifier les compositions exactes et les coûts communs 80/110/130/350 ; conserver les budgets inégaux de T24. Rejeter doublons, manques, compositions divergentes et identité/empreinte de paramètres incohérente.

Vérifier les fichiers consommés contre `experiments/references/manifest.sha256`, le lien `result.planSha256` au plan T34, et les empreintes des copies consommées selon le manifeste T34. Comparer les paramètres à la référence T31 avec les services d’identité existants. Enregistrer les SHA-256 des entrées dans le nouvel artefact, sans réécrire les sources.

Le contrôle de provenance R1 de T34 a été corrigé séparément dans la PR #2 : voir `docs/waar-micro-combat-t34-handoff.md`. La comparaison ne vaut pas approbation du candidat. Une incohérence effective des sources consommées doit produire une erreur explicite, jamais une correction d’archive.

## Métriques et transformation

Toutes les valeurs de calcul sont des ratios ; arrondir seulement à l’affichage.

| Mesure | Legacy | Candidat 116 T34 |
| --- | --- | --- |
| X : victoire | `row.winRate.value` | `row.winRate` |
| Y : effectifs opérationnels restants | `row.metrics.operationalSurvivorsRatio.value` | `row.metrics.survivors` |
| Y : valeur économique opérationnelle restante | `row.metrics.operationalEconomicValueRatio.value` | `row.metrics.economicValue` |

Legacy compte les unités opérationnelles avant hôpital, excluant blessés et morts. Micro compte les survivants, unité partiellement endommagée comprise. Utiliser le barème commun, sans confondre survivants et valeur économique sur les compositions mixtes. La structure n’a pas d’équivalent Legacy : ne pas proposer cet axe dans cette comparaison.

Vue brute : `Y = 100 * s` pour les deux moteurs.
Vue dilatée : `Y_legacy = 100 * (1 - 20 * (1 - s_legacy))` ; `Y_116 = 100 * s_116`.
X vaut toujours `100 * winRate`, sans transformation ni lissage. Le facteur 20 est fixe et global pour tous les scénarios, camps et les deux métriques. Aucun ajustement aux extrema observés, aucune normalisation par scénario et aucun slider de facteur dans cette livraison.

La vue dilatée utilise un indice de présentation : ne pas étiqueter son axe « % de survivants réels ». Le candidat conserve sa valeur numérique brute. Présenter clairement « Legacy : pertes ×20 ; candidat 116 : valeur brute ».

### Domaine constaté et axes constants

L’hypothèse Legacy strictement dans ]95, 100] est approximative. Les archives donnent notamment :

- `soldier-control / attacker`, survivants : 0.985 → indice 70.
- `knights-against-archers / attacker`, survivants : 0.94975 → indice -0.5.
- `mixed-armies / defender`, survivants : 0.9477631578947369 → environ -4.473684.
- `mixed-armies / defender`, économie : 0.9306827731092437 → environ -38.634454.

Ne jamais borner ces indices à zéro, filtrer les lignes ou modifier le facteur pour les faire rentrer dans [0,100]. Un indice négatif est un résultat de la transformation, pas un effectif négatif.

Axes fixes : X [0,100] partout ; Y [0,100] en brut ; Y [-40,100] en dilaté. Ces bornes restent constantes lors de tout changement de scénario, camp ou métrique. Ligne zéro visible dans la vue dilatée. Vérifier les bornes contre toutes les données à la génération ; si de futures entrées sortent du domaine, refuser explicitement plutôt que masquer des points ou adapter silencieusement l’échelle.

## Rapport et parcours utilisateur

Titre : « Candidat 116 / Legacy — compositions T24 ».

- Bascule « Valeurs réelles » / « Legacy dilaté ×20 », vue dilatée par défaut dans la présentation finale.
- Choix de métrique : effectifs ou valeur économique opérationnels restants.
- Choix de scénario et camp, avec possibilité de voir l’ensemble des douze paires.
- Deux symboles distincts et légendés pour Legacy et 116 ; sélection mettant la paire correspondante en évidence. Réutiliser ECharts embarqué et les conventions visuelles du dépôt.
- Infobulle et tableau donnant scénario, camp, taux de victoire de chaque moteur, valeur Y réelle de chaque moteur, indice Legacy transformé et répétitions. Toujours rendre les valeurs brutes accessibles dans la vue dilatée.
- Expliquer brièvement la formule et le sens des indices négatifs. Ne pas présenter un écart normalisé comme un écart de pertes physiques.
- Mentionner Legacy : 200 répétitions, seed 42 ; micro T34 : 1 000 répétitions, seed 32452843. Pas d’appariement statistique inter-moteurs ni de test de significativité. Le vainqueur Legacy est déterministe à composition/contexte fixes ; son X 0 ou 100 est normal.
- Provenance consultable, statut exploratoire du candidat et maintien du constat T33 à 0/32. Cette comparaison ne constitue pas une approbation.
- Fonctionnement direct en `file://`, sans CDN ni serveur. Bureau et largeur 390 px lisibles, commandes accessibles au clavier.

## Implémentation et artefacts attendus

Ajouter une commande dédiée, par exemple :

```bash
php bin/render-candidate-legacy-comparison.php
```

Sortie par défaut : `reports/t31-candidate-0116-legacy-comparison/`, absente ou vide. Autoriser un autre répertoire explicite ; ne jamais écraser une sortie non vide.

Un service PHP pur contrôle les entrées, construit les douze paires et calcule les deux jeux de coordonnées. Un renderer intègre données, ECharts et scripts descriptifs. JavaScript ne résout aucun combat, ne choisit aucun candidat, ne recalcule aucun objectif et ne décide d’aucune approbation ; il sélectionne les coordonnées déjà fournies pour la vue choisie.

Livrer `report.html`, `comparison.json` (schéma distinct et versionné, valeurs brutes et transformées, identités, provenance, facteur, bornes, sampling), `report.md` (méthode et limites), et un manifeste SHA-256 des entrées consommées. Ajouter la commande et le lien vers le rapport dans le README. Aucun besoin de copier ou modifier les objectifs de la carte 116.

## Recette

Tests PHP ciblés : sélection par identifiant, douze paires exactes, compositions/barème, intégrité/provenance, rejet des doublons/manques et entrées invalides, fidélité des coordonnées brutes, formule aux points 1→100, .99→80, .975→50, .95→0 et aux valeurs négatives ci-dessus. Vérifier que X et le candidat restent inchangés, que les deux métriques sont distinctes, que les bornes sont fixes, qu’aucun résultat n’est tronqué et que les sources n’ont pas été modifiées. Couvrir sortie non vide et échec avant émission d’un rapport présenté comme complet.

Tests JavaScript : bascule entre coordonnées préconstruites, sélection scénario/camp/métrique, affichage des valeurs brutes et négatives, conservation des bornes. Vérification navigateur en ouverture locale, bureau et 390 px, sans erreur console ni point masqué. Vérifier visuellement les douze paires et notamment les cas mixtes sous zéro.

Exécuter les contrôles prescrits par AGENTS.md : `composer validate --strict`, `composer install`, `composer test`, `composer test:js`, `composer smoke`, puis `php bin/render-finalist-comparison.php` (utiliser son argument de sortie vers un nouveau dossier si la sortie habituelle est occupée). Ne lancer ni recherche T31 ni validation T33 complète ni observation T34.

Le relais de livraison doit fournir les chemins, preuves de recette et limites ; aucune clôture implicite de T34 ou modification des règles d’acceptation.

## Précision après lecture du moteur historique

Le chemin présent dans ce checkout est `../waar-sf/src/Services/CombatService.php` (pas `CombatBattle.php`). `calculateLossesRatio`, lignes 309–325, plafonne le ratio théorique à `0.07 / 1.7`, soit environ 4.117647 % avant arrondi et éventuel multiplicateur défenseur. `calculateUnitLoss`, lignes 328–331, tire `rand(floor(x*1000), ceil(x*1000))`, puis choisit `ceil(x)` ou `floor(x)`.

Lorsque `x*1000` est non entier, les deux entiers du tirage sont équiprobables : l’arrondi supérieur a une probabilité de 50 %, indépendamment de la partie fractionnaire de x. Lorsque `x*1000` est entier, le résultat est `floor(x)`. Ce mécanisme peut biaiser fortement les pertes des petites cohortes ; augmenter le nombre de répétitions ne supprime pas ce biais. Il ne s’agit pas d’un simple arrondi d’affichage.

Exemple observable dans la référence : `mixed-armies / defender` comporte 4 chevaliers. Sur 200 combats, 99 chevaliers sont perdus, soit 0.495 par combat et 12.375 % de cette cohorte. Toutes unités confondues, 397 pertes sur 7 600 unités initiales donnent 5.223684 % de pertes ; pondérées au barème commun, elles donnent 6.931723 %. Cela explique les passages sous 95 % malgré le plafond du ratio théorique.

Le `LegacyAggregateCombatResolver` de waar-v3 reproduit ce mécanisme et son SHA-256 actuel correspond à celui enregistré dans la référence (`b4e0387a7649fd79950013511509004c02e116527ca0a6b4ec17594ac164e9d8`). La visualisation demandée reste fondée sur les observations archivées, avec indices négatifs conservés. Expliquer leur origine dans le rapport. Une comparaison des taux théoriques avant arrondi serait une autre mesure, à définir explicitement ; ne pas remplacer les observations par ces taux ni corriger le moteur ou les archives dans cette livraison.

## Complément PO — deltas et conformité globale

Le PO demande ensuite les deltas ligne par ligne et trois scores globaux de conformité, en conservant le Legacy dilaté ×20 pour les effectifs. Cette demande remplace l’exclusion initiale de scores descriptifs dans cette spec ; elle n’autorise ni classement de candidats ni modification des objectifs PO.

- Delta victoire : candidat moins Legacy, en points de pourcentage.
- Delta effectifs : candidat moins Legacy dilaté ×20, en points d’indice, y compris lorsque la vue brute est sélectionnée. Les infobulles et le tableau explicitent la référence.
- Victoire attribuée : pourcentage des six scénarios où le même camp dépasse 50 % de victoire. Une absence de majorité est comptée séparément et ne compte pas comme accord.
- Conformité du taux de victoire : max(0, 100 − moyenne des écarts absolus en points), sur les douze lignes, à poids égaux.
- Conformité des effectifs : même formule contre le Legacy dilaté, sur les douze lignes. Le score est borné inférieurement à zéro ; les coordonnées et les deltas restent non tronqués.
- Les scores globaux portent toujours sur le corpus complet, indépendamment des filtres et de la métrique graphique. Aucun score composite ni seuil d’acceptation.
- Note visible sur le coinflip Legacy, qui peut induire des pertes supérieures à 5 % sur de petites cohortes, y compris en moyenne.

La commande `bin/render-candidate-legacy-comparison.php` produit le rapport et les scores. Les tests PHP et JavaScript couvrent les données et interactions descriptives ; la recette visuelle porte sur les rapports générés en ouverture locale, au bureau et à 390 px.
