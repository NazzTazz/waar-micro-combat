# Campagne Waar — décision sur le cœur du moteur

> État de mesure ultérieur : le bloc de référence/D1/D2, D3–D5 et trois confirmations sont terminés. La [lecture intermédiaire](../../reports/campagne-coeur/ANALYSE-DIAGNOSTICS.md) documente le défaut de libellé des pertes `B-A`, désormais corrigé et réexporté sans combat. Les passages ci-dessous décrivant « aucun combat » sont historiques et datent de la préparation.

> Mise à jour locale du 23 septembre 2026 : la préparation et les comptes CLI vérifiés sont dans [le bilan de préparation](../../reports/campagne-coeur/preparation/PREPARATION.md). Les estimations « avant déduplication » du dossier d’origine ci-dessous restent historiques. Les 159 plans du ZIP sont acceptés après correction de configuration ; trois diagnostics D3/D4/D5 et 27 segments sont ajoutés. Aucun combat de cette campagne n’a été lancé.

Configuration du 23 septembre 2026. Objectif : expliquer les comportements actuels et identifier les détails du cœur qui méritent éventuellement une modification. La campagne ne recherche pas un profil optimal. Elle prépare aussi les données du futur manuel.

## Contenu et statut

- `plan-reference.json` : plan au format documenté du CLI, 30 confrontations monotypes, deux sens, 2 000 répétitions par sens, soit **120 000 combats prévus**. Prévisualisation CLI vérifiée ; aucune exécution.
- 159 plans JSON : référence, petits miroirs, défense × rounds, variations simples, météos, écrans et compositions.
- `campaign-design.json` : spécification générale, notamment des interactions et raffinements différés. Ce fichier n’est pas lui-même un plan accepté par le CLI.
- `reference-profile.json` : copie exacte de l’export fourni.
- `generate_plans.py` : génération reproductible des plans avec Python 3, bibliothèque standard uniquement ; ne lance aucun combat.
- `campaign-manifest.json`, `preview-estimate.csv`, `coverage-planned.csv` : inventaire des plans, comptes théoriques avant déduplication et rattachement des axes. Ce ne sont pas des sorties de prévisualisation du CLI.
- `preview-all.ps1` : vérifie l’empreinte du profil authentique sous `reports/campaign-manual-sol/`, prévisualise les plans et reconstruit les rapports ; aucun appel run/resume.
- Ce README : ordre d'exécution, témoins, diagnostics, analyse et consigne à Sol.

Le profil fourni est **Nazz-Eq-20%-Rc2**, id `saved-fbecd00cc0d0dcb6a60e614e`, SHA-256 `4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`. Les valeurs relatives sont résolues depuis cet export, sans reconstruction. Lors de la fabrication du ZIP, le worktree Windows et le CLI n’étaient pas accessibles à Astra ; la validation locale figure dans le bilan de préparation lié en tête de fichier. Les plans utilisent `repetitions=2000`, `batchSize=100` et l’entrée locale `campaignBatch`. Aucun combat n'a été lancé pour préparer ce dossier.

Estimation d’origine du ZIP : **12 023 configurations avant déduplication, 48 092 000 combats prévus**, hors diagnostics supplémentaires, interactions sélectionnées, raffinement et confirmation. Le total CLI corrigé et les segments actifs sont dans le bilan de préparation ; cette estimation n’est pas une consigne de lancement.

| Bloc | Combats avant déduplication |
|---|---:|
| Référence monotypes | 120 000 |
| Petits miroirs D1 | 80 000 |
| Défense × rounds D2 | 420 000 |
| Variations simples hors météo | 15 864 000 |
| Météos et cellules de facteurs | 26 640 000 |
| Écrans | 2 448 000 |
| Compositions et coupe archers | 2 520 000 |

**Premier lot de travail : référence + D1 + D2 = 620 000 combats**, puis lecture des résultats. La météo complète reste un bloc ultérieur ; elle représente toutes les cellules, y compris actuellement neutres. Les requêtes équivalentes doivent être dédupliquées avant son exécution, sans perdre la matrice de couverture.

Les fichiers sont extraits dans `experiments/campagne-coeur/`. Les plans pointent vers l’export authentique `reports/campaign-manual-sol/profile.json` ; aucune copie à la racine n’est nécessaire.

