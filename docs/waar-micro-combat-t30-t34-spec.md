# Specs pour Sol — micro-moteur, tranches T30 à T34

9 septembre 2026. Rédaction demandée par Tristan après acceptation de T29/T29c.

## Objet et statut

Livrer progressivement la recherche de paramètres, la comparaison visuelle,
la vérification de stabilité et l'observation des compositions mixtes.
Ce document spécifie les travaux ; il ne constitue pas leur livraison.
Les bornes de T30 ci-dessous restent une proposition de recherche à examiner
dans le livrable T30, pas des valeurs d'équilibrage déjà approuvées.

Chaque tranche fournit une commande reproductible, ses artefacts, des tests
proportionnés et un relais `docs/waar-micro-combat-tNN-relay.md`.
Consigner la contre-recette avant de déclarer la tranche acceptée. Sol peut
préparer une tranche suivante, mais ne doit pas présenter un résultat dépendant
d'un contrat encore ouvert comme validé. Ne pas regrouper T30 à T34 en une seule livraison.

Le travail reste dans le paquet PHP autonome `packages/waar-micro-combat/` et
ses outils/rapports. Ni intégration Legacy, ni base de données, ni modification
du moteur 3.1/Rust, ni activation dans un jeu dans ces cinq tranches.

Références à lire :

- [Périmètre et démarche en deux passes](waar-micro-combat-scope.md).
- [Contrat des 32 objectifs](waar-micro-combat-objective-contract-correction.md).
- [Cadrage des paramètres proposé en T28](waar-micro-combat-t28-framing.md).
- [Contrat du résolveur](waar-micro-combat-contract.md).
- [T29/T29c et réserve levée](waar-micro-combat-t29-astra-review.md).

La phrase historique « T24 […] à budget égal » du document de correction des
objectifs ne doit pas conduire à modifier T24 : le cadrage PO en deux passes
préserve explicitement ses compositions et ses budgets, même inégaux.

## Invariants communs

1. Entrée de recherche :
   `var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json`.
   Exactement 32 zones `survivors`, actives et confirmées. Garder leurs centres,
   rayons, identifiants et provenances. Ne pas régénérer les cibles depuis un candidat.
2. Expérience de départ :
   `packages/waar-micro-combat/experiments/t28-defender-tie-break.json`.
   Seize confrontations ordonnées, deux camps observés, budget de 400 400 par camp.
3. Coûts fixes : Soldat 80, Lancier 110, Archer 130, Chevalier 350.
   Effectifs monotypes fixes : 5 005, 3 640, 3 080 et 1 144 respectivement.
4. Témoin T28 immuable ; candidat initial `roles-a@t28.0` conservé comme repère.
   Départage `defender`, trois rounds, dispersion `0.1` et formules du moteur fixes.
5. Recherche : 200 répétitions par scénario, `baseSeed = 42`, mêmes seeds de
   combat pour tous les candidats. La seed de génération des candidats est distincte.
6. Réutiliser `CanonicalMonotypeSearchObjectiveEvaluator` et la pénalité T29c.
   Avec `b = 1 + 1e-12`, excès nul si `q <= b`, sinon
   `(q - b) / (sqrt(q) + sqrt(b))`. Perte = moyenne des 32 excès, poids égaux.
7. L'acceptation stricte reste `32/32 objectifs ET zéro nul`. La moyenne, le
   nombre atteint et le pire excès sont trois informations distinctes. Ne pas
   ajouter de score économique, de score structure ou de fidélité Legacy.
8. Aucun élargissement automatique des bornes, déplacement des cibles ou ajout
   de mécanique pour faire réussir une recherche. Un échec de recherche ne
   prouve pas l'impossibilité mathématique des objectifs.
9. Réutiliser le résolveur PHP autoritaire. Toute optimisation doit conserver
   les résultats et être justifiée par une mesure ; pas d'accélérateur dans le
   périmètre initial. Ne jamais dupliquer les règles de combat en JavaScript.

## Contrat des artefacts et de la reproductibilité

Les noms de commandes ci-dessous sont des interfaces à livrer, pas des commandes
déjà disponibles. Sol peut adapter un nom à une convention existante, mais le
relais et le README doivent donner la commande exacte exécutable.

