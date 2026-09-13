# Reprise de l’ergonomie — créer, essayer, puis affiner un profil

12 septembre 2026. Mémoire de la discussion produit avec Tristan, après la
reprise du chantier métier et l’intégration des rapports Legacy dans `main`.
Ce document conserve l’intention et les fondations constatées ; il ne décrit
pas une fonctionnalité déjà livrée ni une spécification d’implémentation complète.
Les futures décisions, critères d’acceptation et travaux seront suivis dans
des issues GitHub, avec une branche et une PR par feature.

## Pourquoi changer l’entrée dans l’outil

Les autres administrateurs et développeurs de Waar peuvent avoir une solide
expérience du développement d’entreprise sans partager le goût de Tristan pour
les mathématiques et l’exploration détaillée d’un moteur de combat.

L’objectif premier est qu’un administrateur du jeu, développeur de préférence,
comprenne immédiatement le champ ouvert par le nouveau moteur pour équilibrer
le gameplay. Le parcours doit rendre les leviers de design concrets : choisir
des caractéristiques et des personnalités, éprouver leurs effets, puis savoir
ce que l’on souhaite affiner. La simplicité de prise en main sert cette découverte.

La présentation doit leur permettre de comprendre rapidement le moteur en
créant quelque chose : définir vaguement leurs unités, leur donner une
personnalité, puis éprouver leur profil d’armée. Ils ont déjà « joué à designer »
avant d’aborder les objectifs quantitatifs. Une matrice brute, les contrats de
recherche et les ellipses ne sont pas des prérequis à cette première expérience.

Le besoin porte sur l’accès aux capacités existantes. Il ne justifie ni une
réécriture du moteur en cleanroom, ni une nouvelle infrastructure d’orchestration.

Le Legacy ne définit pas la cible du nouveau gameplay. Les comparaisons déjà
produites restent historiques ; elles ne sont ni un passage obligatoire du
parcours ni un objectif implicite de fidélité. Les attentes viennent du profil
et des objectifs formulés par l’administrateur.

## Direction visuelle : un mode tutoriel dans la soufflerie

Tristan souhaite intégrer ce parcours comme un **mode tutoriel de la soufflerie**.
Il juge sa présentation actuelle trop austère, tout en conservant la valeur du
dessin des ellipses. La reprise ergonomique doit rendre l’entrée accueillante et
permettre d’apprendre en manipulant son propre profil moteur.

Le mode tutoriel met au premier plan les unités, leurs relations, les réglages
et le combat-test accessible à tout moment. Les explications accompagnent les
actions. Les six repères guident la découverte sans devenir des onglets classiques
ni un parcours obligatoire à terminer avant de pouvoir essayer.

L’affinage expert conserve le **dessin des ellipses** pour exprimer les zones
attendues et la **représentation des vecteurs** pour examiner les écarts et calibrer
le profil. Ces outils sont introduits avec leur sens : ce qui est observé, ce
qui est souhaité et ce que représente chaque origine et extrémité de vecteur.
Le tutoriel n’exige pas leur maîtrise préalable ; l’expert peut accéder directement
à l’affinage sans refaire la découverte guidée.

Les deux usages appartiennent à la même soufflerie et partagent le même profil
versionné. « Aller à la soufflerie » devient donc, dans l’interface finale,
une invitation à **passer à l’affinage** dans cet outil, avec ses réglages conservés.
L’export reste utile pour sauvegarder ou échanger un profil ; aucun aller-retour
manuel de fichiers ne doit être nécessaire pour changer de mode.

## Parcours retenu : six repères, navigation libre