```powershell
php bin/parametric-campaign.php preview experiments/campagne-coeur/plan-reference.json
# Quand la campagne est prête à être exécutée :
php bin/parametric-campaign.php run experiments/campagne-coeur/plan-reference.json
php bin/parametric-campaign.php resume experiments/campagne-coeur/plan-reference.json
php bin/parametric-campaign.php export experiments/campagne-coeur/plan-reference.json
```

## Règles communes

1. Source unique : l'export authentique `reports/campaign-manual-sol/profile.json`, avec seuil de blessure de 20 %. Vérifier cette valeur ; ne pas la corriger silencieusement. Archiver les octets et le SHA, ainsi que versions moteur/RNG, binaire réellement employé et provenance déjà fournie par l'explorateur.
2. Chaque cas repart de la référence. Tout contexte diagnostique modifié, notamment un round, possède son propre témoin. Un changement de contexte n'est jamais comparé directement à la référence générale pour attribuer un effet à un seul paramètre.
3. Ajouter systématiquement la valeur exacte de référence à chaque grille. Valider les valeurs contre le code actuel, dédupliquer après normalisation. Documenter les valeurs impossibles ; ne pas les borner ou arrondir silencieusement.
4. 2 000 répétitions **par configuration et par sens**, lots techniques de 100. Même `baseSeed=42`, `seedKey=0`, indices globaux et identités A/B entre variantes comparables. Ne pas remplacer la génération des graines.
5. Budgets nominaux 12 000, 120 000, 360 000. Effectifs arrondis à l'entier inférieur, reliquat non dépensé. Conserver budget nominal, dépense réelle, effectifs et reliquat. Aucun soldat ajouté automatiquement dans un monotype.
6. Les paramètres d'un profil s'appliquent aux deux camps. Dans un miroir, modifier l'attaque d'un type modifie donc les deux armées : ce test ne mesure pas un avantage unilatéral. Pour mesurer la résistance de la cible indépendamment, utiliser des types différents ; tout effet unilatéral doit employer un mécanisme réellement disponible et être identifié comme tel.
7. Pour les variations de coût, créer deux familles : effectifs de référence figés ; budget nominal conservé et effectifs recalculés. Le coût peut modifier un départage économique même à effectifs constants. Ne pas présenter cet effet comme une modification des dégâts.
8. Tous les paramètres réellement exposés doivent figurer dans une matrice de couverture : chemin, définition, unité, domaine, référence, étape du calcul, cas associés, effet déclenché ou non. Inclure les contrôles inconnus du présent document. Les grilles proposées ne remplacent pas cet inventaire.

## Ordre des blocs

### D — diagnostics prioritaires issus des testeurs

À analyser avant de lancer une grande exploration d'interactions.

**D1 — symétrie et faible effectif.** Miroirs des quatre types avec 1, 5, 10, 50 et 100 unités par camp, référence inchangée. Deux sens. Ces 20 confrontations représentent 80 000 combats avant déduplication avec d'autres blocs. Distinguer l'identité A/B du rôle attaquant/défenseur. Une préférence pour l'attaquant n'explique pas à elle seule une différence persistante entre les deux sens de deux armées identiques. Les deux séries ne sont pas nécessairement des copies miroir à graines fixées.

**D2 — défense et touches.** Pour chaque type : miroir à 100 unités, témoin du profil fourni, puis coefficient défensif 0,5 / 0,75 / 1 / 1,25 / 1,5 / 1,75 / 2. Répéter dans des contextes à 1 round, 2 rounds et limite de référence. Étudier séparément victoire, durée et pertes physiques. Inclure les 5 archers par camp à 1 round et au nombre de rounds de référence. Il s'agit de confronter l'hypothèse de Darth aux sorties, pas de supposer son explication correcte.