- Chaque exécution reçoit un répertoire de sortie explicite ; refuser une sortie
  non vide, sauf mécanisme de reprise explicitement implémenté et vérifié.
- Conserver les rapports T24 à T29c. Écrire les nouveaux résultats sous
  `var/waar-micro-combat/tNN-<run-id>/`.
- Copier les entrées utilisées et publier leurs SHA-256 : expérience, objectifs,
  espace de recherche, variante candidate et, à partir de T33, plan de validation.
- Distinguer identifiant du corpus et identifiant d'exécution. Un nouveau candidat
  ne doit pas exiger de réancrer les objectifs ; vérifier les compatibilités par
  le contrat existant, sans désactiver ses contrôles de provenance.
- Exporter la variante complète attendue par `ExperimentVariant`, pas seulement
  un vecteur de coefficients. Attribuer un identifiant/version propre au candidat.
- Résultats déterministes à entrées, runtime supporté et seeds identiques.
  Séparer date, durée et machine dans les métadonnées d'exécution pour qu'elles
  ne rendent pas les empreintes de résultats artificiellement variables.
- Publier les nombres de variantes évaluées et de combats réellement exécutés.
  Un scénario produit deux observations de camps, pas deux combats. Préciser
  séparément le coût du témoin si celui-ci est rejoué ou mis en cache.
- Une erreur de calcul/entrée invalide donne un échec explicite. Un calcul achevé
  sans candidat satisfaisant est un résultat exploitable, avec statut explicite,
  pas une erreur technique ni une acceptation.

## T30 — Espace de paramètres inspectable

### Livraison

Un manifeste versionné décrivant chaque paramètre ouvert : chemin stable, unité,
valeur initiale, minimum, maximum, pas de quantification. Ajouter le validateur,
un rapport lisible des valeurs ouvertes/figées et une commande de contrôle sans
recherche, par exemple `bin/validate-monotype-search-space.php`.

Proposition de départ issue de T28 : **15 paramètres**, bornes inclusives de
0,5 à 2 fois leur valeur initiale. Tableau à matérialiser dans le manifeste :

| Paramètre | Initial | Minimum proposé | Maximum proposé |
|---|---:|---:|---:|
| Soldat attaque | 7 | 3,5 | 14 |
| Soldat structure | 18 | 9 | 36 |
| Soldat efficacité défensive | 1 | 0,5 | 2 |
| Lancier attaque | 8 | 4 | 16 |
| Lancier structure | 32 | 16 | 64 |
| Lancier efficacité défensive | 1,45 | 0,725 | 2,9 |
| Archer attaque | 18 | 9 | 36 |
| Archer structure | 10 | 5 | 20 |
| Archer efficacité défensive | 0,35 | 0,175 | 0,7 |
| Chevalier attaque | 30 | 15 | 60 |
| Chevalier structure | 45 | 22,5 | 90 |
| Chevalier efficacité défensive | 1,1 | 0,55 | 2,2 |
| Contre Lancier → Chevalier | 1,5 | 0,75 | 3 |
| Contre Archer → Lancier | 1,35 | 0,675 | 2,7 |
| Contre Chevalier → Archer | 1,4 | 0,7 | 2,8 |

Identifier les contres par le couple dirigé, jamais par la position dans un
tableau. Les autres cellules restent neutres à 1. Ne pas les ouvrir implicitement.

Le manifeste doit rendre visible que ces plages permettent un facteur de contre
inférieur à 1, donc une inversion du bonus initial. Elles ne garantissent pas
non plus à elles seules les intentions de rôle. Présenter ce point dans la
recette T30 pour arrêter l'espace réellement utilisé en T31, sans ajouter de
contraintes de rôle cachées au score. Si une borne change, versionner le manifeste.

Représenter les valeurs en décimal compatible avec le fixed-point existant
(six décimales au maximum). Quantification proposée : `0.001`, explicite dans
le manifeste et appliquée avant l'identification/déduplication d'un candidat.
Vérifier l'absence de dépassement numérique aux extrêmes ; une valeur illégale
est rejetée, jamais tronquée ou corrigée silencieusement.

### Recette

