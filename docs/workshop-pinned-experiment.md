# Une référence fixe pour comprendre une confrontation

La vue d’ensemble conserve la matrice monotype. Le testeur choisit une case,
puis « Comparer mes réglages à cet état ». Ce clic fixe le profil, la
confrontation et la météo de référence. Les modifications suivantes sont
comparées à ce témoin, même après plusieurs changements ou le chargement
d’un autre profil. Parcourir une autre case ne déplace pas le témoin.

## Ce que la première tranche montre

- Pour chaque camp : effectif acheté, victoires, nuls, morts et blessés bruts,
  avec référence, valeur courante et écart. Les pertes sont rapportées à
  l’effectif initial du camp ; les différences de taux sont en points.
- Dans le détail : morts, blessés et prisonniers après compression et capture.
- Une fiche des mécanismes : dégâts par impact réussi, précision, frappes,
  structure cible et impacts nécessaires pour tuer une cible intacte.
  Elle distingue le rôle attaquant du rôle défenseur. Ce seuil local ne
  prédit pas le résultat global d’une bataille.
- Le dernier geste et les vingt derniers changements validés restent dans
  un volet secondaire. Un geste continu sur un curseur forme une entrée.

Le texte de comparaison concerne uniquement la confrontation épinglée. Il
distingue des victoires inchangées et des pertes modifiées. Il ne sélectionne
plus automatiquement trois confrontations à raconter, ne recommande pas
un profil et ne conclut pas à une amélioration statistiquement significative.

Les réglages modifiés depuis la référence et leurs changements mécaniques
sont affichés directement au-dessus des résultats, avec l’heure d’actualisation
de la comparaison. Un témoin encore identique aux réglages courants invite à
modifier un réglage ; un changement sans différence mesurée est identifié
séparément. Remplacer la référence est une action explicite qui remet les écarts
à zéro, pas un bouton nécessaire pour actualiser le calcul.

Exemple vérifié dans le navigateur sur Nazz-Equilibre-Test1 : Chevaliers attaquant
Soldats, attaque du soldat 9 → 8 puis coefficient défensif 1 → 1,25. Les tableaux
de pertes restent identiques sur les cinquante répétitions, mais les dégâts par
impact du soldat passent de 9 à 10 au total et son seuil contre un chevalier
intact de 28 à 25 impacts. La catégorie « blessé » ne décrit pas la gravité de
ses blessures. Les changements mécaniques sont donc utiles même lorsque les
nombres de morts et de blessés ne bougent pas.

## Contexte et calculs

La météo courante et le budget fixe de 400 400 par camp sont visibles. Un
changement de météo exige une remesure explicite du témoin dans ce contexte.
Après rechargement, le témoin est conservé dans le navigateur, mais ses
mesures doivent être renouvelées : aucune mesure ni ancienne explication
serveur n’est restaurée depuis le stockage local.

La matrice réutilise `MonotypeMeasurementService` et le batch Rust : seize
confrontations orientées × cinquante répétitions = **800 combats**. Les deux
camps donnent trente-deux lignes d’observation, pas trente-deux combats
distincts par répétition. Zones conserve ses cent répétitions (1 600 combats).
Une remesure explicite nécessite au plus deux batches de 800 combats ; le
cache de la page réutilise une mesure déjà disponible dans le même contexte.

Les calculs sont sérialisés par page, avec attente de 500 ms après saisie,
validation préalable, protection par révision contre les réponses tardives,
pause en onglet masqué et respect de `Retry-After`. Les mesures sont conservées
dans un cache mémoire limité à huit entrées.

PHP produit la comparaison et les grandeurs mécaniques. `MonotypeMechanics`
utilise les mêmes frontières d’arrondi fixe que le runtime ; un test ciblé
compare les dégâts à la trace Rust en attaque et en défense. JavaScript
présente ces données et ne résout aucun combat.

## Limites et tranches suivantes

Cette livraison couvre référence épinglée, comparaison et mécanismes. Le
balayage d’un paramètre et la matrice des écarts restent à réaliser. Les
cinquante répétitions constituent une observation locale ; il n’y a pas
d’estimation de significativité ni de bouton de confirmation renforcée.

L’examen existant à quatre variantes reste accessible explicitement. Ses
phrases portent sur le taux de victoire de l’attaquant ; les tableaux montrent
aussi les pertes brutes des deux camps. Il ne prouve pas une compensation
générale des paramètres. Son budget maximal reste de 3 200 combats.

Le cache vit le temps de la page ; une mise à jour du moteur pendant une
session ouverte exige un rechargement. La provenance actuelle du runtime
identifie le modèle et le transport, mais ne contient pas de hash du binaire.

## Recette de cette tranche

Avec le profil par défaut Test 2, épingler Soldats attaquant Lanciers, puis
passer l’attaque des soldats de 9 à 8. Les victoires restent à 0 % pour
l’attaquant, mais les morts bruts du défenseur passent d’environ 3,12 % à
0,57 %. Le seuil local des soldats passe de quatorze à quinze impacts. Ces
observations concernent les cinquante répétitions et ce contexte précis.

Vérifications effectuées :

- PHP : `MonotypeComparisonTest`, `ProfileFeedbackTest`, `WorkshopAdvancedTest`
  (11 tests, 425 assertions), dont contrôle des arrondis sur une trace Rust.
- JavaScript : `workshop-model`, `workshop-bench`, `workshop-ui`,
  `workshop-http`, `workshop-runtime`. La fausse horloge exécute les callbacks
  arrivés à échéance et supprime ceux qui sont annulés. Les scénarios couvrent
  saisies rapides, retour A/B/A, changements de vue, attente serveur, témoin
  stable, capture seule, météo et rechargement.
- Navigateur isolé, serveur PHP local et runtime Rust, à 1440 × 1000 et
  390 × 844 : épinglage, édition, tableau des pertes, fiche et restauration.
- Les quatre profils du corpus du 20 septembre passent la mesure native
  de 800 combats chacun, sans modification des profils. Le binaire local
  a dû être reconstruit : il avait encore l’ancienne limite de capture de
  10 %, contrairement aux sources actuelles qui autorisent 50 %.

Les campagnes de recherche longues T31/T33 ne font pas partie de cette recette.

## Intégration avec `main` — 25 septembre 2026

Historique vérifié : attente UX de l'issue #10 et PR #11, registre des attentes
produit, présent compte rendu, livraison des conséquences et du seuil de blessure
sur `main`. La matrice épinglée réutilise `MonotypeMeasurementService`,
`MonotypeComparisonService` et le batch Rust ; le seuil et la provenance des
conséquences doivent suivre le contrat actuel de `CohortRequestFactory`.
Le risque observé lors de la fusion était de conserver les observations et les
mécanismes de la PR tout en perdant le nouveau contexte de conséquences, ou
de tester avec un faux runtime qui n'expose plus la provenance requise.
La résolution conserve ces deux comportements et met le faux runtime à jour.
Elle n'ajoute aucune règle de combat et ne vaut pas acceptation produit.
