# Constats météo exploratoires — météo commune aux deux camps

Les lignes citées se trouvent dans [`shared-weather-comparisons.csv`](shared-weather-comparisons.csv)
et [`aggregates.csv`](aggregates.csv) ; chaque point compte 2 000 combats.
Les écarts de victoire ne sont pas des tests appariés. Les intervalles de Wilson
à 95 % sont **marginaux**, sous l'hypothèse d'échantillonnage du protocole ; les
nombreuses comparaisons restent exploratoires.

## W-01 — Le protocole asymétrique W est hors du combat Waar

**Décision produit.** Le 25 septembre, le PO a précisé que la météo est le contexte
du combat et qu'A et B ont, par définition, la même météo. Sur les 26 640 000
combats W inscrits, 25 200 000 ont des météos différentes par camp : les 80 plans
de facteurs et les cas « A seul / B seul » des presets. Ils sont intègres et
archivés, mais ne mesurent pas un combat conforme au jeu. Les 1 440 000 combats
à météo commune donnent 1 080 000 combats effectifs uniques après déduplication.
Le [comptage reproductible](weather-context-audit.json) et les plans source
fondent cette séparation. **Ne pas interpréter les facteurs W asymétriques comme
des conseils de réglage météo.** Le CLI autorisait ce protocole ; cela explique
la campagne, sans transformer sa conception en règle métier acceptée.

## W-02 — Vent et tempête peuvent inverser lanciers contre archers, selon le rôle

**Observation.** `mono-b120000-spearman-archer`, A = 1 714 lanciers, B = 1 714
archers, 119 980 or chacun, A attaquant : A gagne **0/2 000** sous `neutral`
(Wilson 0–0,19 %), puis **2 000/2 000** sous `wind` et `storm` (99,81–100 %).
Les morts physiques moyens de A passent de **1 117,74** à **277,96** et **19,31** ;
les trois points durent 7 rounds en moyenne. Sources : plans
`M-weather-preset-{neutral,wind,storm}`, IDs
`fc99451412d760a76f235ab5`, `e5d7a80d9cfe21f3d125ff97` et
`56f970969f44506f34ea8a22`, sens A→B ;
[figure et valeurs exactes](figures/04-shared-weather-spearman-archer.svg).

**Mécanisme établi.** Le profil authentique applique aux archers une précision
de base ×0,875 sous `wind` et ×0,75 sous `storm`, aux deux camps. Seul B possède
des archers dans ce cas. `EngineProfile::weatherModifiers()` et
`CohortRequestFactory::combat()` transmettent les facteurs ; `v2.rs` prépare la
précision par multiplication (lignes 410–455), tire les touches (632–690), puis
résout chaque action (1098–1152). La baisse de précision de B est cohérente avec
la forte baisse des morts de A. Le sens B→A garde **2 000/2 000 victoires de A**
aux trois météos : l'effet sur l'issue n'est pas symétrique selon le rôle.

**À ne pas conclure.** Les agrégats ne montrent ni les touches ni les trajectoires
individuelles ; ils ne prouvent pas à eux seuls pourquoi le rôle B→A reste au
plafond. D'autres confrontations ou compositions mixtes peuvent réagir autrement.
Une victoire sur 2 000 essais n'établit pas une probabilité vraie de 100 %.
Le `bench_economic_loss_rate` de A reste proche de 5 % dans ces trois points
malgré le changement de morts physiques : il valorise les morts et blessés
**projetés**, sous compression à 5 %, et ne mesure pas ces morts physiques.

## W-03 — Chaleur et canicule défont un avantage local des lanciers

**Observation.** `mono-b12000-spearman-knight`, A = 171 lanciers (11 970 or),
B = 21 chevaliers (11 550 or), A attaquant : A gagne **1 554/2 000** sous
`neutral` (77,7 %, Wilson 75,82–79,47 %), **41/2 000** sous `heat`
(2,05 %, 1,51–2,77 %) et **0/2 000** sous `canicule` (0–0,19 %).
Les morts physiques moyens de B passent de **13,732** à **1,231** puis **0,0665** ;
ceux de A restent proches de 75,5–76. Les rounds moyens restent proches de 3.
Sources : `M-weather-preset-{neutral,heat,canicule}`, IDs
`37ac5d1a6b11bd111d215af5`, `1a6bd23c0eb9c6b7d6266482` et
`a5948e5cfdf5a4349fd0ba29`, sens A→B. Dans le sens B→A, A gagne les 2 000
combats aux trois points.

**Mécanisme établi et limite.** Le profil réduit l'attaque des lanciers à
×0,875 (`heat`) ou ×0,75 (`canicule`) pour les deux camps ; seul A possède des
lanciers ici. Le moteur multiplie l'attaque préparée, puis résout les dégâts ;
la baisse mesurée des morts de B est cohérente. Les règles de reddition et de
départage peuvent aussi intervenir dans le basculement final ; les seules sommes
batch ne permettent pas d'attribuer à l'une d'elles la part exacte de l'écart.
Les budgets réels des deux camps ne sont pas égaux, mais ils restent identiques
entre les trois météos comparées.

## W-04 — `cloudy` est un témoin identique à `neutral`

Les facteurs d'attaque et de précision de `cloudy` valent tous ×1, comme
`neutral` dans ce profil. Les **60 directions** explicites à météo commune
`cloudy` ont la même empreinte de requête effective et les mêmes agrégats que
leur témoin `neutral` : ce sont des alias, pas 120 000 nouveaux combats
indépendants. Ce contrôle valide la déduplication, sans démontrer qu'une météo
non neutre n'a pas d'effet ailleurs.

## Ce qui reste à décider

Les monotypes et les deux sens montrent des sensibilités fortes, mais ne fixent
pas à eux seuls l'équilibrage voulu. Avant toute conclusion sur les facteurs
météo ou sur les compositions mixtes, le protocole doit appliquer **une météo
commune** à A et B ; les anciens plans de facteurs ne peuvent pas être corrigés
par une réétiquette des résultats. Aucun nouveau combat n'a été lancé ni
approuvé dans cette analyse. Aucune confirmation supplémentaire n'est proposée
dans cette passe : la portée du prochain protocole dépend d'abord des questions
de gameplay à trancher.