- Le candidat initial est légal et son aller-retour conserve toutes ses valeurs.
- Minima/maxima légaux acceptés ; valeurs hors borne, non finies, chemins inconnus,
  paramètres dupliqués et modifications d'un champ figé rejetés.
- Aucun changement de témoin, coût, scénario, objectif, rounds ou dispersion.
- Tester la quantification et l'identité d'un contre après réordonnancement.
- Export du manifeste, rapport des 15 paramètres et statut des bornes proposées.
- Aucun optimiseur ni recherche coûteuse en T30. La contre-recette arrête le
  manifeste de recherche ; les règles d'acceptation T29c restent inchangées.

## T31 — Première recherche bornée et reproductible

### Livraison

Une commande telle que `bin/search-monotype-candidates.php`, prenant explicitement
l'expérience, les objectifs, l'espace T30, la seed de recherche, le budget
d'évaluations et la sortie. Le relais fournit une commande courte de recette.

Commencer par une stratégie simple et documentée : échantillonnage global puis
perturbations locales du meilleur candidat, ou autre stratégie sans nouvelle
dépendance justifiée par Sol. Décrire exactement distribution, ordre, pas,
traitement des bornes et arrêt. Ne pas revendiquer d'optimum global.

Deux budgets initiaux : `smoke = 8` et `standard = 128` évaluations de candidats
uniques, candidat initial inclus. Les deux utilisent les 200 répétitions canoniques.
La seed du générateur de recherche vaut 314159 par défaut. Une option permet
un autre budget explicite ; aucun lancement prolongé automatique après échec.

Évaluer l'initial en premier. Dédupliquer après quantification et exclure les
identifiants/libellés de la clé des paramètres. Borner aussi le nombre de
propositions, par défaut à dix fois le budget d'évaluations, pour qu'un espace
saturé de doublons ne crée pas de boucle infinie. Publier ces deux compteurs.

Classement de recherche : perte continue croissante ; égalité exacte départagée
par l'empreinte canonique des paramètres. Ni nombre d'objectifs ni pire écart ne
remplace silencieusement la fonction T29c. Conserver séparément :

- le meilleur exploré et sa progression ;
- jusqu'à trois finalistes distincts sans nul, selon le même classement ;
- les candidats strictement satisfaisants, s'il en existe.

Le candidat initial reste un comparateur disponible même s'il sort des trois
finalistes. Tout nul avec la politique `defender` déclenche un diagnostic
d'invariant et ne peut être présenté comme un finaliste valide. Un candidat
avec 0/32 reste exportable comme résultat exploratoire, clairement étiqueté.

Le témoin peut être calculé une fois pour la recherche, avec une clé de cache
qui inclut variante, corpus, seeds et répétitions. Un chemin plus compact doit
être testé contre `ExperimentRunner` sur les métriques utilisées et les vainqueurs.

### Artefacts et recette

- `search-plan.json`, entrées figées, `search-result.json`, `evaluations.jsonl`,
  variantes complètes des finalistes, évaluations T29c et `report.md`.
- La progression indique le nombre évalué, la meilleure perte, le nombre
  d'objectifs atteints, le pire écart et le temps écoulé.
- Même commande rejouée : mêmes candidats et résultats hors métadonnées de temps.
- Tests : respect du budget, doublons, borne de propositions, arrêt, départage
  déterministe, meilleur conservé, export/import et absence de mutation des sources.
- Recette `smoke` puis une exécution `standard`, avec temps et machine publiés.
  Ne pas exiger une amélioration artificielle pour faire passer les tests :
  l'initial doit rester disponible et le meilleur ne peut avoir une perte supérieure.
- Rejouer les finalistes par le runner autoritaire et retrouver leurs évaluations.
- État final explicite : recherche achevée, interrompue ou en erreur ; distinguer
  « objectif strict atteint » et « aucun candidat satisfaisant trouvé dans ce budget ».

## T32 — Comparaison visuelle des finalistes

### Livraison utilisable par Tristan

Un `report.html` autonome ouvrable localement, alimenté par les vrais artefacts
T31, sans CDN. Réutiliser le renderer et ECharts embarqués ; ne pas créer une
nouvelle application Symfony ou un nouveau service pour cette livraison.

