# Bagaar — joueur Lua

Dans Bagaar, cocher « Ajouter Le comptable » remplace Quentin par un compte
piloté par le script Lua affiché dans l'éditeur. Les 23 autres comptes et le
plafond de 24 comptes restent inchangés. Le script est enregistré dans le
navigateur pour l'édition, puis figé dans chaque nouvelle ère. Le manifeste
contient son SHA-256 et l'export de l'ère contient le texte exact. Modifier
l'éditeur ne change pas une ère en cours.

Le script définit `function next(observation)` et renvoie une action ou `nil`.
Il peut définir `function after_combat(observation)` pour une réaction immédiate
et les chaînes `goal` et `method` pour la fiche du joueur. Un nouvel appel à
`next` suit chaque action, jusqu'à 16 actions par tick. `observation.attempts`
liste les actions déjà proposées pendant ce tick, y compris les refusées.
Le processus Lua est relancé à chaque requête de calcul : les variables
globales ne sont pas un stockage durable.

L'observation donne `tick`, `totalTicks`, `spyRange`, `rwaa`, `candidate`,
`self`, `targets`, `reports`, `events`, `costs` et `attempts`. `targets` ne
contient que l'identité, le nom public, le type et la Glwaare. Les armées,
l'Or et le moral des adversaires n'apparaissent que dans `reports` après
espionnage. `events` contient les vingt derniers combats du compte.

Actions possibles : `mine`, `hospital`, `recruit` avec `units`, `heal`, `spy`
ou `attack` avec `target`, `surrender`, `autoSurrender` avec `enabled`. Les
actions passent par les mêmes règles PHP que celles des profils intégrés.
Une action inaccessible est refusée et inscrite au journal. Un script invalide
ou trop lent interrompt le calcul avec son erreur ; l'ère déjà enregistrée
reste disponible, mais il faut créer une nouvelle ère pour changer le script.

L'exécution se fait dans un processus Lua séparé sans accès aux bibliothèques
de fichiers, réseau, modules ni commandes système. Le source est limité à
16 Kio ; chaque appel est limité en instructions et en temps, et le processus
à 128 Mio de mémoire et 3 secondes CPU par requête. La suite de déploiement
exerce un démarrage et un tick réels dans l'image Docker finale.
