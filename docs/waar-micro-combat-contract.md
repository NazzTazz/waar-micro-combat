# Waar — contrat candidat du micro-résolveur de combat

8 septembre 2026. Contrat T20 corrigé numériquement en T21 et validé comme base du premier noyau PHP. Il concerne la contribution combat à Waar Legacy. Il ne modifie pas le moteur 3.1 destiné à Arbestra et n'active aucune règle de jeu.

## Périmètre

Le résolveur est une bibliothèque PHP pure compatible PHP 8.2, sans Symfony, Doctrine ni accès à la base. Il reçoit une bataille entièrement préparée, la résout, puis retourne le vainqueur et les conséquences brutes par type. Le jeu hôte prépare météo, moral et autres bonus, puis traduit une seule fois ces conséquences vers armées, infirmerie, statistiques, butin et rapport.

Le premier contrat contient exactement quatre types : `soldier`, `spearman`, `archer`, `knight`. Il n'introduit ni plugins, ni types dynamiques, ni blessures persistantes, ni salves, ni ciblage configurable, ni reddition. Les quotas horaires liés aux proportions de Lanciers et de Chevaliers restent dans le jeu hôte.

## Décisions proposées pour le prototype

| Sujet | Décision candidate |
|---|---|
| Résistance | `structure` est la résistance individuelle, dans les deux camps. |
| Spécialisation défensive | `defendingEfficiency` multiplie les dégâts infligés par un type lorsqu'il appartient au défenseur. |
| Contres | Matrice dirigée 4 × 4 `damageFactor[actingType][targetType]`, neutre à `1`. |
| Échanges | Rounds simultanés ; les deux dégâts utilisent les effectifs présents au début du round. |
| Exposition | Chaque type cible reçoit une part des dégâts proportionnelle à son nombre de survivants au début du round. |
| Dommages partiels | La structure est mutualisée par type. Une unité survit tant que sa fraction de structure n'est pas entièrement consommée. |
| Aléatoire | Un multiplicateur borné par camp et par round, produit par un générateur entier seedé. Amplitude par défaut : ±10 %. |
| Arrêt | Extinction d'un camp ou `maxRounds`, compris entre 1 et 30. |
| Victoire à la limite | Comparaison des proportions de structure restante. La politique historique `draw` conserve un nul exact ; la politique T28 `defender` attribue l'égalité au défenseur. |
| Coût | Sert aux budgets et métriques économiques. Il n'intervient pas dans les dégâts ni dans la victoire. |

Ces choix forment une base testable. Ils ne fixent encore ni le quadruplet des unités ni les valeurs non neutres de la matrice de contres.

## Entrée logique

Un `PreparedBattle` autonome contient :

- `rulesetId` et `rulesetVersion` ;
- `maxRounds` et `randomSpread` ;
- les seize `damageFactor` dirigés ;
- pour chaque camp et chaque type, `count`, `effectiveAttack`, `effectiveStructure` et `effectiveDefendingEfficiency` ;
- le coût unitaire déclaré pour les métriques, séparé des valeurs de résolution ;
- une graine entière comprise entre `0` et `2^31 - 1`.

Les valeurs `effective*` sont déjà préparées par Waar. Le résolveur ne sait pas si elles proviennent du catalogue, de la météo ou du moral. `effectiveDefendingEfficiency` n'est appliqué qu'aux unités du camp défenseur. Les facteurs de contre et d'efficacité défensive sont compris entre `0` et `10` inclus.

Les effectifs sont des entiers positifs ou nuls. Le contrat n'instaure pas de rejet fonctionnel à 500 000 unités. L'implémentation vérifiera les dépassements arithmétiques et les mesures de performance couvriront plusieurs tailles jusqu'aux armées de moins de 500 000 unités au total.

## Modèle numérique

Les décimaux d'entrée acceptent au plus six chiffres après la virgule. Ils sont convertis une fois en entiers à l'échelle `S = 1 000 000`. Les additions de dommages utilisent ensuite ces entiers sans nouvel arrondi. Les seules divisions arrondies sont les cinq étapes ordonnées décrites dans la section suivante et la construction du multiplicateur aléatoire.

Pour des entiers non négatifs `x`, `y` et un diviseur positif `d`, le contrat utilise `mulDivNearest(x, y, d)`, arrondi au plus proche avec les moitiés vers le haut. L'implémentation ne calcule pas directement `x × y` :

```text
q = floor(x / d)
r = x mod d
mulDivNearest(x, y, d) = q × y + roundNearest((r × y) / d)
```