Cette formulation remplace le premier découpage en quatre moments. Les six
repères proposent un ordre de découverte, sans imposer un tunnel séquentiel.
Ils fonctionnent comme des sections accessibles librement, mais Tristan ne veut
pas d’une présentation sous forme d’onglets classiques. L’habillage du mode
tutoriel doit être agréable et donner envie d’explorer ; sa composition précise
reste à concevoir.
On peut rejoindre une section, conserver ses réglages et ses compositions,
modifier un choix puis refaire un essai, sans valider toutes les sections précédentes.
Un retour n’impose ni remise à zéro ni nouvel export/import. Les résultats
précédents restent identifiés comme issus de l’ancienne configuration.

1. **Caractéristiques statiques des unités**, une à la fois : soldat, lancier,
   archer, chevalier. Attaque, structure et profil de rôle sont présentés simplement ;
   le rôle défensif, neutre ou offensif correspond à l’efficacité en défense.
   Des valeurs initiales évitent la page blanche ; leur choix reste à fixer.
   Chaque fiche comporte également la case **« Cette unité peut être capturée »**,
   pour exprimer son éligibilité à la création de prisonniers.
2. **Comportement croisé** : définir les contres désirés et leur intensité par
   des relations lisibles entre unités. La matrice de contres est construite
   derrière l’interface et reste cachée dans le parcours courant. La traduction
   numérique doit pouvoir être expliquée sans obliger à manipuler cette matrice.
3. **Météo** : configurer les effets des conditions météo sur chaque unité.
   Tout est neutre par défaut : laisser cette étape telle quelle ne modifie
   aucune efficacité. Le profil conserve ces règles ; l’essai choisit la condition
   météo appliquée au combat. Les caractéristiques affectées restent à définir.
4. **Paramètres de combat** : régler notamment le nombre de rounds, la compression
   des pertes en sortie et le taux de prisonniers. Ces réglages font partie du
   profil moteur, avec une explication de leurs conséquences. La compression
   proportionnelle et son arrondi inférieur sont définis ci-dessous. Le taux de
   capture va de 0 à 10 % des survivants éligibles du vaincu après compression ;
   les valeurs initiales restent à préciser.
5. **Combat instantané** : composer deux armées et éprouver le profil courant
   dans les deux sens, A attaque B puis B attaque A. **Le combat-test est disponible
   à tout moment**, depuis toutes les sections : le numéro 5 n’est pas un verrou
   d’accès. La modale décrite ci-dessous fournit cette surface d’essai.
6. **« Bravo, tu as configuré le moteur. Passer à l’affinage ? »** : récapituler
   et sauvegarder le profil, puis proposer de l’affiner dans la soufflerie ou de
   revenir aux réglages. Le message reconnaît la création du profil, pas sa
   validation comme équilibrage satisfaisant.

Le rôle et le contre sont des paramètres du moteur, pas une promesse de victoire.
L’efficacité en défense modifie la puissance de l’unité quand elle défend ; elle
ne crée pas une classe de comportement supplémentaire. Les contres sont dirigés :
un bonus de dégâts de X vers Y n’implique pas automatiquement un bonus inverse.

## Unités capturables et création de prisonniers

Complément demandé par Tristan : l’éligibilité à la capture est un choix de
design par unité, exprimé directement dans le tunnel. La prise en charge visée
inclut la création de prisonniers ; la case ne doit donc pas rester une simple
décoration sans effet. Ce choix doit suivre le profil lors de la sauvegarde,
du retour aux réglages et de son passage vers la soufflerie.

« Peut être capturée » exprime une possibilité, pas une capture automatique.
Un facteur global **« Pourcentage de prisonniers »** est configurable à l’étape 4,
sur la même page que les rounds et la compression. Sa tirette est bornée entre
**0 % et 10 %, inclus** ; le service PHP doit également rejeter les valeurs hors
de ces bornes, même si elles contournent l’interface.

Tristan fixe la population concernée : **les survivants du camp vaincu après
compression des pertes**, uniquement pour les types marqués capturables dans
leur fiche. L’éligibilité se configure par unité aux premières étapes, tandis
que le taux est commun. Les survivants du vainqueur et les unités non capturables
ne sont pas concernés.

