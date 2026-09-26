# Campagne optimisée : utilité et coût des paramètres

26 septembre 2026. Analyse descriptive de la campagne exécutée sur le VPS de
Strasbourg. Les 79 manifestes et exports ont été audités : 22 544 000 combats,
zéro erreur, binaire Rust SHA-256
`6474fe3428ba698f53fdf90ba26471f62385978c749b24daf599fbd3aecd0f1b`.
Les deux camps partagent chaque preset météo et les combats sont limités à 20
rounds. Chaque ligne d'export agrège 2 000 réalisations. Les observations et
calculs ci-dessous viennent des CSV et du champ `elapsedSeconds` des lots, via
[`ops/campaign/analyze-optimized-vps.py`](../../ops/campaign/analyze-optimized-vps.py).

## Conclusion exploitable

Une variation de sortie n'est pas, en soi, une justification de gameplay. Le
tri utile est : **la règle change-t-elle la trajectoire du combat ?** et
**combien coûte-t-elle à évaluer ?** La campagne établit un surcoût net de la
fragmentation, et un coût de durée pour les rounds et la reddition. Elle ne
sépare pas le coût propre des projections après combat. Treize plans les ont
recalculées en rejouant 4 104 000 combats complets.

Les 13 plans de **post-traitement ou de classification** totalisent 2 281 s de
temps de simulation, soit 14,7 % du temps cumulé de la campagne : seuil de
blessure, capture, compression, `capturable`, coût à effectifs constants,
critère de départage et politique d'égalité. Leurs variations ne changent
ni les tirs ni l'état terminal brut ; elles peuvent être appliquées aux mêmes
états terminaux, avec les mêmes identités et graines de conséquence. À
l'intérieur de chaque plan, les 4 104 000 réalisations ne couvrent
**qu'au plus 984 000 trajectoires physiques distinctes** : **3 120 000 résolutions de
combat pourraient être évitées** par une évaluation multi-règles après
chaque résolution. Le calcul des conséquences reste nécessaire. Le moteur
peut faire ces projections pendant que l'état terminal est en mémoire, sans
stocker des millions de traces. Les plans de coût à budget fixe changent les
effectifs et ne figurent pas dans ce total.

## Ce que mesure le débit

`elapsedSeconds` encadre l'appel PHP → Rust et la vérification de la réponse ;
il exclut l'écriture du lot et du manifeste. La somme vaut 15 565 secondes de
temps de simulation sur les deux slots, soit 1 448 combats/s par seconde de
slot occupé. Le débit mural de la campagne entière est proche de 2 600
combats/s avec deux slots de 1 vCPU. Ces chiffres incluent les changements de
nombre de rounds et de taille des cohortes provoqués par les paramètres : ce
ne sont pas des microbenchmarks du coût d'une instruction isolée.

Dans les plans de composition `X`, en classant chaque lot d'après le nombre de
types présents dans ses armées initiales, les **deux armées mixtes** totalisent
456 000 combats en 811,152 s, soit **562 combats/s par slot**. Le détail est
589/s dans `X-simplex` et 469/s dans `X-archer-cut`. Si « armées mixtes »
signifie **au moins un camp mixte**, les plans `X` totalisent 2 328 000 combats
en 2 662,641 s, soit **874/s par slot**. En incluant aussi les plans de
composition `E`, ce dernier débit est **998/s par slot** pour 4 344 000
combats. Ces moyennes sont pondérées par le temps effectivement mesuré, et les
plans `E` n'ont aucun duel avec deux camps mixtes.

### Classement des plans les plus lents

Le [classement complet des 79 plans](2026-09-26-optimized-plan-ranking.csv)
contient combats, secondes de simulation et combats/s. Voici les quinze
premiers, triés par débit croissant :

| Rang | Plan | Combats/s | Lecture du résultat |
| ---: | --- | ---: | --- |
| 1 | Frappes soldat, effectifs fixes | 403 | Coût des frappes confirmé à contexte égal |
| 2 | Frappes lancier, effectifs fixes | 685 | Coût des frappes confirmé à contexte égal |
| 3 | `X-archer-cut` | 700 | Composition, pas un curseur isolé |
| 4 | `X-simplex` | 966 | Exploration de compositions |
| 5 | Remplacer par archers | 1 004 | Composition |
| 6 | Remplacer par lanciers | 1 025 | Composition |
| 7 | Coût soldat, effectifs fixes | 1 055 | Faux suspect : coût absent des tirs et dégâts |
| 8 | Efficacité du soldat en défense | 1 106 | Change ses dégâts et parfois la durée du duel |
| 9 | Ajouter des lanciers | 1 151 | Composition |
| 10 | Soldat capturable | 1 168 | Faux suspect : conséquence après combat |
| 11 | Structure soldat | 1 183 | Change la survie et les rounds |
| 12 | Dispersion de précision soldat | 1 206 | Change les issues ; surcoût propre non isolé |
| 13 | Attaque soldat | 1 217 | Change dégâts et durée |
| 14 | Coût soldat, budget fixe | 1 257 | Change le nombre d'unités achetées |
| 15 | Efficacité du lancier en défense | 1 273 | Change ses dégâts et parfois la durée |