Le rapport présente d'abord l'initial et le meilleur candidat, puis permet de
choisir parmi les trois finalistes. L'identité du candidat et le statut du run
restent visibles. Une exécution partielle ne ressemble pas à un résultat complet.

- Sélection parmi les 16 confrontations ; attaquant et défenseur distingués
  par couleur ET forme, avec légende et sélection au clavier.
- Afficher les observations, les deux ellipses PO et le déplacement initial →
  candidat. Libeller la référence des flèches ; le témoin neutre T28 est un
  comparateur distinct, optionnel, pas un substitut de l'initial.
- Vue globale de l'état des 32 objectifs et accès direct aux objectifs hors zone.
- Axe X = taux de victoire. Axe Y sélectionnable : survivants, valeur économique,
  structure. Sur monotypes, économique montre les mêmes objectifs que survivants ;
  structure reste sans ellipse cible.
- Au survol/focus : valeurs exactes, nombre de simulations, état de l'objectif,
  excès et contribution à la perte. Tableau accessible fournissant les mêmes données.
- Résumé : perte, objectifs atteints, pire objectif, nuls et contrôles stricts.
  Un candidat ne reçoit jamais le libellé « accepté » sur la seule base de sa moyenne.
- Lecture seule des objectifs de l'expérience. Pour changer le design PO, utiliser
  l'éditeur existant, exporter un nouveau document et lancer une expérience distincte.
- Télécharger variante complète et évaluation du candidat sélectionné, avec
  provenance. Aucun calcul de combat ni inférence de résultat dans le navigateur.

### Recette

Commande de génération, chemin exact à ouvrir et petit parcours manuel dans le
relais : choisir une paire, changer de candidat, comparer les camps, changer Y,
ouvrir le pire objectif et exporter la variante.

Tester l'affichage avec un seul candidat, des points superposés, tous les objectifs
atteints et aucun atteint. Vérifier que changer Y/candidat ne déclenche pas de
simulation, ne duplique pas les objectifs et ne modifie pas les JSON d'entrée.
Tests du modèle de présentation et vérification navigateur réelle sur les sorties
T31, à largeur bureau et 390 px ; aucune erreur console, libellés lisibles,
navigation clavier et exports rattachés au bon candidat. Livrer des captures.

## T33 — Stabilité sur des simulations réservées à la validation

### Livraison

Une commande de validation des finalistes **figés avant la première mesure T33**,
et un rapport JSON/Markdown accompagné de la comparaison HTML adaptée.
Conserver leur ordre T31 ; ne pas modifier leurs paramètres pendant cette tranche.

Le plan de validation est exporté avant exécution : SHA-256 des finalistes,
objectifs, corpus, répétitions et liste des seeds. Plan initial : **cinq lots de
1 000 répétitions par scénario et candidat**, seeds de base
`104729, 130363, 155921, 180749, 205759`.

Utiliser la dérivation des seeds du runner existant. Vérifier avant simulation
que les seeds dérivées ne recouvrent pas celles de la recherche pour un même
scénario, ni celles d'un autre lot. En cas de collision, produire un diagnostic
et un plan corrigé avant mesure, sans adaptation en fonction des résultats.
Les mêmes seeds restent appariées entre initial, témoin et finalistes.

Publier, pour chacun des cinq lots et pour leur agrégation : les 32 observations,
la perte, le nombre d'objectifs atteints, le pire excès, les nuls et les contrôles
stricts. Agréger les compteurs/numerateurs/dénominateurs, puis réévaluer les
ellipses sur les observations regroupées ; ne pas moyenner les statuts binaires
ou les pertes des lots pour fabriquer l'évaluation globale.

Afficher l'étendue observée des valeurs entre lots pour chaque objectif et le
nombre de lots où il passe. Appeler cette information « variation entre lots »,
pas « intervalle de confiance » ; aucun certificat statistique n'est livré ici.

Statuts descriptifs :

- `stable-sur-les-lots` : 32/32 et zéro nul sur chacun des cinq lots ET sur l'agrégat ;
- `variable-selon-le-lot` : au moins un objectif change d'état entre les lots ;
- `objectifs-non-atteints` : échec sans changement d'état entre lots ;
- `invariant-nuls-en-echec` : présence d'un nul, prioritaire sur les autres statuts.