L’ordre est : résolution à pleine échelle et détermination du vainqueur,
compression des pertes avec arrondi inférieur, puis calcul des captures sur
les effectifs restants du vaincu. Pour chaque type, la population restante avant
capture est l’effectif initial moins les pertes appliquées, et non le nombre
de survivants brut retourné avant compression. Les unités capturées sont retirées
des effectifs libres ; elles ne sont pas comptées une deuxième fois comme pertes.

Exemple sans ambiguïté d’arrondi : sur 1 000 soldats capturables du camp vaincu,
1 000 pertes brutes compressées à 8 % donnent 80 pertes et 920 survivants avant
capture. Au taux de 10 %, cela donne 92 prisonniers et 828 soldats libres.
Pour une unité non capturable dans la même situation : 0 prisonnier et 920 libres.

Précision PO du 13 septembre 2026 : **tous les arrondis de ces conséquences
en effectifs sont inférieurs**, pour les pertes compressées comme pour les
prisonniers. Le calcul est effectué par type d’unité, sans redistribution des
fractions restantes entre types ni tirage aléatoire d’arrondi.

Pour un type capturable du camp vaincu :

`prisonniers = floor(survivants après compression × taux de capture / 100)`

Ainsi, 19 survivants éligibles à 10 % donnent 1 prisonnier et 18 unités libres.
Un type non capturable ou appartenant au vainqueur donne toujours 0 prisonnier.

La valeur initiale des cases et du taux et le pas de la tirette restent à préciser.
Le traitement d’un éventuel résultat nul doit
aussi être explicite si cette politique est proposée, puisqu’il n’y a pas de vaincu.

Le moteur est dédié à Waar : « jeu hôte » n’est plus la frontière sémantique
retenue pour ce parcours. La distinction utile est entre **calculer les
conséquences du combat**, captures comprises, et **les appliquer aux données
persistantes de Waar**. Le [contrat historique](waar-micro-combat-contract.md)
décrivait une répartition différente ; le résolveur actuel ne calcule pas encore
de prisonniers. Il faut faire évoluer explicitement ce contrat et les services
concernés. Les essais doivent pouvoir montrer les conséquences calculées
sans créer de prisonniers persistants dans le jeu réel. Le résultat doit
distinguer explicitement les captures et leur relation avec les pertes et les
survivants, pour éviter tout double comptage.

Les fondations de combat sont réutilisables, mais cette capacité est une
extension métier à définir et tester, pas seulement un ajout ergonomique.

## Météo et compression des pertes

Les effets météo sont des règles du profil moteur ; la météo sélectionnée est
une condition de l’essai. Les deux sens du duel doivent afficher cette condition
et permettre une comparaison compréhensible. Modifier une règle météo invalide
la correspondance entre le profil courant et les résultats déjà affichés.
Limiter initialement les effets à la puissance d’attaque a été évoqué comme
piste, mais n’a pas été arrêté par Tristan.

La **compression des pertes en sortie** est proportionnelle : le moteur résout
le combat à pleine échelle, puis le coefficient configuré réduit les pertes
brutes pour chaque type d’unité et chaque camp. Tristan fixe un **arrondi inférieur**.

`pertes appliquées = floor(pertes brutes × pourcentage configuré / 100)`

Avec un coefficient de 8 %, 100 pertes brutes donnent 8 pertes appliquées,
50 donnent 4, et 7 donnent 0. À 100 %, les pertes brutes sont conservées.
Il ne s’agit pas d’écrêter toutes les pertes au-dessus d’un seuil. Les rounds,
la dynamique interne et le vainqueur sont calculés avant cette compression,
sans être recalculés à partir des pertes réduites. La restitution distingue
le résultat brut et les conséquences appliquées. Les captures interviennent
ensuite sur les survivants éligibles du vaincu, selon la règle ci-dessus.

Cette règle métier est distincte de la dilation visuelle Legacy ×20 des anciens
rapports. Elle doit être calculée en PHP, couverte par des tests et enregistrée
dans le profil, sans modifier les références historiques.