**D3 — seuils physiques.** Choisir par lecture du calcul actuel quelques rapports dégâts par frappe / structure qui encadrent les seuils de blessure et de mort. Petits effectifs ; précision à 100 %, variation à zéro, météo neutre, contexte de défense explicite. Encadrer le seuil exact avec le pas minimal réellement représentable. Ne pas réutiliser automatiquement l'ancien cas « 70/250 » : vérifier les dégâts effectifs après défense, frappes et contres. Sauvegarder les traces existantes d'au moins un cas avant/au/après seuil. Pour vérifier que les blessés continuent à combattre, utiliser également un cas sur plusieurs rounds où une cohorte endommagée frappe encore.

**D4 — règle de victoire.** Rejouer les cas litigieux à 1 round avec chaque critère de départage et chaque politique d'égalité effectivement disponibles. Construire une égalité exacte contrôlée. Les limites de rounds et les règles d'issue doivent être explicites ; ne pas déduire les dégâts du seul vainqueur.

**D5 — précision.** Comparer précision et dispersion séparément, puis ensemble, à faible et grand effectif. Vérifier la définition de `accuracySpread`. Le mot « variation » dans l'interface ne suffit pas à l'identifier comme une dispersion des dégâts. Une probabilité fixe n'implique pas un nombre déterministe de touches. Une représentation en cohortes n'interdit pas une loi binomiale sur le nombre de touches.

D1/D2 d'abord à 2 000 répétitions. Confirmer les asymétries ou inversions importantes sur 8 000 répétitions avec `baseSeed=10000042`, dans un plan et une sortie séparés. Cette plage est distincte de la plage initiale pour le protocole à `seedKey=0` décrit par le générateur. Vérifier la règle actuelle avant de l'employer. Ne pas additionner deux séries qui se recouvrent. Cette confirmation ne garantit pas à elle seule l'absence de biais du moteur.

### M — monotypes : cartographie principale

Les 10 paires non ordonnées avec répétition des quatre types, aux trois budgets : six confrontations de types différents et quatre miroirs. Les deux sens sont conservés pour chacune.

Pour chaque champ de chaque unité, appliquer la grille de `campaign-design.json` aux quatre confrontations contenant cette unité, aux trois budgets. Ne pas envoyer les variations d'un type absent dans tous les autres scénarios. À effectifs fixés par le profil de référence, les caractéristiques physiques ne changent pas les armées ; le coût a ses deux familles dédiées.

Couvrir les contres directionnels sur la confrontation concernée : les douze couples de types distincts, y compris ceux dont le facteur de référence est neutre. Inventorier aussi les éventuelles relations d'un type contre lui-même si elles sont exposées. Une variation du contre A→B ne remplace pas le test B→A.

Balayer les paramètres de combat sur les monotypes. Lorsque le phénomène est absent — capture impossible, aucune reddition, seuil jamais traversé — ajouter un scénario ciblé qui le déclenche. Indiquer « non déclenché dans ce contexte », pas « sans effet ».

Toutes les météos nommées du profil sont évaluées, côté A seul, B seul et des deux côtés. Fusionner les requêtes réellement équivalentes en gardant leurs alias. Tester séparément les facteurs d'attaque et de précision sur le type concerné, avec météo active. Un paramètre météo inactif ne constitue pas une expérience utile.

### I — interactions sélectionnées

Les couples et triplets figurent dans le JSON. Ils doivent conserver leurs témoins et effets simples ; les triplets conservent aussi leurs couples.

Ne pas croiser toute la grille fine de tous les axes avec tous les scénarios. Premier passage : référence et deux niveaux discriminants par axe, choisis dans la cartographie simple ; ensemble complet de ces trois niveaux pour chaque interaction retenue. Utiliser un scénario sensible et un témoin par mécanisme, puis vérifier les trois budgets quand l'effet dépend de l'échelle. Les triplets contenant la part de soldats sont exécutés au bloc E.

`target.structure` désigne la structure de l'unité cible réellement présente, pas un chemin littéral du CLI. Pour un miroir, attaque et structure du type s'appliquent aux deux camps : le libellé de l'expérience doit l'indiquer.

Si aucun point discriminant n'existe, ne pas inventer une bascule : documenter le plateau et choisir les extrêmes valides pour contrôler l'interaction. Aucun changement du moteur pour cette exploration. Les valeurs de couples/triplets sont différées volontairement ; les plans simples, écrans et mixtes sont déjà résolus.

### E — écran de soldats

