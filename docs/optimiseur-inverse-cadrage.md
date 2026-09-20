# Étape suivante : des intentions de gameplay vers un ruleset

Statut : proposition de cadrage, non implémentée. Priorité immédiate : permettre
aux co-développeurs d'examiner le moteur et de formuler leurs objectifs dans la
soufflerie. Aucune modification de gameplay n'accompagne ce document.

## Problème à résoudre

Les ellipses décrivent des régions acceptables de victoires et de pertes pour
les deux camps des 16 confrontations. L'utilisateur demande un ruleset cohérent
qui satisfait cet ensemble, et une explication lorsque ses demandes se
contredisent. Une succession de corrections indépendantes des paramètres ne
répond pas à cette intention.

L'explorateur existant combine déjà mutations et croisements sur 28 dimensions.
Il reste une référence de comparaison ; augmenter son budget ou le renommer ne
suffira pas à réaliser cette étape.

## Architecture à expérimenter

1. **Formaliser les contraintes.** Séparer paramètres libres, invariants,
   priorités et tolérances. Vérifier les complémentarités des victoires, le rôle
   des nuls et les contraintes impossibles à satisfaire simultanément. Les
   objectifs restent ceux du concepteur, jamais ceux déduits d'un candidat.
2. **Apprendre une réponse globale du moteur.** Mesurer un plan initial couvrant
   les variations conjointes, puis construire un modèle de substitution des
   observations des deux camps. Conserver les interactions entre unités et
   paramètres, ainsi que l'incertitude de ce modèle. Choisir sa famille après
   un benchmark : le moteur comporte arrondis, seuils et changements de régime.
3. **Chercher conjointement des régions faisables.** Utiliser ce modèle pour
   proposer des profils complets. Réserver les simulations coûteuses aux profils
   prometteurs ou aux régions où une nouvelle mesure réduit une incertitude
   importante. Adapter le nombre de répétitions aux comparaisons difficiles.
4. **Traiter les ambiguïtés.** Plusieurs rulesets peuvent produire les mêmes
   monotypes. Éviter les compensations arbitraires (attaque contre structure,
   précision contre puissance) en fixant des références et en pénalisant la
   complexité ou l'écart au profil choisi. Présenter plusieurs compromis utiles
   plutôt qu'un unique « meilleur » score opaque.
5. **Valider hors apprentissage.** Garder des graines indépendantes et des armées
   mixtes hors des données utilisées pour chercher. Mesurer la robustesse aux
   petites variations de profil, budget, effectifs et météo avant de proposer un
   résultat. Des monotypes réussis ne prouvent pas l'équilibrage global.

Le backend effectue calcul, apprentissage, classement et diagnostics. Le
navigateur affiche les mesures et les choix du concepteur. Aucun candidat n'est
automatiquement approuvé et aucun objectif n'est déplacé pour améliorer le score.

## Critère de décision avant une grosse implémentation

Construire un petit benchmark reproductible avec cibles atteignables générées
par des profils connus, contraintes volontairement incompatibles et cas à forts
effets croisés. À budget de **combats réellement exécutés** identique, comparer
au moteur évolutif actuel : taux de contraintes satisfaites sur graines réservées,
violations résiduelles, coût, stabilité et qualité du diagnostic d'infaisabilité.

La première livraison doit montrer un gain sur ce benchmark avant d'investir
dans l'interface de pilotage. Prévoir ensuite travaux asynchrones, annulation,
checkpoints et reprise : le calcul ne doit pas dépendre d'une requête HTTP ouverte.
