# Bagaar — première boucle jouable (branche `feat/bagaar`)

Cette tranche ajoute une ère virtuelle locale, sans compte ni accès à la base de Waar. Le preset de combat est figé au départ. Le moteur Rust résout chaque affrontement ; PHP décide des actions, applique l'économie et conserve les états, événements et requêtes/résultats de combat. La page `/bagaar.html` calcule des ticks en arrière-plan et laisse la lecture avancer à sa propre vitesse. L'export JSON contient le preset, le manifeste, les frames et les combats complets. Chaque requête/résultat de combat est encodé en `gzip-base64-json/1` pour contenir la mémoire de l'ère ; l'API de détail restitue le JSON décodé.

## Règles en service

- Vingt comptes nommés : quatre Rageux, Grenouilles, Ascenseurs, Fermiers et Scripteurs. Ils commencent avec 2 000 Or, mine et infirmerie de niveau 0 et armée vide. Chaque profil a un joueur actif toute la journée, un de 9 h à 17 h, un de 17 h à minuit et un de 6 h à 14 h. Les revenus et soins horaires continuent hors connexion ; les décisions attendent la prochaine plage active.
- Recrutement et valeur de l'armée actuelle au **prix du preset** ; améliorations de mine et d'infirmerie au prix Waar. Mine, revenu horaire, prisonniers, quotas selon les parts de chevaliers et lanciers, soin et attrition de l'infirmerie sont des transitions PHP pures.
- Attaque à ±20 Glwaare ; météo commune aux deux armées ; résultat et conséquences projetées du moteur Rust. Les nuls consomment les quotas et appliquent les pertes sans transférer Or, Glwaare ni prisonniers.
- Villages créés à la première tranche admissible, selon le plafond du leader, avec garnison calculée aux prix du preset et recharge horaire. Aucun village initial. Ils apparaissent en losanges sur le même plan que les joueurs, à leur palier de Glwaare et à la valeur de leur garnison. Ils restent hors du classement des joueurs.
- Espionnage payant : le Scripteur ne reçoit que le rapport Waar (Or, effectif total, Glwaare, moral haut/bas), en plus de son état et des événements qui le concernent.
- Reddition automatique après neuf défaites **consécutives en défense** : −30 Glwaare, prisonniers libérés et moral à 100. L'Ascenseur possède une phase de reddition durant laquelle il ne lance plus d'attaques, faute de quoi sa propre attaque casserait la série.
- Prétendance Rwaa à partir de 50 Glwaare, avance stricte, attribution après 24 contrôles complets. Axe x : Or investi dans l'armée active, sans blessés à l'infirmerie.
- La fiche d'un joueur expose sa mine, sa production horaire, ses quatre effectifs et son bilan victoires/nuls/défaites. Sur le plan, les marqueurs clignotent selon le résultat du combat, la reddition, l'avènement et la destitution du Rwaa, le recrutement et le soin.

## Heuristiques initiales à calibrer

Les chiffres ci-dessous appartiennent aux **politiques v1**, pas aux règles de Waar : Grenouille prépare son offensive pendant 75 % des ticks ; Rageux peut répondre trois fois ; Fermier attaque jusqu'à trois fois son village ou ses frigos du début ; Scripteur estime la valeur adverse à partir de l'effectif espionné et du coût unitaire moyen du preset, puis attaque si sa valeur est au moins 1,5 fois cette estimation et si le pillage espéré couvre une estimation de 3 % de sa propre valeur. L'Ascenseur bascule en raid à 2 000 Or d'armée, recherche sa reddition si sa valeur tombe sous 40 % de son pic avec au moins 10 Glwaare, puis reconstruit jusqu'à 60 % du pic (minimum 2 000 Or). Les quatre instances d'un profil ont des coefficients d'agressivité de 80, 95, 105 ou 120 % qui modulent le nombre d'attaques tentées par tick et, pour le Scripteur, son seuil de rentabilité. Ces seuils sont provisoires et versionnés par `decisionVersion` dans le manifeste.

## Limites visibles de cette tranche

La portée d'espionnage est figée à **±10** (défaut du code hôte ; valeur effective de production non vérifiée). Le cron quotidien, les lois du Rwaa, les modes, les alliances, la protection achetable, les trophées et les modificateurs saisonniers ne sont pas encore simulés. Les effets détaillés de moral sur le combat ne sont pas reliés au preset Rust. Les taxes Rwaayales et les lois économiques restent à intégrer. En production, les runs sont conservés dans le volume Docker `waar-engine-demo_bagaar-runs` ; en local, ils restent dans le répertoire temporaire par défaut. L'export JSON permet de les conserver hors du serveur. Cette tranche ne doit donc pas servir à conclure sur l'équilibre d'une ère Waar complète.

## Vérification numérique locale

Avec le preset par défaut, cinq comptes, seed 42 et 24 ticks, la boucle produit 78 combats. Sur ce poste Windows, elle a pris 3,02 s avec un processus Rust par combat et 2,29 s en réutilisant le processus JSONL, avec le **même SHA-256 des frames** (`e726d0b9fe55eb67f19709a58fdf4a50d6b23c443caef37670f5786748e7da7e`). Ce résultat mesure la boucle Bagaar locale complète sur cet exemple, pas le débit isolé du moteur ni une garantie sur 60 jours ou sur le VPS.

Avec le roster par défaut de vingt comptes, le même preset et la même seed, la première semaine produit 1 165 combats en 37,6 s dans une boucle PHP locale réutilisant le processus Rust. Le premier village (`village-20`) apparaît au jour 7, après qu'un joueur a atteint 61 Glwaare. Mesure exploratoire locale, sans transport HTTP ni lecture graphique.

La page propose sept jours par défaut. Le plan garde une grille de 20 000 Or par colonne et 10 Glwaare par ligne, dont l'étendue croît avec les valeurs observées.

Les combats d'un run sont stockés dans une archive annexe plutôt que recopiés avec chaque état. L'export JSON restitue leur liste complète dans le même champ `state.combats`. Un ancien run encore en ligne est migré lors de son prochain pas de calcul ; les seeds et l'ordre des combats restent inchangés.

L'API de calcul transmet les nouvelles trames par paquets de huit ticks. Lors d'un rechargement, l'API de reprise fournit au plus 50 trames par réponse, avec `frameOffset` et `nextFrameOffset` ; les événements sont découpés aux mêmes bornes. L'export complet est un téléchargement séparé et diffusé progressivement. Les requêtes Bagaar disposent d'une limite PHP de 256 Mo et l'export n'a pas la limite d'exécution de 30 secondes du serveur local.

Le run local de 60 jours qui s'était interrompu au tick 489 a repris avec la nouvelle archive et s'est achevé à 1 440 ticks et 13 288 combats. Le pic de mémoire d'un paquet CLI sous la limite initiale de 128 Mo était de 126 Mo ; la marge de 256 Mo évite de dépendre de cette proximité. La reprise HTTP a renvoyé les 1 440 trames et 32 785 événements ; l'export JSON intégral contient les 13 288 combats.
