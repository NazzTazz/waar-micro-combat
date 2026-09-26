# Handoff UX — HUD progressif des combats de la soufflerie

## Intention et périmètre

Donner une preuve lisible du comportement du moteur pendant que les combats
s'accumulent, sans attendre un rapport final. Cette proposition décrit une
présentation de résultats **descriptive** : le HUD ne fixe aucun objectif de
recherche, ne choisit aucun candidat et n'approuve aucun réglage.

Une exécution compare les quatre monotypes à budget plafonné à **30 000 Or**,
puis deux armées libres A et B à leurs **budgets réels**. Chaque confrontation
se joue **200 fois dans chaque sens**, en quatre vagues de 50. La même météo
s'applique aux deux camps ; ses effets sont ceux du profil métier, sans réglage
individuel dans ce HUD. Le plafond de combat retenu est de 20 rounds.

Les coûts entiers laissent parfois de l'Or non dépensé. Afficher le budget
annoncé et le coût réellement engagé : 3 000 soldats (30 000 Or), 428 lanciers
ou archers (29 960 Or), 54 chevaliers (29 700 Or) avec les coûts du profil de
référence. Recalculer ces effectifs si les coûts du profil changent.

## Surface principale

Trois prototypes figés utilisent exactement les mêmes résultats réels de la
campagne `X-simplex`, au budget effectivement mesuré de 12 000 Or et avec
4 000 combats agrégés par orientation :

- la [Rose de combat](visuals/rose-combat-campagne-12000.svg) transforme les
  douze confrontations orientées en une silhouette polaire et place les quatre
  miroirs au centre ;
- les [rubans aller-retour](visuals/rubans-combat-campagne-12000.svg)
  privilégient la lecture détaillée des six paires et de leurs changements de
  rôle ;
- la [matrice triangulaire](visuals/matrice-combat-campagne-12000.svg) offre le
  balayage le plus compact des seize orientations.
- le [Relief tactique](visuals/relief-tactique-campagne-12000.svg) extrude les
  seize orientations autour du plan d'équilibre : tours rouges pour l'avantage
  attaquant, volumes bleus pour l'avantage défenseur, sans interpolation entre
  les quatre types d'unités.

Ces prototypes servent à comparer les grammaires visuelles, sans remplacer le
périmètre 30 000 Or décrit ci-dessous.

Une matrice **4 × 4** : ligne = monotype attaquant ; colonne = monotype
défenseur. Chaque case est coupée par la diagonale descendante :

- triangle **haut gauche rouge** : part de victoires de l'attaquant ;
- triangle **bas droit bleu** : part de victoires du défenseur ;
- diagonale neutre : part de matchs nuls, écrite si elle est non nulle.

Les deux taux de victoire ont le même dénominateur, le nombre de combats
achevés pour cette orientation ; avec les nuls, leur somme vaut 100 %. Une
case symétrique de l'autre côté de la diagonale de la matrice montre les mêmes
types dans l'autre rôle. Une case diagonale compare un type à lui-même : les
deux séries « dans chaque sens » y sont agrégées, soit **400 combats finaux**.

À côté, un cartouche **A attaque B / B attaque A** reprend exactement les
mêmes triangles pour les armées libres, avec les noms A et B visibles dans
chaque sens. Il affiche aussi leurs effectifs et leurs coûts engagés. Ne pas
mélanger les résultats des deux orientations dans un pourcentage unique.

## Couleur et lecture progressive

La **vivacité** de chaque triangle suit son propre taux de victoire : 0 % est
très pâle et 100 % est la couleur pleine. L'échelle reste fixe entre toutes les
cases et les quatre vagues. Rouge signifie toujours « attaquant » et bleu
toujours « défenseur » ; les couleurs ne désignent jamais les camps A/B.

La quantité d'observations a un indicateur séparé : `50/200`, `100/200`, etc.
Les cases diagonales portent `100/400` après la première vague. Une bordure ou
une trame légère marque le résultat provisoire. La couleur ne doit pas aussi
coder la progression : une case pâle doit signifier peu de victoires, pas peu
de combats. Un état « aucun combat » distinct précède le premier lot.

Après chaque vague, tous les taux affichés sont recalculés sur **l'ensemble
des combats déjà reçus**, sans lissage artificiel. Une brève transition de
couleur peut aider à repérer le changement, mais les chiffres prennent
immédiatement leur valeur vraie. Les nuls ne sont pas comptés comme des
victoires d'un camp. Si les deux triangles sont pâles, le taux de nuls sur la
diagonale évite de confondre ce cas avec une case vide.

