# Modèle mathématique du gameplay demandé

Modèle de travail calculé le 13 septembre 2026. Aucun nouveau gameplay ajouté.
Les contraintes ci-dessous remplacent les propositions de pertes après compression
faites précédemment dans la conversation. Ce document ne constitue pas un candidat validé.

## 1. Grandeurs et objectifs

Types S, L, A, C ; coûts fixes c = (10,70,70,550).
Pour un budget B, effectifs initiaux n_i = floor(B/c_i).
À B = 400400 : (40040,5720,5720,728), budgets exactement égaux.

La perte demandée est le coût de TOUTES les unités atteintes avant compression :

    L = somme_i c_i (blessés_i + morts_i) / budget_initial
      = 1 - somme_i c_i indemnes_i / budget_initial.

Les prisonniers sont sélectionnés parmi les blessés après résolution : les ajouter
aux blessés bruts compterait les mêmes unités deux fois. Équivalent : blessés libres
+ prisonniers sélectionnés + morts, AVANT compression. Cette métrique n'est ni
`rawLossRatio` (morts seuls), ni le coût économique perdu actuel (morts/captures projetés).
L'ancien optimiseur n'évalue donc pas les nouvelles contraintes de pertes.

### Confrontations à budget égal

| Attaquant | Défenseur | Favori | Cible de victoire | Pertes souhaitées |
| --- | --- | --- | --- | --- |
| A | S | A | 60–80 % | libres |
| S | A | A | 60–80 % | libres |
| S | C | S | 60–80 % | libres, rôle tampon |
| C | S | S | 60–80 % | libres, rôle tampon |
| S | L | S | 60–80 % | libres |
| L | S | S | 60–80 % | libres |
| C | L | L | défense fortement favorisée | à calibrer |
| L | C | C | défense fortement favorisée | L fortement atteint |
| A | L | A | 60–80 % | L_A > L_L |
| L | A | A | 60–80 % | pertes non nulles chez A |
| A | C | C | victoire très nette | L_C < L_A |
| C | A | C | victoire très nette | L_C < L_A |

Le seuil 90 % pour « très net » est une proposition de formalisation, pas un
chiffre expressément choisi par l'utilisateur. Les 60–80 % sont des bandes cibles,
pas la garantie que tous les budgets donnent la même fréquence de victoire.

| Miroir | Résultat | Pertes brutes |
| --- | --- | --- |
| S/S | proche de 50/50 | proches de 100 % des deux côtés |
| L/L | défenseur favorisé | environ 10 % dans chaque camp |
| A/A | attaquant favorisé | 10 % vainqueur / 50 % perdant |
| C/C | proche de 50/50 | environ 10 % dans chaque camp |

Les pertes vainqueur/perdant sont des moyennes CONDITIONNELLES au résultat :
E[L_camp | camp gagne] et E[L_camp | camp perd]. Elles ne doivent pas être
remplacées silencieusement par les moyennes attaquant/défenseur.
Tolérances de travail proposées : 45–55 % pour coinflip, 5–15 % pour « environ
10 % », 40–60 % pour « environ 50 % », >=95 % pour « presque tous atteints ».
Conserver aussi les cibles centrales et les écarts continus ; ces tolérances
sont modifiables, pas des critères d'approbation automatiques.

## 2. Variables utiles

R entier de 1 à 30. Pour chaque type : H structure, A attaque totale,
N frappes, p précision moyenne, s amplitude, D coefficient défensif.
M_ij efficacité dirigée, M_ii=1.

    d_ij = (A_i/N_i) M_ij             en attaque
    d_ij_def = D_i d_ij               en défense
    k_ij = ceil(H_j / d_ij)           touches nécessaires sur une unité indemne

Les équations ci-dessus sont exactes en arithmétique réelle. La vérification
native applique les arrondis micro-unités du moteur avant le calcul des seuils.
Les dégâts ne déterminent pas seuls les pertes : toute touche positive blessant
une unité auparavant indemne la fait compter entièrement dans L.

Paramétrisation préférable : intensité de touches lambda_i = N_i p_i et matrice
des dégâts normalisés z_ij = d_ij/H_j. Le moteur doit toujours recevoir H,A,N,p,D,M,
mais le calcul peut travailler sur ces grandeurs interprétables.
Une multiplication commune de H et A conserve les rapports, hors arrondis.
Fixer H_S=100 comme convention possible retire cette redondance ; ce choix ne
doit pas dépasser les bornes physiques après reconstruction du profil.
Les 12 relations hors diagonale permettent de régler les z_ij ; leurs plafonds
M<=10 et les diagonales imposées doivent être vérifiés à la reconstruction.