Un spécialiste à la fois : lancier, archer, chevalier. Trois budgets de départ ; chacun des quatre monotypes comme adversaire fixe.

Deux familles distinctes :

- **Remplacement à budget constant** : part du budget des soldats 0 / 5 / 10 / 20 / 35 / 50 / 65 / 80 / 90 / 100 %. Le reste achète le spécialiste.
- **Ajout** : effectif du spécialiste fixé par le budget de départ, puis achat de soldats pour 0 / 5 / 10 / 20 / 35 / 50 / 100 % de ce budget. Le coût total augmente et doit être affiché.

Cela représente 612 confrontations nominales, soit 2 448 000 combats avant déduplication. Les extrémités répètent des monotypes ; garder leur rattachement aux courbes mais réutiliser les résultats si requêtes et graines sont identiques.

Autour d'un effet d'écran : croiser part de soldats, reddition et limite de rounds. Mesurer si l'écran protège effectivement les spécialistes, provoque une reddition plus précoce ou change le départage. Ne pas affirmer que les soldats sont ciblés en premier sans vérifier le mécanisme de ciblage actuel.

### X — compositions mixtes

Grille de parts budgétaires par pas de 25 % sur les quatre types, somme 100 % : 35 compositions, frontières comprises. Aux trois budgets, chacune affronte les quatre monotypes et une composition fixe à 25 % de budget par type. Soit 525 confrontations et 2 100 000 combats avant déduplication, deux sens compris. Les frontières déjà connues servent de contrôles ; les nouvelles expériences sont les compositions restantes. Pas de tournoi toutes compositions contre toutes compositions au premier passage.

Ajouter une coupe lisible pour les archers : 20 % du budget en lanciers, 20 % en chevaliers ; les 60 % restants sont partagés entre soldats et archers. Part archers 0 / 10 / 20 / 30 / 40 / 50 / 60 %, remplaçant des soldats, contre les mêmes adversaires fixes. Conserver les effectifs et reliquats exacts. Ne pas ajuster une gaussienne par défaut.

## Raffinement et confirmation

Déclencheurs de repérage, pas tests de significativité : entre points voisins, 10 points de pourcentage de victoire, 5 points de pertes normalisées, 20 % de durée moyenne ; tout extremum, renversement de classement, cas rapporté par les testeurs ou seuil prédit par le code.

Ajouter des points intermédiaires, au plus quatre par intervalle et par passage, compatibles avec la précision du moteur. Regarder aussi les voisins immédiats d'une bascule afin de ne pas supposer la monotonie. Arrêter quand le pas moteur est atteint ou quand le comportement est suffisamment décrit. Les variations plus petites peuvent rester pertinentes pour le gameplay : les seuils de repérage ne sont pas des seuils d'importance universels.

Confirmer les conclusions qui pourraient motiver une modification du cœur avec la plage distincte de 8 000 répétitions. Pour prolonger une expérience, ne pas éditer un plan déjà commencé : le système de reprise refuse justement cette modification. Créer un nouveau plan et documenter ses graines.

## Analyse et limites des sorties

Conserver les lots et leurs réponses brutes. Exporter par cas/sens/type/camp : effectifs, budgets, victoire/nul/défaite, rounds moyens, morts et blessés physiques disponibles, morts/blessés/prisonniers projetés, pertes normalisées et indicateur économique. La documentation fournie définit ce dernier comme la valeur des morts plus blessés projetés au coût du profil, divisée par la valeur initiale ; prisonniers exclus. Vérifier que cela correspond encore à la sortie actuelle.

Séparer explicitement : fait établi par lecture du code ; observation mesurée ; hypothèse explicative ; préférence de gameplay. Une règle cohérente peut produire un résultat indésirable sans bug, et une moyenne proche de l'attendu n'établit pas à elle seule la correction de toutes les règles.

Le batch documenté expose des agrégats, pas les observations individuelles. Ne pas fabriquer quantiles, histogrammes individuels, structure restante, écart-type des pertes ou différences appariées. L'utilisation des mêmes graines ne rend pas ces dernières calculables à partir des seuls totaux. Un intervalle de Wilson marginal sur un taux de victoire peut être calculé depuis les comptes, en explicitant l'hypothèse d'échantillonnage ; il ne constitue pas un test apparié des variantes. Les indicateurs analysés sur des milliers de points ne doivent pas servir à déclarer mécaniquement des anomalies statistiques.

