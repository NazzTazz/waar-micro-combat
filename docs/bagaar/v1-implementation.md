# Bagaar — première boucle jouable (branche `feat/bagaar`)

Cette tranche ajoute une ère virtuelle locale, sans compte ni accès à la base de Waar. Le preset de combat est figé au départ. Le moteur Rust résout chaque affrontement ; PHP décide des actions, applique l'économie et conserve les états, événements et requêtes/résultats de combat. La page `/bagaar.html` calcule des ticks en arrière-plan et laisse la lecture avancer à sa propre vitesse. L'export JSON contient le preset, le manifeste, les frames et les combats complets. Chaque requête/résultat de combat est encodé en `gzip-base64-json/1` pour contenir la mémoire de l'ère ; l'API de détail restitue le JSON décodé.

## Règles en service

- Cinq comptes : Rageux, Grenouille, Ascenseur, Fermier et Scripteur. Ils commencent avec 2 000 Or, mine et infirmerie de niveau 0 et armée vide.
- Recrutement et valeur de l'armée actuelle au **prix du preset** ; améliorations de mine et d'infirmerie au prix Waar. Mine, revenu horaire, prisonniers, quotas selon les parts de chevaliers et lanciers, soin et attrition de l'infirmerie sont des transitions PHP pures.
- Attaque à ±20 Glwaare ; météo commune aux deux armées ; résultat et conséquences projetées du moteur Rust. Les nuls consomment les quotas et appliquent les pertes sans transférer Or, Glwaare ni prisonniers.
- Villages créés à la première tranche admissible, selon le plafond du leader, avec garnison calculée aux prix du preset et recharge horaire. Aucun village initial. Ils apparaissent en losanges sur le même plan que les joueurs, à leur palier de Glwaare et à la valeur de leur garnison. Ils restent hors du classement des joueurs.
- Espionnage payant : le Scripteur ne reçoit que le rapport Waar (Or, effectif total, Glwaare, moral haut/bas), en plus de son état et des événements qui le concernent.
- Reddition automatique après neuf défaites **consécutives en défense** : −30 Glwaare, prisonniers libérés et moral à 100. L'Ascenseur possède une phase de reddition durant laquelle il ne lance plus d'attaques, faute de quoi sa propre attaque casserait la série.
- Prétendance Rwaa à partir de 50 Glwaare, avance stricte, attribution après 24 contrôles complets. Axe x : Or investi dans l'armée active, sans blessés à l'infirmerie.

## Heuristiques initiales à calibrer

Les chiffres ci-dessous appartiennent aux **politiques v1**, pas aux règles de Waar : Grenouille prépare son offensive pendant 75 % des ticks ; Rageux peut répondre trois fois ; Fermier attaque jusqu'à trois fois son village ou ses frigos du début ; Scripteur estime la valeur adverse à partir de l'effectif espionné et du coût unitaire moyen du preset, puis attaque si sa valeur est au moins 1,5 fois cette estimation et si le pillage espéré couvre une estimation de 3 % de sa propre valeur. L'Ascenseur bascule en raid à 2 000 Or d'armée, recherche sa reddition si sa valeur tombe sous 40 % de son pic avec au moins 10 Glwaare, puis reconstruit jusqu'à 60 % du pic (minimum 2 000 Or). Ces seuils sont provisoires et versionnés par `decisionVersion` dans le manifeste.

## Limites visibles de cette tranche

La portée d'espionnage est figée à **±10** (défaut du code hôte ; valeur effective de production non vérifiée). Le cron quotidien, les lois du Rwaa, les modes, les alliances, la protection achetable, les trophées et les modificateurs saisonniers ne sont pas encore simulés. Les effets détaillés de moral sur le combat ne sont pas reliés au preset Rust. Les taxes Rwaayales et les lois économiques restent à intégrer. Les runs sont conservés dans le répertoire temporaire du serveur ; l'export JSON est nécessaire pour les garder. Cette tranche ne doit donc pas servir à conclure sur l'équilibre d'une ère Waar complète.

## Vérification numérique locale

Avec le preset par défaut, cinq comptes, seed 42 et 24 ticks, la boucle produit 78 combats. Sur ce poste Windows, elle a pris 3,02 s avec un processus Rust par combat et 2,29 s en réutilisant le processus JSONL, avec le **même SHA-256 des frames** (`e726d0b9fe55eb67f19709a58fdf4a50d6b23c443caef37670f5786748e7da7e`). Ce résultat mesure la boucle Bagaar locale complète sur cet exemple, pas le débit isolé du moteur ni une garantie sur 60 jours ou sur le VPS.