Les produits `q × y`, `r × y` et leur somme sont vérifiés séparément. Avec les facteurs de rôle et de contre bornés entre `0` et `10`, `r × y` reste borné lorsque `d = S`. Pour l'exposition, `y` est un effectif cible inférieur ou égal à `d`, qui est l'effectif adverse total. Un dépassement du résultat décomposé produit `numeric_overflow` ; un dépassement du produit naïf `x × y` ne constitue pas une erreur si le résultat décomposé tient sur 64 bits.

La construction du facteur aléatoire utilise une division signée arrondie au plus proche, les moitiés s'éloignant de zéro. Aucune opération du moteur ne repose sur un flottant PHP.

## Résolution d'un round

Pour un camp agissant `s`, un type agissant `i` et un type cible `j` :

- `N[s,i]` est le nombre de survivants au début du round ;
- `A[s,i]` est son attaque effective ;
- `R[s,i]` vaut `defendingEfficiency[s,i]` si `s` est le défenseur, sinon `1` ;
- `Z[s,r]` est le multiplicateur aléatoire du camp pour le round ;
- `E[t,j] = N[t,j] / Σk N[t,k]` est l'exposition du type cible ;
- `C[i,j]` est le multiplicateur dirigé de contre.

La formule mathématique de la contribution de `i` aux dommages reçus par `j` est :

```text
D[s→t,i,j] = N[s,i] × A[s,i] × R[s,i] × Z[s,r] × E[t,j] × C[i,j]
```

Son calcul normatif suit exactement cet ordre, avec `A`, `R`, `Z` et `C` déjà exprimés à l'échelle `S` :

```text
P0 = N[s,i] × A[s,i]                                      // produit entier exact
P1 = mulDivNearest(P0, R[s,i], S)                         // efficacité de rôle
P2 = mulDivNearest(P1, Z[s,r], S)                         // aléa du camp
P3 = mulDivNearest(P2, N[t,j], Σk N[t,k])                 // exposition, non préarrondie
D[s→t,i,j] = mulDivNearest(P3, C[i,j], S)                 // contre dirigé
```

Il n'existe aucun arrondi intermédiaire supplémentaire. En particulier, `E[t,j]` et `u` ne sont jamais convertis en flottants ni arrondis séparément avant ces opérations.

Les dommages reçus par le type `j` sont la somme des quatre contributions :

```text
D[s→t,j] = Σi D[s→t,i,j]
```

L'exposition dépend uniquement des effectifs cibles. Un grand nombre de Soldats peut donc absorber une part des attaques qui auraient touché des unités coûteuses. La matrice de contres change ensuite l'efficacité des dégâts dirigés vers chaque type. Elle ne change pas l'exposition.

Les deux camps calculent leurs dommages depuis le même état de début de round. Les deux résultats sont ensuite appliqués simultanément.

## Structure mutualisée et pertes entières

Pour chaque camp et chaque type, le résolveur conserve une structure totale `Q`. Au départ :

```text
Q[side,type] = count[side,type] × effectiveStructure[side,type]
```

Après application simultanée des dégâts :

```text
Q' = max(0, Q - D)
survivors = 0                              si Q' = 0
survivors = ceil(Q' / effectiveStructure)  sinon
dead = survivorsBefore - survivors
```

La fraction de structure appartient implicitement à une unité survivante du type. Tous les survivants, y compris l'unité partiellement endommagée, attaquent normalement au round suivant. Le prototype ne transforme pas cette unité en blessé persistant.

Ce pooling par type est une approximation explicite qui permet un coût indépendant de l'effectif. Le rapport fournit `remainingStructure` afin que l'hôte n'interprète pas silencieusement une unité partielle comme un passage à l'infirmerie.

## Aléatoire reproductible

Le prototype utilise le LCG31 suivant :

```text
M = 2³¹
state₀ = seed
stateₙ₊₁ = (1 103 515 245 × stateₙ + 12 345) mod M
delta = 2 × stateₙ - M
offsetMicro = roundNearestSigned(randomSpreadMicro × delta / M)
randomFactorMicro = S + offsetMicro
```

Deux tirages sont consommés à chaque round encore joué : attaquant puis défenseur. Ils sont consommés même si `randomSpread = 0`, ce qui garde un ordre stable lorsque l'amplitude change. `randomSpread` est compris entre `0` et `0.5` ; la valeur initiale proposée est `0.1`.

Dans la soufflerie, la graine d'une répétition est dérivée de façon indépendante de l'ordre d'exécution :

```text
iterationSeed = first31Bits(SHA-256(baseSeed + NUL + scenarioId + NUL + iteration))
```

Le taux de victoire d'un scénario fixe a donc une source d'aléatoire définie et reproductible.

## Arrêt et victoire