## La modale d’essai libre

Une action permanente ouvre la modale, même avec un profil encore incomplet.
L’utilisateur peut composer ses armées et demander un essai sans avoir terminé
le parcours. On valide la possibilité de réaliser le combat demandé, pas le
nombre de sections visitées.

Deux colonnes : **camp A à gauche, camp B à droite**. Chaque camp dispose de quatre
tirettes de quantité, une par unité. Une saisie numérique complémentaire permet
de choisir précisément les effectifs.

Le coût de construction de chaque camp s’actualise pendant les réglages, selon
les coûts du profil courant. Ce calcul immédiat sert à composer les armées ; les
combats ne sont déclenchés que par le bouton **« Simuler les deux sens »**.

Le même profil moteur est utilisé pour les deux camps. La simulation présente
séparément **A attaque B** et **B attaque A**, en conservant l’identité des armées
lors de l’inversion. La météo utilisée est explicite. Le vainqueur, les
survivants/pertes et les prisonniers par unité permettent
une lecture rapide. L’objectif est de rendre perceptible, par exemple, ce que
change le fait de défendre avec ses lanciers plutôt que de les faire attaquer.

Un résultat ponctuel doit être identifié comme tel. Si l’essai repose sur un lot,
son nombre de répétitions doit être explicite : on ne présente pas un combat
unique comme un taux de victoire fiable. Ce choix de sampling reste à spécifier.

## Souplesse de navigation, validation précise des essais

Un brouillon incomplet est un état normal de création. Il reste modifiable et
conservé lors des déplacements. L’état « brouillon » doit être distinct d’un
profil prêt à simuler et d’un profil exportable vers la soufflerie.

Si le combat est impossible, un overlay explique concrètement pourquoi, par
exemple : **« Combat impossible à réaliser : l’unité Archer n’a pas été
configurée. »** Une action permet de rejoindre la fiche concernée, puis de
retrouver les armées et de relancer l’essai. Les autres réglages sont préservés.
Les causes connues doivent être présentées ensemble plutôt que découvertes
une par une à chaque clic. La fermeture de l’overlay reste possible au clavier.

La validation autoritative appartient au PHP. L’interface peut anticiper les
erreurs mais ne doit ni inventer des paramètres manquants ni afficher un résultat
pour une configuration rejetée. Une météo laissée aux valeurs neutres est valide,
même si sa section n’a jamais été visitée. La règle pour une unité non configurée
mais absente des deux armées reste à fixer explicitement : le catalogue actuel
exige les quatre profils, ce qui ne doit pas devenir une contrainte ergonomique
implicite sans examen.

La solidité des tests fait partie de cette souplesse. Les issues devront couvrir :

- Navigation dans un ordre quelconque, retours répétés et conservation de tous
  les champs et compositions ; ouverture du combat-test depuis chaque section.
- Profils incomplets ou invalides : unité manquante, valeurs hors bornes,
  quantités négatives/non entières, camps vides et limites de calcul selon les
  règles retenues ; erreur PHP explicite avant simulation.
- Correction depuis l’overlay, fermeture accessible, nouvelle tentative réussie
  et absence de perte de saisie ; données invalides également rejetées quand
  elles sont envoyées directement au service sans passer par l’interface.
- Résultats associés au profil, aux armées, à la météo et à la seed utilisés ;
  une réponse tardive ne remplace pas le résultat d’une demande plus récente.
  Modifier un réglage signale les résultats précédents comme à recalculer.
- Inversion A/B correcte, coûts cohérents, reproductibilité et conservation des
  effectifs selon les règles de pertes/capture ; absence de mutation persistante
  des armées du jeu pendant les essais.
- Sauvegarde/reprise des brouillons et profils complets, puis export vers A
  soumis à son propre contrat, indépendamment de la liberté de navigation.

## Les deux sorties : A et B