Ce classement indique où le moteur tousse, mais **pas pourquoi** à lui seul.
Les rangs 7 et 10 le prouvent : ces paramètres ne changent pas la résolution
physique. Pour incriminer une règle, il faut comparer ses valeurs dans le
même plan, puis vérifier rounds et composition.

Le diagnostic de coût est donc, par ordre de solidité : **frappes** (chute
mesurée à scénarios identiques quand leur nombre monte), **durée du combat**
(plafond et reddition : davantage de rounds coûtent davantage), puis
**compositions chargées en soldats ou lanciers** (plans lents, sans paramètre
unique à supprimer). Le coût propre de la dispersion, des contres et des
projections de pertes n'est pas isolé. Leur rang brut ne justifie pas de les
retirer du moteur.

| Famille de plans | Combats | Temps de simulation | Part du temps de campagne |
| --- | ---: | ---: | ---: |
| Frappes par attaque, quatre unités | 1 728 000 | 2 103 s | 13,5 % |
| Composition `X-simplex` | 2 100 000 | 2 173 s | 14,0 % |
| Tous les paramètres de combat | 4 440 000 | 2 327 s | 14,9 % |
| Tous les contres dirigés | 1 284 000 | 587 s | 3,8 % |

La part du temps reflète aussi le **budget de combats attribué à chaque
famille**. Elle ne signifie pas que le paramètre consomme cette part en
production. Le plan de frappes du soldat est le plus lent : 432 000 combats en
1 072 s, soit 403 combats/s sur toute sa plage.

## Fragmentation : le coût démontré

Le moteur divise l'attaque par le nombre de frappes avant de résoudre les
impacts. La fragmentation joue donc surtout sur la variance, la distribution
et l'overkill, pas sur un multiplicateur direct d'attaque. Le tir reste
aléatoire : l'allocation et l'occupation des cohortes tirent sur le RNG.

| Unité modifiée | 1 frappe | 8 frappes | 12 frappes | 32 frappes |
| --- | ---: | ---: | ---: | ---: |
| Soldat | 1 085/s | 478/s | 367/s | 194/s |
| Lancier | 1 342/s | 827/s | 649/s | 333/s |
| Archer | 2 737/s | 1 968/s | 1 711/s | 1 292/s |
| Chevalier | 3 134/s | 2 845/s | 2 800/s | 2 093/s |

Ces valeurs sont les débits des lots de la **même série de scénarios** à chaque
valeur. Chez le soldat, 8 → 12 coûte encore 23 % de débit ; dans les 24
contextes appariés, le changement de victoire médian est nul et le maximum
est 4,2 points. Chez le lancier, 8 → 12 coûte 22 % pour un maximum de 2,9
points. L'archer reste plus sensible (jusqu'à 26,8 points) et le chevalier a
un cas qui bascule entièrement. Le plafond de production à 10 est donc un
arbitrage raisonnable pour borner le coût ; il ne signifie pas que toutes les
valeurs au-delà de 8 sont équivalentes.

**Décision recommandée :** conserver la fragmentation stochastique, plafonnée
à 10. Ne pas réintroduire 24 ou 32 pour corriger un duel particulier ; régler
d'abord attaque, précision et structure. Le coût soldat/lancier mérite un
profilage ciblé de l'occupation des impacts si 10 frappes restent trop lentes.

## Rounds, reddition et paramètres de conséquence

| Paramètre | Effet observé | Décision proposée |
| --- | --- | --- |
| Plafond de rounds | 20 → 10 : 1 703 → 1 931 combats/s dans le même plan (+13 %). La différence de victoire médiane est nulle mais atteint 100 points dans certains duels plafonnés. | Garder le plafond fixe à 20 ; ne pas en faire un curseur de réglage courant. |
| Reddition | À 35 % de morts : 1 645/s. À 5 % : 2 329/s, parce que les combats s'arrêtent plus tôt. Désactiver la reddition donne 1 467/s. Le seuil change aussi les vainqueurs et les pertes. | Garder une règle de reddition, puis choisir un seuil métier unique après lecture des duels qui basculent. |
| Seuil de blessure | Autour de 0,1–0,5, les débits vont de 1 684 à 1 739/s ; pas de goulet comparable aux frappes. Il ne change ni victoire ni rounds, mais change blessés, pertes économiques et prisonniers. | Garder une règle de classification des blessés ; figer sa valeur dans le moteur ou le profil métier une fois calibrée, sans l'exposer comme réglage libre. |
| Taux de capture | Aucun effet sur victoire ou rounds. Sur les contextes testés, changer 24 % en 0–50 % modifie le taux de prisonniers de 0,7 point d'effectif initial au maximum. | Revoir après la compression des pertes ; aujourd'hui son effet est fortement atténué. |
| `capturable` | Aucun effet sur le combat brut ; effet limité aux prisonniers et aux pertes projetées. | Le garder comme propriété d'unité si les prisonniers sont une mécanique voulue, sans le confondre avec une stat de combat. |