## 3. Modèle de cohortes déterministe, compatible avec la structure du code

État par camp/type : cohortes (h,n), morts cumulés. Les deux camps calculent leurs
actions à partir des mêmes instantanés de début de round. Les blessés continuent
à frapper à pleine capacité. Pour une cohorte cible d'effectif m recevant I touches :

    u = floor(I/m), r = I - um
    m-r unités reçoivent u touches ; r unités reçoivent u+1 touches.

C'est la répartition réelle dans `RoundResolver::applyImpacts`, et non une
distribution indépendante de Poisson entre tous les individus. Les tirages
binomiaux répartissent d'abord les touches entre types et cohortes.

Approximation calculable : remplacer ces tirages binomiaux par leurs moyennes,
conserver la séparation en cohortes et la simultanéité, ainsi que l'ordre des
sources S,L,A,C et la réallocation. Effectifs et restes peuvent être fractionnaires.
Pour la précision variable, sa moyenne effective est E[clamp(p+U(-s,s),0,1)],
pas nécessairement p près des bornes ; intégrer ce clamp ou utiliser s=0 au départ.

Cette approximation peut calculer structure, blessés et morts attendus à faible
coût. Elle ne fournit PAS un taux de victoire de 70 % : le signe de la différence
détermine seulement un vainqueur moyen. Les fréquences nécessitent une couche
stochastique et une validation exacte.

## 4. Calcul analytique des miroirs à faibles pertes

Sans décès significatifs, effectifs égaux et 0<=lambda<=1, la fraction indemne
dans chaque camp est approximativement (1-lambda)^R. En effet, les touches
allouées à la cohorte indemne atteignent chacune une unité distincte.

    L ≈ 1-(1-Np)^R
    p ≈ [1-(1-L)^(1/R)]/N

| R | Np pour L=10 % | Np pour L=50 % | Np pour L=99 % |
| --- | ---: | ---: | ---: |
| 3 | 0,034511 | 0,206299 | 0,784557 |
| 10 | 0,010481 | 0,066967 | 0,369043 |
| 30 | 0,003506 | 0,022840 | 0,142304 |

Exemple R=10 : un chevalier à cinq frappes devrait avoir p≈0,002096 (0,21 %)
pour n'atteindre qu'environ 10 % de chevaliers adverses dans son miroir stable.
Un soldat à une frappe demanderait p≈0,369 pour atteindre 99 % de son miroir.
Ces chiffres décrivent une branche sans décès significatifs, pas une solution globale.

Un coefficient défensif augmente la profondeur des blessures, pas le nombre
de premières touches. Si toutes les touches restent non mortelles, il peut donc
faire gagner au départage structurel tout en gardant environ 10 % d'atteints
dans les deux camps. Au départage économique, les blessés gardent leur pleine
valeur : sans mort, c'est l'égalité, puis le défenseur si la politique actuelle reste.
Ainsi un coinflip avec peu d'atteints nécessite soit des morts discriminants,
soit un départage structurel, soit une autre gestion d'égalité déjà disponible.

### Difficulté précise du miroir archer

L'attaquant et le défenseur ont le même N et la même précision ; D ne change que
les dégâts. Dans la branche à effectifs presque constants, L_attaquant=L_defenseur,
quel que soit D tant que les dégâts sont positifs. La cible 10 % / 50 % ne peut
donc PAS être obtenue dans cette branche en réglant seulement la matrice ou D.

Pour R=10 et N=4, les deux cibles demanderaient respectivement p≈0,002620
et p≈0,016742 : un rapport 6,39, alors que le profil fournit une précision commune.
Il faut analyser une branche avec attrition importante, seuils de dégâts et/ou
conditionnement sur le vainqueur. Cette observation n'est pas une preuve
d'impossibilité dans le moteur stochastique complet ; c'est un obstacle identifié
que la résolution doit traiter explicitement avant l'équilibrage des autres duels.

## 5. Contraintes à budget égal : intensité reçue

Avant forte attrition et réallocation, touches reçues par cible j et par round :

    lambda_ij = (n_i/n_j) N_i p_i ≈ (c_j/c_i) N_i p_i.