**A — « Partir de ce profil et affiner ».** L’utilisateur considère que son
intention mérite d’être travaillée. Une version identifiable du profil est
sauvegardée puis transmise au mode d’affinage de la même soufflerie, sans ressaisie.
Ce transfert est interne au parcours et ne nécessite pas de manipulation de fichiers.
Les confrontations
monotypes sont préparées à partir de ce profil ; viennent ensuite les mesures
répétées, les zones attendues en taux de victoire et pertes, puis une recherche
bornée de candidats lorsque les objectifs sont prêts.

Ce passage n’est ni une acceptation des objectifs, ni une validation d’équilibrage,
ni une autorisation de lancer automatiquement une recherche longue. Les résultats
et objectifs de cette nouvelle expérience restent distincts des archives T24–T34.

**B — « Revoir mon profil ».** Retour au début du wizard avec les valeurs
conservées. L’utilisateur ajuste les unités ou leurs personnalités, puis rejoue.
Revenir ne signifie pas réinitialiser. Une modification doit rendre explicite
que les anciens résultats concernent la version précédente du profil.

La frontière produit est donc : **exprimer et essayer une intention**, puis,
si elle convient, **préciser et mesurer les résultats attendus**. Le dessin des
zones appartient à A, après le premier essai ludique.

## Fondations réutilisables dans le dépôt

Constat effectué sur le code intégré à `main` (`6842b48`). Les liens ci-dessous
désignent les composants existants, pas des fonctionnalités du wizard déjà disponibles.

| Besoin | Fondation existante | Raccordement restant |
| --- | --- | --- |
| Attaque, structure, efficacité en défense, coût | [UnitProfile](../src/Experiment/UnitProfile.php), [UnitCatalog](../src/Experiment/UnitCatalog.php) | Fiches guidées, valeurs initiales, bornes et traduction des rôles. |
| Profil moteur et contres dirigés | [ExperimentVariant](../src/Experiment/ExperimentVariant.php), [CombatRuleset](../src/CombatRuleset.php) | Éditeur de personnalités et sauvegarde/reprise du profil. La sérialisation existe ; la gestion interactive des profils reste à construire. |
| Éligibilité à la capture et prisonniers | [Contrat historique des conséquences](waar-micro-combat-contract.md) | Nouvelle propriété et taux à conserver dans le profil, calcul des captures et application aux données de Waar. Aucun calcul de prisonniers n’est actuellement fourni par le résolveur. |
| Météo, rounds et compression en sortie | [CombatRuleset](../src/CombatRuleset.php), [CombatResolver](../src/CombatResolver.php) | Nombre de rounds déjà représenté ; règles météo configurables et compression métier à spécifier et raccorder. |
| Composer et résoudre un duel | [UnitCatalog::prepareArmy](../src/Experiment/UnitCatalog.php), [PreparedBattle](../src/PreparedBattle.php), [CombatResolver](../src/CombatResolver.php), [BattleResult](../src/BattleResult.php) | Service applicatif pour les deux sens, validation des entrées et présentation A/B. |
| Mesurer avec des seeds déterministes | [ExperimentDefinition](../src/Experiment/ExperimentDefinition.php), [ExperimentRunner](../src/Experiment/ExperimentRunner.php) | Contrat de sampling de l’essai libre et création d’une nouvelle expérience depuis le profil sauvegardé. |
| Préparer les monotypes et afficher les zones | [run-monotype-objectives.php](../bin/run-monotype-objectives.php), [MonotypeObjectiveOverlayBuilder](../src/Experiment/MonotypeObjectiveOverlayBuilder.php) | Construction du corpus correspondant au nouveau profil ; aucun recyclage implicite du corpus historique ou de ses budgets. |
| Éditer, importer et exporter des objectifs | [acceptance-overlay-app.js](../resources/acceptance-overlay-app.js), [acceptance-zones-model.js](../resources/acceptance-zones-model.js) | Continuité du parcours et provenance des nouveaux objectifs. L’axe actuel de survivants doit être présenté ou converti explicitement si l’interface parle de pertes. |
| Chercher des candidats et comparer les résultats | [BoundedMonotypeCandidateSearch](../src/Experiment/BoundedMonotypeCandidateSearch.php), [MonotypeSearchSpace](../src/Experiment/MonotypeSearchSpace.php), [FinalistComparisonBuilder](../src/Experiment/FinalistComparisonBuilder.php) | Vérification de compatibilité du nouveau profil et espace de recherche explicitement défini. |