Pour chaque bloc : courbes victoire/durée/pertes, cas d'inversion, plateaux, seuils, hypothèses réfutées ou encore ouvertes. Une victoire peut coûter cher ; les pertes après compression ne remplacent pas les pertes physiques pour expliquer le combat. Les paramètres purement post-combat doivent être analysés à leur stade réel, sans leur attribuer une influence sur les frappes.

## Consigne historique transmise à Sol

Configurer cette campagne dans le worktree existant à partir de `profile.json` et de l'explorateur livré. Lire les instructions du dépôt et respecter les changements déjà présents.

Travail demandé maintenant : **préparer et prévisualiser tous les plans ; ne pas lancer les combats**.

1. Valider `plan-reference.json` avec le CLI actuel ; corriger uniquement la configuration si nécessaire. Ne pas reconstruire le profil. Ne modifier ni moteur, RNG, runtime, contrats web, binaire, profils enregistrés ni déploiement.
2. Inventorier les paramètres réels et vérifier les 159 plans fournis contre le CLI actuel. Compléter les contextes diagnostiques D3/D4/D5 et les éléments différés de `campaign-design.json`, en plans distincts D/M/I/E/X. Pour les interactions qui dépendent des premiers résultats, livrer leur règle de sélection et leur gabarit clairement marqué comme différé ; ne pas prétendre les avoir entièrement résolues.
3. Calculer les valeurs relatives depuis l'export authentique, valider types et domaines, inclure les références exactes. Fournir une liste des écarts entre cette spécification et les possibilités effectives.
4. Faire une prévisualisation des plans résolus sans combat. Produire `coverage.csv`, `campaign-manifest.json` et `preview-summary.csv` : plans, configurations effectives, directions, répétitions, combats, mécanismes couverts et dépendances. Dédupliquer les requêtes strictement équivalentes avec leurs alias, ou signaler les doublons résiduels si l'outil ne déduplique qu'au sein d'un plan.
5. Découper les gros blocs en plans de taille raisonnable, par famille et par budget, avec plafond exact `maxCombats` calculé avant exécution. Ne pas donner un plafond arbitrairement immense. Ne pas lancer automatiquement tous les blocs après le premier : analyse intermédiaire avant interactions et généralisation.
6. Fournir les commandes PowerShell preview/run/resume/export, dans l'ordre, et un script de préparation reproductible si nécessaire. Ce script peut générer des configurations mais ne doit pas dupliquer le calcul de combat ni contourner le plafond web.
7. Ne pas réouvrir une campagne générale de tests de parité pour cette tâche de configuration. Si un comportement concret bloque la préparation, le décrire ; ne pas entreprendre une refonte du socle.
8. Conclure avec les comptes exacts prévisualisés et les parties encore dépendantes des mesures. Aucune estimation de temps fondée sur le petit benchmark de 616 combats/s : elle n'est pas représentative des grandes armées, météos et nombres de rounds de cette campagne.

Le livrable final de configuration doit permettre de lancer D et M, puis de décider à partir de leurs données quels détails du cœur examiner avant de poursuivre E et X.

## Vérifications réalisées ici

Lecture du profil authentique ; contrôle du SHA ; JSON de tous les plans analysés ; recomptage des scénarios, valeurs et plafonds ; contrôle des parts budgétaires et de leur somme ; contrôle des effectifs explicites non négatifs ; vérification de la reproductibilité du générateur. Les valeurs de départage `economic`/`structure` et d’égalité `defender`/`draw` sont acceptées par le fichier Rust fourni, sans garantie que cette copie soit le dernier code du worktree. La validation de tous les plans par le CLI actuel reste à effectuer.

Dans les plans de facteurs météo, A reçoit la météo étudiée. B reçoit `neutral`, sauf lorsque la cellule étudiée appartient à `neutral` : B reçoit alors `cloudy`, numériquement identique dans cet export. Cela permet de varier une cellule de `neutral` sans modifier simultanément le même type chez B. Les contextes et témoins sont conservés dans le plan.