À 50 observations par orientation, un taux voisin de 50 % a une marge
indicative de ±14 points à 95 % ; à 200, environ ±7 points. Montrer
`provisoire` et l'effectif, sans formulation de verdict. Au besoin, afficher
l'intervalle au survol. La matrice montre un comportement dans **ces
compositions, cette météo et ce budget**, pas une supériorité universelle.

## Détail au survol, au clic et au clavier

Chaque case ouvre un panneau lisible sans dépendre du survol : clic/toucher,
Entrée ou Espace au clavier. Il contient :

1. Les deux compositions, leurs effectifs, coût engagé, météo commune et sens
   d'attaque ; pour les armées libres, préciser A et B.
2. `n` combats, victoires attaquant / nuls / victoires défenseur en nombres et
   en pourcentages, rounds moyens et état provisoire ou terminé.
3. Pour chaque camp : morts, blessés et prisonniers moyens par combat, avec
   la part de l'effectif initial ; perte de valeur si elle est utile au joueur.
4. Lorsque pertes brutes et pertes livrées diffèrent, les libeller clairement
   plutôt que de présenter des catégories comprimées comme des morts physiques.

Les valeurs numériques et les étiquettes textuelles restent disponibles sans
perception du rouge et du bleu. Les seize cases sont de vrais éléments
focalisables ; un résumé textuel de la case sélectionnée évite de faire lire
toute la matrice à chaque mise à jour. Annoncer la fin de chaque vague dans
une zone de statut discrète, sans annoncer chaque case individuellement.

## Cadence et états

La matrice contient 6 paires de types distincts et 4 miroirs. Les duels
monotypes représentent **4 000 combats** ; A/B en ajoute **400**, soit
**4 400 combats**. Chaque vague complète représente **1 100 combats** :
50 dans chacune des 12 cases non diagonales, 100 dans chacune des 4 cases
diagonales et 50 pour chacun des deux sens A/B.

Afficher en premier lieu le squelette de la matrice, puis la première vague
complète, puis les vagues 2 à 4. Afficher `vague 1/4` et le nombre de combats
achevés. Si une partie de la vague tarde, conserver les cases déjà terminées
avec leur propre `n`, sans fabriquer un état complet. À la fin, marquer
`4 400/4 400` et l'heure du résultat.

Une saisie modifiée rend l'exécution précédente obsolète : conserver
éventuellement son image atténuée avec la mention « résultat à recalculer »,
mais ne jamais fusionner ses lots avec la nouvelle. Une erreur sur un lot
marque seulement les cases concernées comme incomplètes et permet une reprise
sur les mêmes plages d'itérations. Un bouton d'annulation arrête les lots
encore en attente. Le lancement de la trame complète est explicite ; le petit
duel automatique actuel peut rester un retour rapide distinct.

## Contrat de réalisation

**Rust** résout les combats. **PHP** construit les orientations, réserve des
plages de graines disjointes pour les vagues, valide les réponses et agrège
les compteurs et les conséquences. **JavaScript/HTML** affichent uniquement
les données fournies ; ils ne résolvent aucun combat, ne déduisent aucun
objectif et ne décident d'aucun résultat acceptable.

Le service actuel `/api/duel-summary` renvoie une réponse complète de 50
combats par sens ; il ne pousse pas quatre états progressifs. Il faut donc un
contrat de progression (identifiant d'exécution, numéro de vague, cellules,
effectifs cumulés, état et signature de configuration), puis un canal de
livraison au navigateur. Un job serveur avec consultation fréquente de son
état suffit pour une exécution de quelques secondes ; un flux SSE est possible
si la pile HTTP le permet. Regrouper les scénarios d'une vague dans peu
d'appels Rust évite de payer un lancement de processus par case. Limiter le
travail à deux slots sur le VPS.

La précédente estimation à 30 000 Or donne environ **0,7 s de calcul** pour
la première vague et **2,7 s** pour l'ensemble avec les lots de mesure.
Ce ne sont **pas** des latences écran validées pour les lots de 50. Mesurer
sur le VPS le délai clic → premier tableau complet, les mises à jour suivantes,
la fin, et les mêmes délais sous occupation d'un slot. Publier médiane et
95e percentile avant de promettre un temps de réponse dans l'interface.

## Critères de réception UX

- Une case peut être interprétée sans légende mémorisée : ses rôles, ses taux,
  les nuls et `n/total` sont accessibles par le panneau de détail.
- Après chaque vague, les taux et les pertes correspondent aux seuls combats
  achevés ; le dernier état correspond à 4 400 combats si les budgets et
  scénarios prévus sont valides.
- La couleur reste comparable entre cases et vagues, y compris pour les nuls,
  les miroirs et les changements de sens.
- Les changements de configuration, les annulations et les erreurs ne peuvent
  pas injecter des résultats périmés dans la nouvelle trame.
- La matrice et le détail restent utilisables au clavier et au toucher.