La recherche T30/T31 actuelle exige **15 paramètres ouverts : 12 champs d’unité
et exactement trois contres dirigés**. On ne peut donc pas promettre une recherche
sur n’importe quel graphe de contres dessiné dans le wizard sans examiner ce contrat.
Le mode de création et son passage vers A devront rendre cette limite explicite
ou faire l’objet d’une adaptation dédiée, sans changer les références historiques.

Les rapports HTML actuels sont autonomes et descriptifs. Une page statique seule
ne peut pas appeler le moteur PHP à chaque clic : le raccordement interactif
(par exemple une petite interface locale vers PHP) reste à choisir. Les combats,
validations métier et évaluations demeurent dans les services PHP testables.
Le navigateur peut afficher le coût courant, mais le serveur doit revalider
effectifs, paramètres et coûts avant simulation. Aucun besoin de Symfony,
Doctrine, base de données ou dépendance Legacy n’est établi pour ce premier palier.

## Points à fixer dans les issues d’implémentation

- Profil initial et coûts : garder les coûts actuels est une piste évoquée,
  pas une décision PO définitive ; aucune modification automatique du coût en
  fonction de l’attaque ou de la structure n’a été demandée.
- Bornes et pas des réglages, correspondance des trois rôles aux coefficients,
  graphe de contres autorisé et intensités disponibles.
- Météo : conditions disponibles, caractéristiques affectées et combinaison des
  effets ; neutralité par défaut et condition explicite dans chaque expérience.
- Paramètres de combat : bornes et défaut des rounds et du pourcentage de
  compression.
- Sauvegarde et reprise : identité/version, stockage local ou export/import,
  et traitement des modifications après un essai ou un export vers A.
- Capture : valeur initiale par unité et du taux global, pas de la tirette
  et traitement des éventuels nuls. Tester les arrondis inférieurs par type,
  notamment les fractions sur petits effectifs, les bornes 0/10 %, l’exclusion
  du vainqueur et des types non capturables, la population après compression et
  la conservation effectif initial = pertes appliquées + prisonniers + effectifs
  libres. Définir la restitution en simulation et l’application
  unique aux données de Waar, ainsi que la compatibilité des profils historiques.
- Essai libre : plafonds d’effectifs, cas des camps vides, seed, répétitions,
  durée maximale et lecture des résultats. Aucun budget de calcul n’est fixé ici.
- Export A : coûts et budget des monotypes, politique de départage, aléa, nombre
  de rounds et sampling conservés ou explicitement définis ; provenance du profil,
  du corpus et des objectifs ; conversion pertes/survivants.

Il n’est pas nécessaire de résoudre toute la recherche avancée pour démontrer
la création d’un profil et les essais dans les deux sens. En revanche, on ne
présentera pas un bouton d’export comme opérationnel avant que son contrat soit
raccordé et vérifié. Cette note n’autorise ni nouvelle recherche, ni activation
du moteur dans le jeu réel, ni modification des objectifs PO existants.

## Reprendre le travail

Le prochain support d’implémentation est une issue GitHub fondée sur cette
intention, avec un périmètre testable et ses choix numériques explicites.
La discussion, les décisions, la PR et les preuves vivent dans GitHub.
Ce document reste la mémoire du « pourquoi » ergonomique, sans journal d’agent
ni succession de handoffs.