### Compression des pertes : décision de conception, sans coût moteur démontré

Dans `project_type`, `lossCompressionPercent` échantillonne une nouvelle fois
les morts, les blessés et les prisonniers **après** le combat. À la valeur de
référence de 5 %, environ 5 % des morts bruts deviennent des morts projetés.
Dans les 60 lignes du plan de référence : 181 236 morts bruts moyens cumulés
contre 9 071 morts projetés ; 309 705 blessés bruts contre 13 932 blessés
projetés, plus 1 562 prisonniers projetés. Le ratio morts est 5,0 % ; celui
des blessés libres est 4,5 %, car une partie est sélectionnée comme prisonniers.

Changer la compression ne modifie ni les victoires ni les rounds, mais modifie
massivement les conséquences. Entre 5 % et 100 %, la variation médiane du taux
de perte économique du camp A est de **73,9 points** dans les 60 contextes
appariés. La compression ne ressort pas comme goulet de calcul : son problème
est la cohérence physique. À 5 %, la plupart des pertes produites par le
combat disparaissent de l'état livré.

Ce chiffre ne démontre pas l'utilité d'un curseur de compression : le taux
de pertes change parce que c'est précisément ce que fait le curseur. La vraie
question est de savoir pourquoi un combat doit effacer aléatoirement 95 % de
ses pertes brutes avant de livrer son résultat. Cela découple fortement la
physique, les prisonniers et le coût économique affiché. **Proposition :**
retirer cette compression du modèle cible et calibrer directement une
physique dont les pertes brutes sont livrables. C'est un arbitrage de règles,
pas une optimisation de CPU ; ne pas changer seulement la valeur sans
recalibrer dégâts et capture.

## Paramètres qui classent le résultat sans changer le combat

- `cost` ne participe pas aux tirs ni aux dégâts. À effectifs fixes, ses plans
  gardent exactement les mêmes rounds et morts bruts, mais le gagnant change
  parfois : le critère final `economic` compare la valeur survivante. Le coût
  a sa place dans les budgets et rapports économiques ; le laisser décider un
  vainqueur est un choix de règle, pas une propriété physique du combat.
- `tieBreakCriterion` change jusqu'à 53,2 points de victoire dans un contexte,
  sans changer rounds ni pertes brutes. Un critère unique doit être choisi ;
  une comparaison de structure restante est plus directement liée à l'état
  militaire que le coût des unités.
- `equalityPolicy` n'a changé aucun résultat dans les 60 contextes appariés
  (240 000 combats). Cela ne prouve pas qu'une égalité exacte soit impossible,
  mais n'établit pas l'intérêt d'un paramètre configurable. Fixer une règle
  explicite pour les égalités exactes paraît suffisant.

## Stats d'unité et matrice de contres

Attaque, structure, précision de base et efficacité en défense modifient
nettement les résultats dans les plages testées. `accuracySpread` n'est pas
une simple décoration : passer de 0 à 0,05 déplace la probabilité de victoire
de 5,2 points en médiane pour les contextes soldat, avec un maximum de 36,6
points. Son plan soldat tourne à 1 206/s, proche du plan d'attaque à 1 217/s ;
la campagne n'isole pas un surcoût du tirage de dispersion.

La sémantique de `defendingEfficiency` prête à confusion : le code multiplie
les **dégâts émis par une unité lorsque son camp défend**. La matrice de
relations multiplie les dégâts selon le **couple unité qui tire–type visé**.
Les poids de ciblage restent uniformes dans le profil PHP actuel. Ces deux
leviers ne sont donc pas interchangeables. Les plans de contres montrent que
changer un facteur dirigé peut retourner un duel, mais ils n'établissent pas
qu'un jeu de douze facteurs libres est nécessaire au gameplay. Aucun surcoût
spécifique de la matrice n'est isolé par cette campagne.

**Décision recommandée :** renommer `defendingEfficiency` pour décrire son
effet réel. Garder les facteurs de contres à ×1 tant qu'aucun comportement
voulu par paire n'est formulé ; si des contres sont voulus, définir quelques
relations métier explicites plutôt qu'un buffet de douze curseurs.

## Limites et suite

Les écarts de victoire sont calculés à contexte identique avec 2 000 combats
par direction et valeur. Les extrêmes des plages testées (par exemple 0–10
pour les contres et 0–100 % pour la compression) prouvent une sensibilité,
pas une bonne valeur de production. Les simulations agrégées ne démontrent
pas à elles seules le réalisme. Le débit d'un plan mélange coût algorithmique,
durée du duel et composition ; seul le comparatif par valeur dans un même plan
permet un premier diagnostic de coût.

Prochaine tranche utile : définir les conséquences brutes et la règle de
vainqueur à 20 rounds, puis mesurer les scénarios proches des valeurs retenues.
Cette campagne est attachée à un moteur épinglé antérieur au plafond 10 ; les
valeurs 12–32 restent des données de recherche et non une configuration
livrable.