Après chaque application simultanée :

1. les deux camps sont vides : nul, raison `mutual-extinction` ;
2. seul l'attaquant est vide : défenseur, raison `attacker-extinction` ;
3. seul le défenseur est vide : attaquant, raison `defender-extinction` ;
4. sinon, un nouveau round commence jusqu'à `maxRounds`.

À la limite de rounds, on calcule pour chaque camp :

```text
structurePreservation = totalRemainingStructure / totalInitialStructure
```

La plus grande proportion gagne avec la raison `round-limit-preservation`. Sous
la politique historique `draw`, une égalité exacte dans le modèle entier
produit un nul `round-limit-equality`. Sous la politique T28 `defender`, elle
produit une victoire défensive `defender-tie-break-round-limit-equality`. Une
extinction mutuelle devient de même
`defender-tie-break-mutual-extinction`. Cette politique change uniquement le
vainqueur et la raison : elle ne modifie aucun dégât, survivant ou état de
structure. Le coût économique n'entre pas dans ce départage.

Une armée vide au départ perd sans jouer de round ; deux armées vides produisent un nul. Les métriques dont le dénominateur initial est nul valent `null`.

## Sortie brute

Le résultat contient au minimum :

- `winner`: `attacker`, `defender` ou `null` ;
- `reason` et `roundsPlayed` ;
- par camp et par type : effectif initial, survivants, morts, structure initiale et structure restante ;
- par round et par type cible : dommages reçus, pour permettre un rapport vérifiable ;
- identité et version du ruleset, graine consommée et version du modèle numérique.

Le micro-résolveur ne renvoie pas de prisonniers, de butin, d'admissions à l'infirmerie ni de modifications de village. L'adaptateur Waar produit ces conséquences une seule fois à partir du résultat brut.

## Métriques de soufflerie

Pour un scénario, un camp choisi et `K` répétitions, l'axe X est :

```text
winRate = numberOfWins / K
```

Les nuls restent dans `K`. Les trois choix initiaux de l'axe Y utilisent tous les combats, gagnés ou non :

| Grandeur | Valeur d'une répétition | Valeur affichée en pourcentage |
|---|---|---|
| Effectifs survivants | `Σtype survivors[type]` | moyenne des survivants ÷ effectif initial du camp |
| Structure restante | `Σtype remainingStructure[type]` | moyenne de la structure restante ÷ structure initiale du camp |
| Valeur économique restante | `Σtype survivors[type] × cost[type]` | moyenne de la valeur restante ÷ valeur initiale du camp |

Une unité partiellement endommagée compte comme un survivant et conserve son coût nominal dans la troisième mesure. Une future « valeur structurelle restante » serait une quatrième métrique distincte. Changer Y ne relance pas les combats : le résultat d'expérience conserve les trois numérateurs, leurs dénominateurs et le nombre de répétitions.

Un dénominateur nul donne `null`, jamais zéro. Chaque point du graphe expose les valeurs absolues, le pourcentage, `K` et un intervalle de Wilson à 95 % pour X. Une mesure d'incertitude pour Y sera choisie avec le protocole de raffinement ; elle ne doit pas réutiliser l'intervalle binomial de X.

## Exemples calculables à la main

### Exemple A — un round simultané et victoire

Paramètres : `randomSpread = 0`, `maxRounds = 1`, tous les contres à `1`.

- Attaquant : 10 Soldats, attaque 2, structure 5, coût 10.
- Défenseur : 4 Lanciers, attaque 3, structure 10, coût 25, efficacité défensive 1,5.

Il n'existe qu'un type cible de chaque côté, donc son exposition vaut 1.

```text
D attaquant → défenseur = 10 × 2 = 20
D défenseur → attaquant = 4 × 3 × 1,5 = 18
```

Après application simultanée :

- Soldats : structure `50 - 18 = 32`, survivants `ceil(32 / 5) = 7`, morts 3 ;
- Lanciers : structure `40 - 20 = 20`, survivants `ceil(20 / 10) = 2`, morts 2.

Préservation structurelle : attaquant `32/50 = 64 %`, défenseur `20/40 = 50 %`. L'attaquant gagne par `round-limit-preservation`.

Pour l'attaquant, les trois valeurs Y sont : effectifs `7/10 = 70 %`, structure `32/50 = 64 %`, valeur économique `(7×10)/(10×10) = 70 %`. Pour le défenseur, elles valent toutes 50 %.

### Exemple B — exposition, tampon et valeur nominale