Le coût agit sur l'exposition, la matrice agit sur la gravité. Les deux sont
distincts. Exemple à R=10 avec les intensités du tableau pour S/S à 99 % et C/C
à 10 % : un chevalier face aux soldats reçoit initialement environ
55×0,369043 = 20,30 touches de soldat par round ; un soldat face aux chevaliers
en reçoit environ 0,010481/55 = 0,000191. La résistance peut retarder la mort du
chevalier, mais la première touche le compte déjà comme perte au sens utilisateur.

Ce calcul explique la possibilité de chevaliers très vulnérables aux soldats
nombreux tout en conservant de faibles pertes dans leur miroir. Il ne garantit
pas que ces chevaliers puissent aussi détruire assez d'archers : cette contrainte
doit être satisfaite par un seuil mortel, la durée, la précision ou le départage.

### Victoire coûteuse des archers contre les lanciers

À budget égal et départage économique, une victoire à la limite des rounds exige
moins de coût de MORTS, pas moins de blessés. Il est donc cohérent d'avoir
L_A > L_L tout en gagnant : par exemple 30 % d'archers blessés, 2 % morts,
contre 5 % de lanciers blessés, 10 % morts. Pertes demandées 32 % contre 15 %,
mais budgets vivants 98 % contre 90 %. Exemple arithmétique, pas simulation.

## 6. Modèle du tampon soldats–chevaliers

Soit x la fraction du budget consacrée aux soldats. Initialement :

    n_S=xB/10 ; n_C=(1-x)B/550
    fraction des touches visant C = (1-x)/(1+54x)
    touches reçues par chevalier, relativement au full C = 1/(1+54x).

| Budget soldats x | Part des touches visant C | Exposition par chevalier / full C | Chevaliers conservés / full C |
| --- | ---: | ---: | ---: |
| 0 % | 100 % | 100 % | 100 % |
| 10 % | 14,06 % | 15,63 % | 90 % |
| 20 % | 6,78 % | 8,47 % | 80 % |
| 50 % | 1,79 % | 3,57 % | 50 % |

À 10 % du budget en soldats, chaque chevalier reçoit donc initialement 6,4 fois
moins de touches, pour seulement 10 % de chevaliers en moins. C'est une synergie
quantifiée, suffisante pour justifier la recherche du mélange. Elle ne prouve pas
sa victoire : le tampon s'use et la réallocation peut accélérer l'exposition.
Tester x sur [0,1] après résolution des monotypes, puis raffiner autour des meilleurs
intervalles ; tenir compte des effectifs entiers et du budget effectivement dépensé.

## 7. Procédure de résolution du modèle

1. Énumérer R=1..30 : trente branches structurelles bon marché, pas trente
   générations stochastiques. Conserver d'abord les frappes narratives comme
   préférence ; ne les verrouiller que si la branche reste réalisable.
2. Déduire les intensités initiales des miroirs par inversion de l'équation des
   indemnes. Identifier séparément la branche asymétrique A/A.
3. Choisir des seuils de touches k ; pour k>=2, imposer
   H_j/k <= d_ij < H_j/(k-1). Pour k=1 : d_ij>=H_j.
   Avec H et N fixés, les plages de M se calculent directement ; les variantes
   attaque/défense doivent partager le même M et le même D par source.
4. Faire évoluer les cohortes déterministes pour vérifier que les pertes visées
   et le signe du départage sont cohérents. Éliminer les branches contradictoires
   dans ce modèle sans les annoncer impossibles dans le modèle stochastique.
5. Sur les profils plausibles, mesurer les 16 duels exacts ; enregistrer victoire,
   indemnes, blessés, morts et les pertes conditionnées au résultat. Le batch actuel
   ne fournit pas toutes ces sommes conditionnelles : ne pas les inventer à partir
   des moyennes globales. Agréger les rapports exacts ou étendre explicitement
   l'instrumentation, sans changer les règles du combat.
6. Régler les marges de victoire par la précision/amplitude et les seuils, puis
   vérifier une plage de seeds indépendante. Une approximation normale de la
   différence de budgets peut aider loin des seuils, mais ne constitue pas une
   preuve de taux de victoire à proximité des éliminations ou des égalités.
7. Rechercher x pour le mélange S+C contre S et comparer les pertes financières.

La première conclusion calculée est donc double : le tampon a un avantage
géométrique très fort ; l'asymétrie 10/50 des archers demande une branche physique
différente des miroirs à faibles pertes. Aucun profil n'est déclaré résolu par
ces équations seules. La prochaine expérience doit tester cette branche précise,
pas relancer indistinctement 28 paramètres contre les anciennes ellipses.