Ces statuts décrivent ce plan de mesure, pas la garantie de tous les combats futurs.
Ne pas remplacer la définition binaire T29c. Une reprise de recherche inspirée
par ces résultats constitue une nouvelle expérience, avec un nouveau plan réservé
avant sa validation ; ne pas réutiliser ces mesures comme validation indépendante.

### Recette

- Tests de séparation des seeds, gel des candidats, agrégation indépendante,
  statuts stables/variables/en échec et correspondance du rapport aux entrées.
- Reproductibilité d'un lot et de son agrégation ; contrôle des nombres de combats.
- Les exports des finalistes et objectifs restent identiques avant/après mesure.
- Temps réel, résultats de chaque lot et limitations publiés ; aucun classement
  opportuniste qui masquerait l'ordre de recherche initial.
- Le rapport permet au PO de retenir un candidat, ou de demander une nouvelle
  expérience. Zéro candidat stable n'empêche pas de livrer honnêtement T33.

## T34 — Observation des compositions mixtes T24

### Livraison

Un rapport autonome comparant les finalistes gelés et le candidat initial sur
les scénarios du fichier
`packages/waar-micro-combat/experiments/t24-astra-vector-corrections.json`.
Montrer les statuts T33 à côté des candidats ; une observation T24 n'efface pas
un échec monotype. La visualisation reste possible même sans candidat stable.

Conserver exactement les identifiants, compositions et budgets de T24.
Ne pas égaliser ses budgets et ne pas utiliser le générateur monotype pour
reconstruire ce corpus. Préparer un manifeste T34 distinct avec les variantes
retenues, leurs coûts figés et leur politique `defender`.

Pour comparer l'effet des paramètres, rejouer l'initial T28 sur T24 avec la même
politique `defender` et les mêmes seeds que les finalistes. Les rapports historiques
T24 restent intacts ; s'ils sont affichés, identifier leur politique de départage
et ne pas attribuer aux paramètres une différence due à cette politique.

Premier plan d'observation : 1 000 répétitions par scénario, seed de base 32452843,
publié avec les entrées. Ce corpus et ses résultats ne sont lus par aucun chemin
de score, de classement ou de sélection automatique de T31.

### Rapport et recette

- Comparaison par scénario et camp, flèches clairement référencées, trois axes Y
  et valeurs exactes. En composition mixte, survivants et valeur économique peuvent
  diverger : calculer chacun depuis les résultats, ne pas les traiter comme alias.
- Aucune ellipse monotype transposée aux armées mixtes, aucun score d'acceptation
  T24 et aucun verdict de fidélité Legacy. Les éventuels résultats historiques
  sont uniquement des références explicitement identifiées.
- Publier victoires, pertes/survivants par type, structure restante, valeur
  économique restante, rounds et nuls. Signaler les renversements de vainqueur
  majoritaire et les plus grands écarts comme observations, sans seuil de rejet caché.
- Tests de conservation exacte des scénarios et budgets, séparation des camps,
  métriques d'une composition mixte et absence de branchement T24 dans l'objectif.
- Vérification navigateur sur les vrais artefacts : changements de candidat,
  scénario et axe, points superposés, valeurs exportées et lisibilité.
- Rapport JSON/HTML/Markdown, manifestes, empreintes, mesures de durée et parcours
  manuel de recette. Aucune modification des finalistes au cours de l'observation.

## Critère de fin et suite

T30 ferme l'espace de recherche ; T31 livre une recherche réelle et ses résultats ;
T32 les rend inspectables ; T33 mesure leur stabilité ; T34 montre leurs effets
sur les compositions mixtes. Chacune peut être acceptée techniquement sans que
le candidat satisfasse le design PO. Toujours distinguer ces deux verdicts.

Au terme de T34, livrer une synthèse avec candidats, objectifs atteints, stabilité,
observations mixtes et questions de gameplay restantes. La décision de retenir
un candidat appartient au PO. Une recherche supplémentaire reste une tranche
distincte si aucun candidat ne convient.

L'intégration Legacy, la projection des pertes vers l'infirmerie, l'application
transactionnelle des conséquences et l'activation versionnée seront spécifiées
séparément. Elles ne sont pas des critères cachés de ces livraisons autonomes.