Paramètres : un round, aucun aléa. Deux Archers attaquent avec une attaque de 10. Le défenseur possède 6 Soldats de structure 5 et coût 1, ainsi que 2 Chevaliers de structure 20 et coût 10. Le contre Archer → Soldat vaut 1 ; Archer → Chevalier vaut 2. Le défenseur n'inflige aucun dégât dans cet exemple isolé.

Expositions : Soldat `6/8 = 75 %`, Chevalier `2/8 = 25 %`.

```text
D vers Soldats    = 2 × 10 × 0,75 × 1 = 15
D vers Chevaliers = 2 × 10 × 0,25 × 2 = 10
```

Résultat : les Soldats passent de 30 à 15 de structure, donc de 6 à 3 survivants. Les Chevaliers passent de 40 à 30 de structure et restent 2. Leur préservation structurelle vaut 75 %, mais leur valeur économique nominale reste 20.

Pour le défenseur : effectifs `5/8 = 62,5 %`, structure `45/70 ≈ 64,29 %`, valeur économique `(3×1 + 2×10)/(6×1 + 2×10) = 23/26 ≈ 88,46 %`.

Sans les six Soldats, les deux Chevaliers auraient une exposition de 100 % et recevraient `2×10×1×2 = 40`, soit toute leur structure. Cette comparaison illustre le mécanisme de tampon ; elle ne prouve pas sa rentabilité, car les budgets initiaux diffèrent.

### Exemple C — deux premiers tirages seedés

Avec la graine 42, `randomSpreadMicro = 100 000` et `S = 1 000 000` :

```text
état attaquant = 1 250 496 027
offset attaquant = roundNearestSigned(100 000 × 353 508 406 / 2³¹) = 16 462
Z attaquant = 1 016 462 micro = 1,016462

état défenseur = 1 116 302 264
offset défenseur = roundNearestSigned(100 000 × 85 120 880 / 2³¹) = 3 964
Z défenseur = 1 003 964 micro = 1,003964
```

Une pression attaquante de `100 000 000` micro avant aléa devient donc :

```text
mulDivNearest(100 000 000, 1 016 462, 1 000 000)
= 101 646 200 micro
= 101,646200
```

Rejouer la même entrée avec la graine 42 doit produire exactement ces deux facteurs micro et cette pression arrondie au premier round.

### Exemple D — produit intermédiaire supérieur à 64 bits

Avec 250 000 unités et une attaque de 100, la pression de base vaut `25 000 000 000 000` micro. Appliquer un facteur de `1,1` par le produit naïf demanderait de former `25 000 000 000 000 × 1 100 000`, supérieur à un entier signé 64 bits.

La décomposition normative donne :

```text
q = floor(25 000 000 000 000 / 1 000 000) = 25 000 000
r = 0
q × 1 100 000 + roundNearest(r × 1 100 000 / 1 000 000)
= 27 500 000 000 000 micro
= 27 500 000
```

Le résultat tient sur 64 bits et doit être accepté. Le test de cette valeur est obligatoire avant toute mesure de performance proche de 500 000 unités.

## Frontières avec Waar

L'adaptateur autour de `CombatService::attaquer()` devra démontrer :

- la préparation explicite des valeurs effectives ;
- une seule invocation autoritaire du résolveur par attaque validée ;
- une traduction documentée de `dead` et `remainingStructure` vers les pertes Waar ;
- aucune admission implicite à l'infirmerie depuis une structure partielle ;
- l'application transactionnelle et idempotente des conséquences ;
- la conservation des règles hôtes pour météo, moral, quotas, butin, prisonniers et statistiques.

Le plafond de pertes et la projection vers l'infirmerie restent bloqués jusqu'à une décision séparée.

## Preuves attendues avant implémentation de la soufflerie

1. Recalcul indépendant des exemples A, B, C et D dans les tests du résolveur.
2. Tests des seize cellules de la matrice, de l'orientation attaquant/défenseur et de l'application simultanée.
3. Tests aux frontières de structure partielle, extinction mutuelle, égalité et dénominateur nul.
4. Reproductibilité par seed et indépendance à l'ordre des scénarios.
5. Corpus de rôles adapté de T17, avec petits budgets, armées avancées et contres explicitement justifiés.
6. Test numérique de 250 000 unités avec attaque 100 et facteur 1,1, attendu `27 500 000 000 000` micro sans dépassement ; puis mesures séparées d'une résolution et de centaines de répétitions sur plusieurs tailles, dont des totaux proches de 500 000 unités.
7. Mutation de chaque règle centrale : exposition, contre, efficacité défensive, simultanéité, pooling, victoire et valeur économique nominale.

T20 et sa correction numérique T21 autorisent l'implémentation du premier noyau. Elles ne valident encore aucun quadruplet, contre non neutre ou résultat de gameplay.
