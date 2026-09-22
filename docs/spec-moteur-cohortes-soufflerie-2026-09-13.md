# Moteur Waar par cohortes et raccordement de la soufflerie

Spécification consolidée — 13 septembre 2026.

Statut : cible d'implémentation issue des arbitrages avec Tristan. Ce document
ne signifie pas que les capacités décrites sont déjà livrées. Il constitue la
spécification unique de cette évolution ; les issues et PR GitHub portent son
exécution et ses preuves, pas des contrats métier concurrents.

Convention de lecture : les décisions du §3 sont les arbitrages utilisateur. Les
mentions « proposition technique » précisent une solution de première livraison,
pas un nouvel équilibrage validé. Une limite d'intégration signalée dans ce document
ne doit pas devenir une fonctionnalité supplémentaire exigée avant de tester l'outil.

## 1. Résultat attendu et règle de conduite

Un administrateur configure les quatre unités de Waar, leurs contres, la météo
émulée et les règles de combat ; il teste deux armées dans les deux sens, comprend
les résultats, mesure les monotypes, dessine ses objectifs à la souris et recherche
des candidats. Le combat-test, les mesures et la recherche utilisent le même moteur
par cohortes, avec le batch Rust pour les études.

Il ne faut ni reconstruire la soufflerie, ni inventer un autre moteur, ni ramener
l'utilisateur à une comparaison obligatoire avec le Legacy. L'objectif est un
parcours utilisable de bout en bout, pas une collection de nouvelles infrastructures.

Principes de livraison :

- Trois grandes tranches définies au §15, chacune testable et démontrable.
- Pas de nouvelle série de handoffs, de sous-tranches numérotées ou de dispositif
  d'orchestration. Une issue et une branche par tranche cohérente ; PR relue avant merge.
- Les tests sont développés avec les changements, pas reportés à la dernière tranche.
- Aucun arbitrage métier supplémentaire ne doit être caché dans une adaptation de
  schéma, un défaut de formulaire ou une optimisation.
- Les points explicitement reportés ne bloquent pas la livraison et ne sont pas
  implémentés par anticipation.
- Une tranche n'est pas terminée sur la seule foi d'une suite de tests verte :
  sa démonstration prévue doit fonctionner avec les chemins réellement utilisés.

## 2. Fondations à conserver

| Fondation | Utilisation prévue |
| --- | --- |
| `engines/waar-cohort/` | Seul moteur à faire évoluer pour cette livraison : PHP de référence, Rust natif, batch, tests de parité. |
| `engines/waar-v3/` | Import historique conservé, manifeste et empreintes vérifiables. Ne pas modifier ces copies pour développer l'adaptation. |
| `public/workshop/` | Interface actuelle à raccorder et compléter, sans refonte visuelle générale. |
| `src/Workshop/DuelService.php` | Point d'entrée des deux sens de combat, à adapter au nouveau contrat. |
| `src/Workshop/MonotypeMeasurementService.php` | Fabrication des scénarios et présentation des mesures, à brancher au batch natif. |
| `src/Workshop/BoundedProfileSearch.php` | Recherche bornée existante, extérieure au moteur. |
| `src/Workshop/T27Editor.php`, ressources de l'éditeur | Édition des ellipses à la souris, axes et présentation experte à préserver. |
| Services de conséquences existants | Réutiliser validation et invariants utiles ; remplacer leur ancienne politique par celle du §9. |
| Moteurs et références historiques à la racine | Reproduction des anciens résultats ; ne pas les réécrire pour faire passer la nouvelle physique. |

État constaté avant cette spécification : l'adaptation possède les contres via
`attackFactor`, le ciblage proportionnel par défaut, l'extraball optionnel à zéro
et le coefficient défensif, avec parité PHP/Rust et batch testée. Elle conserve
encore les règles historiques de précision fixe, blessés, reddition et départage.
La soufflerie utilise encore `SingleTargetCombatResolver` : elle n'est pas branchée
sur cette adaptation. Les derniers arbitrages ci-dessous remplacent certains
choix décrits dans le README initial de l'adaptation.

La restriction historique du dépôt « pas de Rust » ne s'applique pas à ce chantier
explicitement demandé. Cela n'autorise pas l'import de Symfony, Doctrine ou d'une
base de données dans le chemin de calcul autonome.

## 3. Décisions actées, provisoires et reportées

### 3.1 Actées

- Quatre types : soldat, lancier, archer, chevalier ; identifiants existants conservés.
- Résolution par cohortes, pas de tableau d'état pour chaque combattant.
- Frappes simultanées entre les deux camps, à partir des effectifs du début du round.
- Ciblage proportionnel aux populations vivantes par défaut.
- Contres dirigés par multiplicateur de dégâts, sans modifier le ciblage.
- Coefficient défensif par type, appliqué aux frappes du camp défenseur seulement.
- Précision de base et amplitude d'aléa configurables par type en page Unités.
- Tirage uniforme de précision, une fois par type vivant, par camp et par round.
- Fractionnement en `N` frappes de puissance `attaque / N`, défaut `N = 1`.
- Ce fractionnement remplace l'extraball probabiliste dans le nouveau modèle.
- Suppression du multiplicateur d'attaque des blessés. La structure restante reste suivie.
- Le moteur reçoit et applique les modificateurs de chaque armée, avec provenance.
- La météo ne modifie que l'attaque et la précision de base ; jamais l'amplitude d'aléa.
- Reddition configurable sur le taux de morts brut, désactivée par défaut.
- Départage configurable : coût économique restant par défaut, ou proportion de
  structure restante. Égalité exacte au défenseur par défaut, match nul disponible.
- Rapport détaillé conservable par le jeu et rejouable sans consulter les services externes.
- Recherche de candidats extérieure au moteur.

### 3.2 Politique provisoire, isolée et versionnée

Captures parmi les blessés éligibles du vaincu, puis compression de toutes les
catégories hors indemnes. Les effectifs retranchés par compression redeviennent
indemnes. Cette politique est détaillée au §9 et doit pouvoir changer sans modifier
la résolution physique ou les anciens résultats bruts.

### 3.3 Explicitement reporté

- Nouvel arbitrage de la réallocation et compétences tactiques associées.
- Formations en carré, en ligne, etc., et ciblage granulaire au sein d'un même type.
- Optimiseur mathématique avancé proposant des candidats.
- Implémentation du vrai service météo, d'un arbre technologique ou de la persistance
  des combats dans l'application Waar.
- Nouvelle physique du sampler binomial hérité : ses approximations sont conservées,
  documentées et testées ; pas de remplacement opportuniste pendant l'intégration.
- Certification du chiffre historique de 70 000 combats/s : il n'est pas un résultat
  démontré pour cette nouvelle configuration.

## 4. Frontières de responsabilité

### 4.1 Services du jeu ou émulateurs de la soufflerie

Ils déterminent quels effets sont actifs pour chaque armée : formation, technologie,
météo, équipement futur. Ils produisent une liste explicite de modificateurs. Le
moteur ne consulte ni horloge, ni réseau, ni base de données, ni arbre technologique.

La soufflerie émule ce rôle ; elle ne pré-applique pas les coefficients en envoyant
ensuite les mêmes modificateurs au moteur. Une valeur de base reste une valeur de base.

### 4.2 Moteur

Il valide ses entrées, prépare les valeurs effectives, résout les rounds, détermine
l'issue brute et produit la télémétrie. Préparation et résolution appartiennent au
même contrat explicable, même si ce sont deux services internes purs distincts.

### 4.3 Politique de conséquences

Elle transforme un résultat brut en catégories utiles au jeu. Elle reçoit les
paramètres de compression/capture, ne modifie jamais le résultat brut et ne consomme
pas le flux aléatoire de la résolution. Elle est utilisable depuis les deux runtimes
ou reproduite à l'identique avec des tests de parité dédiés.

### 4.4 Soufflerie et futur optimiseur

Ils choisissent les scénarios et variantes à mesurer, appellent le moteur, évaluent
les observations par rapport aux objectifs et présentent des propositions. Le moteur
ne dessine pas de zones, ne propose pas de paramètres et n'approuve aucun candidat.

### 4.5 Interface

Elle édite les données, explique les résultats fournis et affiche les erreurs.
Elle ne résout ni combat, ni capture, ni score de candidat. Les calculs de coûts
prévisionnels du formulaire sont descriptifs ; les valeurs de référence du résultat
proviennent du backend.

## 5. Contrats et versionnement

### 5.1 Entrée d'un combat

Le contrat contient au minimum :

- `schemaVersion`, `resolutionModel`, `numericModel`, `stochasticModel` ;
- ruleset complet et son empreinte ;
- armée attaquante et armée défensive, avec leurs cohortes initiales ;
- liste des modificateurs de l'attaquant et liste de ceux du défenseur ;
- seed ;
- niveau de trace demandé ;
- paramètres et version de politique de conséquences si une sortie de jeu est demandée.

Les labels A/B sont indépendants des rôles. Pour B attaque A, échanger les armées
et leurs listes de modificateurs, pas seulement les libellés du rapport.

Les cohortes ont un type, un effectif entier et une structure restante par membre.
Elles sont regroupées selon les clés effectivement nécessaires à la résolution.
Aucune identité individuelle de soldat n'est créée. L'interface de première livraison
compose des armées indemnes ; le résolveur conserve sa capacité à traiter des blessés
initiaux pour les tests et usages explicites.

Un type non configuré mais absent des deux camps ne bloque pas un test instantané.
Un type présent et incomplet le bloque avec une erreur localisée. La mesure des
monotypes exige les quatre fiches complètes.

### 5.2 Sorties

Séparer explicitement :

1. `rawResult` : résultat physique, indépendant de la politique de conséquences.
2. `consequences` : projection versionnée destinée au jeu, si demandée.
3. `trace` : explication de la préparation et des rounds, selon le niveau demandé.
4. `provenance` : entrées figées, versions, seeds, empreintes.

Les résultats ne doivent pas réutiliser un identifiant de modèle prétendant reproduire
la physique individuelle précédente. Le format physique à six décimales et les règles
de quantification doivent être explicitement identifiés.

Un changement de trace seul ne doit pas modifier l'empreinte fonctionnelle du résultat
physique. Un changement de politique de sortie ne doit pas modifier son checksum brut.
Les labels purement descriptifs n'influencent pas le calcul.

### 5.3 Compatibilité

Versionner le nouveau profil, le modèle de résolution, la préparation des modificateurs,
le protocole aléatoire et la politique de conséquences. Les noms exacts peuvent suivre
les conventions du dépôt, mais une version doit distinguer cette cible de l'adaptation
intermédiaire `waar-cohort-v1`.

Les anciens documents restent lisibles dans leur contexte historique. Ne pas convertir
en silence un champ disparu en un autre : `randomSpread` de puissance n'est pas
l'amplitude d'aléa de précision ; `extraBallChance` n'est pas `strikesPerAttack`.

## 6. Paramètres

### 6.1 Fiche de chaque unité

| Champ logique | Sens | Défaut / validation |
| --- | --- | --- |
| `attack` | Budget d'attaque d'un combattant pour un round | Décimal positif ou nul ; conserver les valeurs du profil chargé. |
| `structure` | Structure d'un combattant indemne | Strictement positive. |
| `cost` | Coût de construction / remplacement | Entier strictement positif, modifiable dans le wizard. |
| `baseAccuracy` | Précision moyenne avant variation par round | Dans `[0,1]`. |
| `accuracySpread` | Demi-largeur de l'intervalle uniforme de précision | Dans `[0,1]`, zéro autorisé. |
| `strikesPerAttack` | Nombre de tentatives par combattant et par round | Entier `N >= 1`, défaut 1. |
| `defendingEfficiency` | Multiplicateur de dégâts lorsque le camp défend | Décimal positif ou nul, défaut 1. |
| `capturable` | Éligibilité du type à la capture | Booléen, conserver le profil chargé. |

Les qualificatifs offensif/neutre/défensif restent une présentation du coefficient
défensif. Ils ne changent ni le rôle du camp, ni le ciblage, ni les contres.

Les exemples `0,15 ± 0,02` ne constituent pas un équilibrage universel validé.
Pour compléter un ancien profil, proposition technique de migration : précision
`0,15`, amplitude `0`, fractionnement `1`, tous affichés comme nouveaux réglages
à confirmer. Ne pas reprendre les valeurs de la fixture historique en écrasant
l'attaque, la structure ou les coûts déjà choisis par l'admin.

### 6.2 Contres et ciblage

Une matrice dirigée `attackFactor[actingType][targetType]`, neutre à 1. La fiche de
relation du wizard reste la présentation courante ; pas de matrice brute obligatoire.
Conserver les règles de validation existantes de l'éditeur, dont la diagonale neutre.

Le facteur de contre n'augmente ni la probabilité de choisir cette cible, ni la précision,
ni le nombre de tentatives. Les préférences historiques peuvent rester archivées ou
accessibles au bas niveau pour compatibilité ; elles ne doivent pas être activées
implicitement dans le nouveau parcours.

### 6.3 Paramètres de combat

| Réglage | Valeur de départ |
| --- | --- |
| Nombre maximal de rounds | 3, réglable de 1 à 30 avec tirette et valeur numérique. |
| Reddition activée | Non. |
| Seuil de reddition | 20 % conservé comme valeur préparée, sans effet si désactivée. |
| Critère de départage | Coût économique restant. |
| Autre critère disponible | Proportion de structure restante. |
| Égalité exacte | Défenseur ; option match nul. |
| Compression de sortie | Valeur du profil, défaut de nouveau profil conservé à 8 %. |
| Taux de capture | Valeur du profil, défaut 0 %, intervalle 0–10 %. |
| Seuil de blessure | `combat.woundDamageThreshold`, défaut des nouveaux profils 20 %, affiché de 0 à 100 %. |

La variation globale de puissance `randomSpread`, le multiplicateur des blessés et
l'extraball sont absents du nouveau modèle et de ses formulaires. Un import qui les
contient exige un chemin de migration explicite, pas leur application cachée.

### 6.4 Bornes techniques

Conserver les limites déjà exposées pour l'attaque/structure, le coût, les facteurs,
les effectifs et les budgets de la soufflerie. Les valeurs effectives après modificateurs
doivent également être validées, avec vérification d'overflow avant toute simulation.

Nouvelle limite de travail proposée pour la première implémentation : `1 <= N <= 32`.
Il s'agit d'une borne technique, pas d'un équilibrage acté. La vérifier par les mesures
de fragmentation en tranche A ; toute modification doit être documentée dans l'issue,
commune au schéma, au PHP, au Rust et à l'interface. Ne jamais tronquer silencieusement.

Bornes de reddition proposées : 1–100 % lorsque l'option est activée ; utiliser le
booléen pour la désactiver plutôt qu'un seuil zéro ambigu. Compression : 0–100 %.

## 7. Modificateurs et météo

### 7.1 Description d'un modificateur

Chaque effet doit identifier sa source (`training`, `technology`, `weather`, etc.),
son identifiant stable, son libellé, les types concernés, le paramètre ciblé et son
coefficient. Les identifiants permettent de rejeter les doublons accidentels ; deux
effets distincts qui ciblent le même paramètre se composent.

La préparation doit pouvoir recevoir un effet non météo sur chaque paramètre de
l'unité, pas seulement sur deux champs codés pour le service météo. Contrat technique
proposé pour couvrir les types sans inventer de règles d'entraînement :

- `multiply` pour attaque, structure, précision de base, amplitude, coefficient
  défensif, coût et nombre de frappes ;
- pour le coût et le nombre de frappes, le résultat exact doit être un entier
  admissible ; une fraction est rejetée, pas arrondie silencieusement ;
- `set` booléen pour l'éligibilité à la capture ; deux effets fournissant des valeurs
  opposées sont rejetés comme conflit, sans priorité implicite liée à l'ordre de liste.

La météo n'a accès qu'aux deux cibles définies au §7.3, avec l'opération `multiply`.
Les effectifs de l'armée ne sont pas un paramètre de fiche d'unité : un modificateur
ne crée pas de soldats. Aucun arbre technologique ni catalogue complet de compétences
n'est nécessaire pour exposer et tester ce contrat typé.

Le jeu choisit les effets acquis ; le moteur ne calcule pas lui-même les niveaux
d'entraînement. Un type absent de l'armée peut avoir des effets sans créer d'unités.
Un identifiant de type ou de paramètre inconnu est rejeté.

### 7.2 Calcul et ordre de présentation

Pour un paramètre décimal :

`valeurEffective = arrondi6(base × coefficient1 × coefficient2 × …)`

Le produit doit être exact jusqu'à l'arrondi final : pas de multiplication flottante
non maîtrisée, ni de succession d'arrondis à six décimales. Utiliser une représentation
décimale/rationnelle exacte ou une stratégie entière prouvée ; les produits dépassant
les capacités admises sont rejetés proprement. Ce travail est effectué à la préparation,
pas pour chaque frappe.

Présentation humaine : base → formation/acquis → météo → valeur effective. Les
intermédiaires affichés peuvent être arrondis pour lecture, mais ne sont jamais
réutilisés comme entrées de calcul. Permuter des modificateurs multiplicatifs ne
change pas le résultat effectif.

La quantification finale reste à six décimales, au plus proche avec demi supérieur,
comme le calcul physique existant. Les arrondis inférieurs de conséquences sont
une règle différente (§9).

Après composition, valider le domaine du paramètre : précision et amplitude dans
`[0,1]`, structure strictement positive, etc. Un produit hors domaine est une erreur
explicite de préparation, pas une correction silencieuse des bonus. Le bornage de
l'intervalle de tirage du §8.2 traite une autre situation : un centre et une amplitude
valides dont l'intervalle dépasse les probabilités autorisées.

### 7.3 Météo

Référence de concepts : `../waar-sf/src/Services/MeteoService.php`. Le Legacy décrit
une unité affectée et une intensité ; ses libellés sont dérivés de cette combinaison,
pas d'un catalogue autonome parfaitement équivalent à nos presets.

Familles à retrouver dans l'émulation : beau temps/nuageux, froid/blizzard,
chaleur/canicule, vent/tempête, pluie/orages. Préserver le vocabulaire Waar, sans
copier ses probabilités horaires, son stockage Doctrine ou sa génération de prévisions.

Chaque preset de météo expose, pour chaque type d'unité :

- un coefficient d'attaque entre 0 et 1, neutre à 1 ;
- un coefficient de précision de base entre 0 et 1, neutre à 1.

Les effets sont neutres par défaut, même si le nom du preset évoque du mauvais temps.
Les valeurs restent un choix de design. L'amplitude n'est pas éditable dans un effet
météo ; le backend rejette un tel effet, même s'il est soumis sans passer par l'UI.
Les autres sources peuvent modifier l'amplitude, par exemple entraînement `×0,5`.

Exemple final : base `0,15 ± 0,02`, entraînement de précision `×1,20`, météo de précision
`×0,80`, compétence sur l'amplitude `×0,50` : moyenne `0,144`, amplitude `0,01`.
Il n'y a pas de coefficient météo sur l'amplitude.

Le service météo réel pourra produire les mêmes objets que l'émulateur. Sa mise en
production n'est pas un prérequis à cette livraison.

## 8. Résolution physique

### 8.1 Préparation

Valider le ruleset, les deux armées et leurs listes d'effets. Calculer séparément
les paramètres effectifs de chaque camp : le ruleset commun ne doit pas empêcher
un entraînement ou une autre météo spécifique à l'une des armées.

Pour les armées indemnes du wizard, la structure initiale provient de la structure
effective du camp. Ne pas modifier rétroactivement les états bruts archivés en
leur appliquant les paramètres d'un profil plus récent.

Pour des cohortes initialement blessées, le contrat doit distinguer maximum effectif
et structure restante. Une modification de structure ne doit pas provoquer de
guérison implicite à l'import : documenter cette limite et rejeter une combinaison
ambiguë plutôt que l'interpréter. La première livraison UI ne crée pas cette ambiguïté.

### 8.2 Précision uniforme par round

Pour chaque type vivant de chaque camp au début du round :

`bas = max(0, précisionEffective - amplitudeEffective)`

`haut = min(1, précisionEffective + amplitudeEffective)`

Tirer uniformément dans l'intervalle borné. Il s'agit de borner l'intervalle avant
tirage, pas d'écrêter un tirage dans l'intervalle non borné, ce qui créerait une
masse de probabilité aux extrémités. Près de 0 ou 1, la moyenne du tirage peut donc
différer du centre nominal ; le rapport montre l'intervalle réellement utilisé.

La précision tirée est commune à toutes les cohortes de ce type dans ce camp,
blessées ou indemnes, et à toutes leurs tentatives pendant le round. Elle est tirée
à nouveau au round suivant. L'amplitude n'est pas une seconde chance de frappe ni un facteur
de puissance d'attaque.

Un tirage de précision nul ou un intervalle réduit à un point ne provoque pas d'erreur.
Dans un intervalle réduit à un point, utiliser cette valeur sans tirage. Les types
absents au début du round n'ont pas de tirage. Le protocole du §8.6 fixe le reste.

### 8.3 Fractionnement et touches

Pour une cohorte de `C` membres et `N` frappes par attaque : `C × N` tentatives.
Puissance nominale d'une frappe : `attaqueEffective / N`.

Exemple : 100 chevaliers à 350, `N=5` → 500 tentatives à 70 ; pas 500 tentatives à 350.
Le nombre de touches est obtenu par le sampler agrégé existant avec la précision
tirée pour ce type/camp/round. Il n'est pas nécessaire de créer un objet par tentative.

Ne pas conserver en parallèle une génération probabiliste d'extraballs : ni champ
fonctionnel actif, ni tirage résiduel, ni nombre supplémentaire de tentatives caché.
L'ancien mécanisme reste uniquement dans les copies historiques.

La division de puissance est quantifiée à six décimales selon le modèle physique.
Le budget nominal est conservé aux erreurs de quantification près, qui doivent être
bornées et testées ; ne pas redistribuer arbitrairement un reste en créant des soldats
ou en imposant une puissance minimale à une frappe arrondie à zéro.

### 8.4 Ciblage, dégâts et blessés

Répartir les tentatives selon les populations vivantes en utilisant l'algorithme
par cohortes existant. Pas de retour à l'état individuel et pas de garanties inventées
sur des cibles individuelles distinctes.

Une frappe qui touche applique sa puissance fractionnée, le `attackFactor` du couple,
puis le coefficient défensif de l'unité qui frappe si son camp défend. Conserver et
documenter les étapes de quantification physique de l'adaptation ; elles sont distinctes
du produit des modificateurs à la préparation.

Les blessés ont exactement les mêmes paramètres offensifs que les indemnes du même
type/camp. Ils ont moins de structure restante, pas un bonus ou malus caché d'attaque.
Les survivants de même type et même structure restante sont regroupés. Suivre et
mesurer la fragmentation des cohortes sans introduire un plafond qui changerait
silencieusement le résultat.

Amendement du 21 septembre 2026, issue #14 : un survivant est classé blessé si et
seulement si `(Smax - Sreste) / Smax > woundDamageThreshold`, sur les entiers fixes
du moteur et sans arrondi d'affichage. `Smax` est la structure maximale effective
préparée pour ce combat. À la frontière exacte le survivant reste valide ; un seuil
de 0 reproduit le classement historique et un seuil de 1 ne classe aucun survivant
comme blessé. Ce classement alimente les états, agrégats, captures et conséquences,
sans restaurer de structure ni modifier frappes, ciblage, RNG, reddition ou départage.
Le ruleset JSON omet le champ pour les anciennes requêtes afin de préserver leurs
empreintes ; les nouvelles requêtes l'émettent explicitement sous forme décimale.

La réallocation estimée des tentatives non consommées reste celle du moteur hérité.
Avec le fractionnement, ses compteurs portent sur les tentatives de frappe, pas sur
un nombre supposé de chevaliers distincts. Identifier ce comportement dans le modèle
et la trace comme héritage provisoire. Ne pas le transformer en compétence tactique.

Les frappes des deux camps utilisent les effectifs du début du round. Une unité
tuée pendant l'échange n'est pas privée rétroactivement de ses tentatives de ce round.

### 8.5 Arrêt du combat et vainqueur

Ordre explicite après les deux actions simultanées :

1. Un seul camp éliminé : victoire de l'autre, indépendamment du critère de départage.
2. Deux camps éliminés : égalité exacte, appliquer la politique d'égalité.
3. Si reddition activée, comparer pour chaque camp `morts cumulés / effectif initial`
   au seuil, égalité de seuil comprise. Un seul camp atteint le seuil : il perd.
   Si les deux l'atteignent, utiliser le critère configuré puis la politique d'égalité.
4. Si la limite de rounds est atteinte, appliquer le critère configuré puis l'égalité.
5. Sinon, round suivant.

Un camp vide à l'entrée perd sans round ; les deux camps vides sont invalides. Le
wizard continue d'exiger deux armées non vides pour un essai normal.

Départage économique : somme `effectif vivant × coût unitaire effectif`. Les blessés
vivants comptent à leur coût unitaire entier ; il ne s'agit ni du coût perdu après
compression ni d'une valeur pondérée par les points de structure restants.

Départage structurel : structure restante totale / structure initiale totale de
chaque camp, comparée exactement sans tolérance flottante arbitraire. Pour des
entrées blessées, le dénominateur est la structure réellement engagée au départ.

La reddition ne transforme pas automatiquement les survivants en morts ou prisonniers.
Le vainqueur est déterminé avant la politique de conséquences et ne change pas si
l'admin modifie le taux de compression.

### 8.6 Déterminisme

Versionner le protocole des nouveaux tirages. Fixer l'ordre des types, des camps,
des rounds et des appels au sampler. La consommation aléatoire ne dépend ni de
l'affichage, ni du niveau de trace, ni de l'activation d'une exportation.

Proposition technique de protocole pour éviter un arbitrage implicite pendant le
portage : isoler le tirage de précision du flux des touches. Pour un round numéroté
à partir de 1, un rôle `attacker` ou `defender` et un identifiant de type existant,
former les octets UTF-8 suivants, avec `NUL` comme séparateur :

`waar-accuracy-uniform-v1 NUL seedDécimale NUL roundDécimal NUL rôle NUL type`

Prendre les quatre premiers octets du SHA-256, big-endian, puis masquer sur 31 bits.
Ce nombre initialise un LCG31 local. Transformer les bornes de précision en entiers
micro-unités `L` et `U`. Tirer uniformément un entier dans `[L,U]`, bornes incluses,
sans biais modulo : pour `m=U-L+1` et `b=floor(2^31/m)`, avancer le LCG jusqu'à un
état `s < b*m`, puis prendre `L + floor(s/b)`. Un intervalle constant ne consomme
pas de tirage. La loi uniforme est donc discrète à la résolution numérique du moteur.

Le flux LCG/binomial des touches et allocations reste celui de la résolution. Les
vecteurs de référence du nouveau sous-flux sont figés en tranche A et communs aux
deux runtimes. Aucune graine basée sur l'heure, l'ordre des threads ou un identifiant
de requête. Ce protocole technique peut être amendé explicitement avant gel de la
tranche A, mais pas remplacé différemment dans les deux implémentations.

Conserver la dérivation des seeds de scénarios existante pour les monotypes et les
plages d'itérations. Les nouveaux résultats peuvent changer à seed identique puisque
le modèle change ; ne pas modifier les oracles historiques pour masquer ce changement.

### 8.7 Amendement #16 — hasard adressé et binomiale couplée (22 septembre 2026)

Statut : correctif mandaté par le PO, implémenté et recetté localement sur
`fix/issue-16-addressed-rng`, base `55cfbe4`, soumis à contre-recette.
Ni acceptation, ni déploiement. Suivi : [issue #16](https://github.com/NazzTazz/waar-micro-combat/issues/16),
[PR #17 et preuves de livraison](https://github.com/NazzTazz/waar-micro-combat/pull/17).
Cet amendement remplace le flux partagé de §8.6 pour les nouvelles requêtes de
la soufflerie. Le protocole historique reste disponible et inchangé.

#### Identité et frontières

- Nouveau `stochasticEngineVersion` : `sha256-binomial-tree/1`.
- La requête porte `armyIdentities: {attacker: "A", defender: "B"}` ou
  l'inverse. Les identités sont obligatoires avec le nouveau protocole, distinctes,
  et suivent les armées lors de l'inversion des rôles. Elles ne sont pas déduites
  des effectifs, du profil, de la position d'une cohorte ou d'un hash de règles.
- Champs absents : exécuter `lcg31-binomial-normal-v1`, sans ajouter de champs
  aux anciens résultats. Version inconnue, identité manquante/dupliquée ou
  identité fournie au protocole ancien : erreur explicite.
- Batch : version au niveau de la requête, identités dans chaque scénario.
  Seeds, seedKey, plages, ordre des scénarios et budget restent inchangés.
  Dans les duels, A/B sont les camps de l'interface ; pour une mesure monotype
  autonome, A est le premier camp construit et B le second. Les deux sens du
  duel partagent déjà seedKey=0 et conservent cette dérivation.
- Le snapshot et le rejeu conservent version et identités. Le batch expose la
  version et les identités par scénario ; contextes de mesure et caches incluent
  le nouveau protocole. Aucun profil sauvegardé n'est réécrit.

#### Adressage des tirages

Un événement est identifié par les octets UTF-8 suivants, séparés par NUL :
`protocole, seed décimale, identité A/B, round décimal, type acteur, usage`.
Le round de sortie est 0. Les usages de combat sont `accuracy`,
`target/{vague}/{typeCible}`, `hit/{vague}/{typeCible}` et
`impact/{vague}/{typeCible}/{structureRésiduelleEnMicroUnités}`. La vague vaut 0
au premier passage et augmente lors d'une réallocation. Les usages de sortie
sont `consequence/capture`, `consequence/dead`, `consequence/wounded`,
`consequence/prisoners`. Chaque événement repart de son adresse : aucun compteur
global, aucun état conservé entre clics, aucune consommation d'un autre usage.

Les types gardent leur ordre canonique existant. Dans le nouveau protocole, les
compartiments sont triés par structure résiduelle croissante avant allocation ;
le chemin historique conserve son ordre d'insertion. La structure
résiduelle identifie un **compartiment de dégâts**, pas un soldat persistant.
Fusionner deux cohortes de même structure conserve ce compartiment ; une
structure différente est un événement différent. La correspondance promise
concerne les événements de mêmes entrées, pas une identité individuelle fictive.

Le ciblage reste multinomial séquentiel sur les populations vivantes et poids
actuels. Ses binomiales conditionnelles sont adressées par type cible. Les
impacts restent répartis entre compartiments proportionnellement à leurs
populations, puis par quotient/reste dans chaque compartiment, comme auparavant.
Les changements physiques de population, structure, poids ou réallocation peuvent
donc modifier les résultats en aval. Aucune règle de ciblage ou de dégâts n'est
remplacée par cet amendement.

**Correction de contre-recette #17 R1, 22 septembre 2026.** Les poids finis
strictement positifs restent valides, même très déséquilibrés. Pour chaque
binomiale conditionnelle du ciblage adressé, reprendre uniquement les types
encore à allouer : diviser leurs préférences par la plus grande préférence
de ce sous-ensemble, puis multiplier par les populations vivantes. Additionner
ces masses normalisées dans l'ordre canonique et diviser la masse du type
courant par ce total. Recalculer le sous-ensemble à chaque étape ; ne pas
obtenir le dénominateur suivant par soustraction du poids déjà traité.

Cette normalisation avant multiplication évite le débordement des produits
et de la somme ; le recalcul préserve les petits poids après retrait d'un poids
dominant. Au moins une préférence normalisée vaut 1, donc le dénominateur est
strictement positif et fini ; la probabilité reste dans `[0,1]`, y compris
quand aucune tentative ne reste. Le dernier type reçoit le reliquat exact.
Les rapports trop petits pour binary64 peuvent devenir nuls ; la quantification
à 52 bits du sampler reste applicable. Aucun écrêtage d'une probabilité invalide
ni borne supplémentaire sur les préférences n'est ajouté.

Le sampler `sha256-binomial-tree/1`, ses adresses et ses vecteurs sont inchangés.
Cette correction numérique du ciblage de la PR encore ouverte peut changer
l'arrondi des probabilités des poids personnalisés par rapport à `7c732de` ;
elle ne promet pas de reproduire ses allocations erronées. Le chemin historique
`lcg31-binomial-normal-v1` garde son arithmétique et ses replays, y compris ses
limites numériques préexistantes. Le cas de revue (`1e16`, `2.9`, `0.01`, seed 42,
un round) et les poids extrêmes sont couverts par
[les tests de ciblage PHP/Rust](../engines/waar-cohort/tests/AddressedTargetingTest.php).
La [contre-recette et sa reproduction](https://github.com/NazzTazz/waar-micro-combat/pull/17#issuecomment-5779002936)
restent la preuve historique du défaut.

Limite historique distincte constatée pendant cette correction : PHP et
`serde_json` n'encodent pas certains poids extrêmes de la même façon pour le
hash (`10000000000000000.0` contre `1e+16`, par exemple). Le contrôle de rejeu
PHP peut donc refuser un rapport Rust contenant ces poids. La sérialisation
des hashes n'est pas modifiée ici ; les tests extrêmes comparent tous les
champs sauf ce hash, et vérifient la répétition dans chaque runtime. Les tests
des rapports historiques ordinaires conservent leur comparaison intégrale.

#### Binomiale et couplage

Une simple réutilisation du BTRS à des probabilités différentes ne garantit pas
la monotonie. On emploie un arbre binaire de partitions de la population de
tentatives, sans créer d'individus :

1. À la racine, N=n. Tirer L ~ Bin(N, 1/2), R=N−L.
2. Le sous-arbre gauche représente les uniformes dans [0,1/2), le droit [1/2,1).
   Chaque nœud est adressé par son chemin de bits depuis la racine (`tree/`).
3. Pour compter les uniformes < p, ajouter les sous-arbres entièrement sous p
   et descendre uniquement dans le sous-arbre contenant la frontière.
4. Arrêter dès que N=0 ou que la frontière est une extrémité. La probabilité
   fournie est représentée par `floor(p * 2^52)` ; profondeur maximale 52,
   erreur de quantification < 2^-52. p=0/1 et n=0 sont exacts.

Le tirage à 1/2 réemploie la méthode binomiale de §9.5 (attentes géométriques
pour N/2<30, BTRS sinon), dans un domaine distinct par nœud. Aucune approximation
normale. Les mots uniformes restent les 52 bits de tête de SHA-256, transformés
en `(bits+0.5)/2^52`, compteur local au nœud, décimal sans zéro préfixé.
La variation de précision utilise un entier uniforme sans biais modulo sur les
bornes micro-unités inclusives, issu du seul domaine `accuracy`.

Justification : partitionner des uniformes indépendants en deux moitiés produit
une Bin(N,1/2), puis les partitions conditionnelles ont la même construction.
La somme des feuilles sous p est donc Bin(n,p) à la quantification et précision
numérique documentées. Pour une adresse et n fixes, augmenter p inclut uniquement
des feuilles supplémentaires : le nombre de réussites ne peut pas diminuer.
Cette représentation agrégée définit des ensembles emboîtés sans prétendre
exposer l'identité des combattants. Changer n ne promet pas une correspondance
individuelle ni la multiplication exacte d'une sortie par le facteur d'échelle.
Le coût attendu croît avec la profondeur utile de l'arbre, pas linéairement avec
des millions de tentatives ; il doit néanmoins être mesuré.

#### Conséquences et compatibilité

Nouvelle politique `wounded-capture-then-compress/4`, même ordre et mêmes règles
de capture/compression que /3, mais utilisant les identités A/B et l'arbre
binomial adressé. Elle exige le nouveau protocole physique ; celui-ci exige /4
lorsque les conséquences sont demandées. /2 et /3 restent disponibles avec le
protocole historique et leurs rapports rejouables à l'identique. La provenance
de /4 indique `sha256-binomial-tree/1`. Le classement des blessés, la reddition,
le départage et les effets spécifiques au défenseur restent inchangés.

#### Recette ciblée obligatoire

- Pièces jointes #16 : rejouer exactement les rapports historiques 25/30 %, dont
  7/11 blessés, puis localiser le premier décalage par un compteur du flux dans
  un harnais de diagnostic, sans changer les tirages historiques de production.
- Nouveau protocole : cycle 25→30→25, mêmes tirs archers et même état final
  lanciers dans ce cas sans morts ; réussites lanciers non décroissantes.
- Inversion des rôles avec A/B préservés : mêmes sous-flux et mêmes événements
  à entrées égales, y compris variation de précision et conséquences. Tester
  séparément un effet défensif non neutre pour conserver sa conséquence physique.
- Cas avec morts, simultanéité, blessés qui frappent, ciblage mixte, fusion des
  compartiments, traces none/full, batch entier et plages recomposées, ordre
  des scénarios et parité complète PHP/Rust, provenance et rejeu compris.
- Sampler : bornes, cas dégénérés, monotonie en p, petites lois calculées
  exactement, moyenne n*p et variance n*p*(1-p), seeds fixées à l'avance,
  échelles de 1 à plusieurs millions et frontières 59/60, 64/65. Tolérances
  statistiques fixées avant observation ; aucune recherche de seeds favorables.
- Combat : cas élémentaires calculables, effets par unité inchangés aux
  différentes tailles et conservation des effectifs/dégâts ; ne pas exiger
  que les pertes soient linéaires malgré les seuils, arrêts et sur-dégâts.
- Mesure de coût bornée petits/grands effectifs et batch réel. Contrôles ciblés
  de raccordement service/runtime, aucun E2E navigateur selon le mandat PO.
  Aucune campagne T31/T33, modification de références gelées, fusion ou
  déploiement. La contre-recette indépendante reste à la discrétion du PO.

Recette locale du 22 septembre : le flux historique diverge à la répartition
des impacts lanciers du round 2 (position 102, consommation 16 contre 19), puis
les archers commencent aux positions 118/121 et obtiennent 46/53 touches.
Les pièces jointes d'origine sont conservées sous
`engines/waar-cohort/tests/fixtures/issue16-{25,30}.json`. Leur sérialisation
navigateur a remplacé les poids flottants `1.0` par `1` ; les harnais restaurent
uniquement leur type déclaré avant vérification, sans modifier les hashes ou
les fichiers archivés. Les deux anciens rapports et empreintes sont retrouvés.
Cette limite préexistante d'export/rejeu n'est pas corrigée par #16.

Nouveau protocole, seed 42, mêmes entrées : 9 blessés lanciers et 0 mort aux deux
précisions, archers 51/58 touches aux rounds 1/2 dans les deux variantes,
lanciers 12/11 touches à 25 % puis 15/15 à 30 %. Retour exact à 25 %,
inversion A/B et résumé de 50 combats par sens vérifiés par les services PHP/Rust,
sans navigateur. Les suites dédiées vérifient aussi les lois sur 4 096 seeds
fixes par cas, 180 vecteurs partagés, l'oracle élémentaire de deux frappes sur
1 024 seeds, les limites u32 du sampler et des combats jusqu'à un million
d'unités par camp. Ce sont des contrôles bornés, pas une preuve exhaustive de
tous les combats ni une validation d'équilibrage.

Coût local Windows, corpus monotype `test-2` existant, 80 combats mesurés par
runtime/protocole, transport process-jsonl compris : Rust 46,5 ms historique,
165,9 ms nouveau ; PHP 681,4 / 1 088,4 ms. Une seule passe séquentielle après
échauffement, aucune concurrence de tests demandée. Les issues/arrêts peuvent
changer entre protocoles : ce rapport mesure le coût du même corpus d'entrées,
pas le surcoût isolé du sampler. Aucun seuil de débit serveur n'est certifié.
Commandes, versions, résultats et limites de recette restent dans la PR #17.

## 9. Conséquences de sortie : historique et amendement probabiliste

Les §9.1–9.4 conservent le cadrage historique. Le §9.5 amende explicitement
le tirage pour les nouveaux appels de la soufflerie ; `/2` reste rejouable.

### 9.1 Séparation impérative

Le résultat brut reste disponible avec les cohortes et leur structure restante.
La politique ci-dessous est un service pur versionné, par exemple
`wounded-capture-then-compress/1`. Elle ne guérit pas les cohortes à l'intérieur du
combat et ne rétroagit ni sur les rounds, ni sur le vainqueur.

### 9.2 Calcul par camp et type

Soient `I` l'effectif engagé, `D` les morts bruts, `W` les blessés bruts encore vivants
et `H = I - D - W` les indemnes bruts. Soient `c` le taux de capture et `k` le
pourcentage de sorties conservées après compression, exprimés entre 0 et 1.

1. Si le camp est vaincu et le type capturable : `P = floor(W × c)` ; sinon `P = 0`.
   En cas de match nul, aucun camp n'est vaincu : pas de capture.
2. Blessés bruts non capturés : `Wlibres = W - P`.
3. Morts livrés au jeu : `Dsortie = floor(D × k)`.
4. Blessés libres livrés : `Wsortie = floor(Wlibres × k)`.
5. Prisonniers livrés : `Psortie = floor(P × k)`.
6. Indemnes livrés : `Hsortie = I - Dsortie - Wsortie - Psortie`.

Les quatre catégories sont exclusives. Si le jeu demande aussi `survivantsLibres`,
il s'agit du total dérivé `Hsortie + Wsortie`, pas d'une cinquième catégorie à sommer.

Toutes les sorties hors indemnes sont compressées. Les morts évités deviennent
indemnes, pas blessés. Les captures sont calculées avant cette compression, parmi
les blessés seulement : cela remplace explicitement l'ancienne capture sur les
survivants après compression.

Exemple : `I=1000, H=100, W=300, D=600`, vaincu capturable, capture 10 %, compression
8 % : `P=30`, `Wlibres=270`, puis **48 morts, 21 blessés libres, 2 prisonniers et
929 indemnes**. Leur somme vaut 1000. Une compression de 100 % conserve la projection
brute ; à 0 %, tout le monde sort indemne, même si le résultat brut désigne un vaincu.

### 9.3 Contrat de sortie et limites

Fournir systématiquement les quatre types, même à zéro, leurs catégories brutes et
projetées, les paramètres, la version de politique et le lien vers le résultat brut.
La formule initialement proposée était `(mortsSortie + prisonniersSortie) × coût
unitaire`. Elle a été remplacée dans la politique `/2` livrée : l’indicateur
valorise **morts + blessés libres**, hors prisonniers, au prix d’achat entier.
L’amendement `/3` conserve cette définition ; un coût de soin reste hors scope. Afficher le pourcentage du coût initial, en définissant proprement
le cas d'un coût initial nul dans les résultats de bas niveau.

La première sortie de jeu promise est une ventilation d'effectifs par type, pas
une affectation persistée des blessures à des soldats identifiés. Ne pas inventer
de règle de guérison ou de durée d'infirmerie.

La V1 de conséquences est définie pour les armées initialement indemnes du wizard.
Le résolveur peut traiter des blessés initiaux, mais le jeu devra définir le traitement
des blessures antérieures avant de leur appliquer une compression qui pourrait les
effacer. Jusqu'à cet arbitrage, refuser explicitement cette projection dans ce cas
ou fournir uniquement le brut ; ne pas annoncer une prise en charge implicite.

### 9.4 Batch

Si des statistiques projetées sont demandées, appliquer capture et compression pour
chaque combat, puis sommer les catégories entières. Il est faux de compresser une
moyenne de morts ou de capturer une moyenne de blessés : les arrondis ne commutent pas.

Le batch rapide peut n'agréger que le brut pour dessiner les zones. Si la sortie
historique de l'API promet aussi captures et pertes appliquées, fournir les agrégats
exacts correspondants ou versionner explicitement leur absence. Ne jamais remplir
ces champs par une approximation non annoncée.

Changer cette politique doit nécessiter un changement du service de conséquences,
de sa version et de ses tests, pas une réécriture du résolveur, des zones ou de l'UI.

### 9.5 Amendement du 21 septembre 2026 — politique probabiliste /3

Mandat : [issue #12](https://github.com/NazzTazz/waar-micro-combat/issues/12),
après les constats RC-1 ARR-01/02/03 et CAP-01. À 5 %, chaque catégorie de moins
de vingt pertes disparaissait systématiquement. La correction concerne la sortie,
sans changement de reddition, vainqueur, dégâts, objectifs ou profil RC-1.
Statut : implémentation corrective ; recette E2E et acceptation réservées au PO.

**Loi.** Pour chaque camp/type, avec I initial, D morts bruts, W blessés vivants,
k=compression/100 et c=capture/100 : P ~ Bin(W,c) si vaincu capturable, sinon P=0.
Puis, avec des flux distincts : Dsortie ~ Bin(D,k), Wsortie ~ Bin(W−P,k),
Psortie ~ Bin(P,k). Hsortie=I−Dsortie−Wsortie−Psortie ; survivants libres=Hsortie+Wsortie.
Le diagnostic `prisonersSelectedBeforeCompression` contient P, y compris à k=0.
Pas de capture chez le vainqueur, en match nul ou pour un type non capturable.
Les catégories restent exclusives, entières et de leur type d'origine. À k=100,
toutes les pertes sont conservées ; à k=0, toutes les sorties finales sont indemnes.
Capture 0–50 %, compression 0–100 %. Aucun minimum, reliquat ou transfert entre types.

Pour un blessé capturable vaincu à c=24 %, k=5 % : prisonnier 1,2 %, blessé libre
3,8 %, indemne 95 %. Dix morts donnent une espérance de 0,5 mort. Dix-huit blessés
non capturables donnent 0,9 blessé, et une probabilité de zéro de 0,95^18 ≈ 39,7 %.
Le cas I=32,D=9,W=14 donne 0,45 mort + 0,70 blessé en espérance ; W=68 capturables
vaincus donne 0,816 prisonnier + 2,584 blessés libres. Ce sont des valeurs théoriques.
La somme de binomiales indépendantes de même k garde la même loi à total brut égal :
fragmenter catégories, types ou combats ne réduit plus l'espérance du total conservé.
Cela ne promet ni les mêmes entiers à seed donnée après recomposition, ni le même
coût entre types de prix différents, ni le même résultat physique après changement
de composition.

**Compatibilité.** `consequences.policyVersion` accepte `/2` ou `/3`, dans le duel
comme dans le batch. Champ absent = `/2` ; valeur inconnue, null ou non textuelle
rejetée. L'API PHP historique garde `/2` par défaut. La soufflerie sélectionne `/3`
via sa factory de requêtes, sans réécrire un profil ni son empreinte. Le rejeu
restitue cette sélection. Les sorties `/2` individuelles sont inchangées ; le batch
reçoit une provenance additive `consequenceProvenance`, avec version, protocole et
taux réellement utilisés. `/3` ajoute `samplingProtocol` aux conséquences du duel.
Les modèles physique, numérique, de précision et de touches ne changent pas.

**Protocole figé** `sha256-counter52-binomial-btrs/1`. À chaque étape, créer un
compteur local j=0. Hacher en SHA-256 les octets UTF-8 suivants, sans NUL terminal :

`protocole NUL wounded-capture-then-compress/3 NUL seed NUL camp NUL type NUL étape NUL j`

Seed et j sont des entiers décimaux non signés, sans zéro préfixé. La seed est la
seed effective du combat, dans [0,2147483647] : aucune nouvelle dérivation des seeds
de scénarios. Camp = `attacker` ou `defender` ; type = `soldier`, `spearman`,
`archer` ou `knight` ; étape = `capture`, `dead`, `wounded`, `prisoners`.
Lire les huit premiers octets big-endian, décaler de 12 bits vers la droite pour
obtenir B sur 52 bits, puis U=(B+0,5)/2^52. Incrémenter j après chaque uniforme.
U appartient strictement à (0,1). Ni le hash physique, ni la trace, ni le libellé,
ni l'ordre des appels n'entrent dans cette chaîne. Aucun état partagé avec la physique.

**Sampler dédié.** n reste u32. n=0, taux=0 et taux=100 retournent respectivement
0, 0 et n sans consommer d'uniforme. Sinon prendre p=min(taux,100−taux)/100 ; pour
un taux >50, retourner n−X. Si n*p<30, sommer les attentes géométriques
floor(log(U)/log1p(−p))+1 jusqu'à dépasser n ; le nombre d'attentes complétées est X.
Sinon employer le rejet transformé BTRS de
[Hörmann, 1993](https://doi.org/10.1080/00949659308811496), avec le test d'acceptation
logarithmique explicité dans [TensorFlow](https://github.com/tensorflow/tensorflow/blob/master/tensorflow/core/kernels/random_binomial_op.cc).
Il s'agit d'un rejet binomial, pas d'une normale arrondie ou tronquée.

Les deux implémentations évaluent dans le même ordre en binary64, sans FMA explicite :
proposition BTRS, refus des k hors [0,n], acceptation rapide, puis log du rapport des
masses. Le terme central utilise log1p pour limiter la cancellation ; la correction
de Stirling utilise dix constantes pour k<10 puis les termes jusqu'à 1/(1188*x^9),
x=k+1 (terme suivant <7e−15 pour x≥11). Les transcendantes restent celles du runtime :
ce protocole ne promet pas une arithmétique réelle exacte sur toute plateforme.
Les vecteurs communs vérifient entiers et consommation du flux ; un port vers une
nouvelle plateforme doit les rejouer. Aucun écrêtage ou plafond d'itérations ne
substitue une autre loi. Coût moyen borné : moins de 31 uniformes dans la branche
faible espérance, rejet de coût moyen constant pour les grands effectifs ; mémoire
constante. Le sampler physique historique demeure inchangé.

**Raccordement.** Une seule projection par camp/type alimente détail et batch dans
chaque langage. Sommes batch en u64/entiers PHP 64 bits, après projection individuelle.
La factory refuse une provenance absente ou différente de la politique demandée.
Version/protocole participent au contexte et à la clé du cache. Une comparaison
entre `/2` et `/3` laisse les observations brutes comparables mais n'émet aucun delta
de perte projetée : « Non comparable », avec invitation à remesurer la référence.
Les zones anciennes suivent le mécanisme de remesure/réassociation existant ; aucune
modification des objectifs ni migration silencieuse des sauvegardes.

La compression est expliquée comme une fréquence de conservation, avec des entiers
variables selon la seed. La provenance reste dans les détails existants. Les pertes
économiques restent la valeur d'achat des morts + blessés, hors prisonniers.

**Vérification ciblée.** Les tests purs contrôlent les vecteurs, la partition, les
extrêmes et la loi sur les seeds 0…4095 ; la fragmentation entre deux combats utilise
2s et 2s+1, donc au total seulement les seeds 0…8191. Pour une moyenne binomiale,
marge Bernstein sqrt(2*variance*log(2000000)/4096)+2*log(2000000)/(3*4096), appliquée
aux Bernoulli constituants (risque nominal ≤1e−6 par contrôle). Même principe pour
chaque fréquence de classe. Le second moment centré utilise six écarts-types
calculés à partir du quatrième moment binomial. Les contrôles ne sont jamais relancés
avec une autre liste pour obtenir un résultat favorable. La parité seule ne sert
pas de preuve de loi. La recette physique et les commandes sont consignées dans
la PR de #12, dans le budget de 512 combats par langage, sans campagne d'équilibrage.

## 10. Journal métier et explication

### 10.1 Informations conservables

Le rapport le plus verbeux contient :

- Entrées complètes, règles effectives, effets actifs identifiés et provenance.
- Valeurs de base, facteurs de formation/acquis, facteurs météo, résultat exact de
  préparation et représentation lisible des étapes.
- Seed, versions fonctionnelles, politique de conséquences et empreintes.
- État initial et final par cohorte/type/camp.
- Pour chaque round, intervalle et précision tirée par type et camp.
- Tentatives initiales, allocations, touches, dégâts émis et absorbés, excédent,
  réallocation héritée et état après le round.
- Cause d'arrêt, critère comparé, valeurs comparées et résolution d'une égalité.
- Ventilation brute puis conséquences de sortie avec les arrondis appliqués.

Le journal est un artefact métier sérialisable ; sa persistance dans Waar sera faite
par le jeu. Le calcul de combat ne réalise aucune écriture métier externe.

### 10.2 Matrice par round

Conserver le tableau 4 × 4 : ligne = type qui frappe, colonne = type cible, même
quand une cohorte est vide. Séparations horizontales et verticales lisibles.

Chaque cellule expose les compteurs du moteur : tentatives affectées et touches
retenues, sans confondre les deux. Le tooltip accessible au survol et au clavier
explique effectif, `N`, attaque fractionnée, précision tirée, contre, coefficient
défensif et dégâts. Si plusieurs cohortes blessées sont impliquées, les détails
doivent l'indiquer sans inventer une seule structure individuelle moyenne.

La réallocation peut faire apparaître plusieurs allocations d'une même tentative
non consommée : ne pas présenter leur somme comme le nombre de combattants distincts.
Les libellés actuels « chacun affecté à une seule cible » et « dégâts sans report »
doivent être remplacés par des descriptions fidèles au moteur par cohortes.

Les événements de trace sont émis au point où le moteur réalise le calcul, pas
reconstruits après coup en JavaScript. Les logs conservent des agrégats de cohortes,
pas une ligne par individu ou tentative.

### 10.3 Trois niveaux de lecture

- Résumé visible : vainqueur, deux camps côte à côte, indemnes/blessés/morts/prisonniers
  par type, coût économique perdu et pourcentage du coût initial.
- Détail replié : préparation, deux camps côte à côte, attaque et structure cumulées,
  chronologie et matrices par round.
- Données brutes/provenance repliées : export complet exploitable pour rejeu.

Le mode sans trace du batch et le mode détaillé doivent donner le même résultat
physique pour les mêmes entrées. Un tirage sélectionné dans une mesure doit être
rejouable avec le journal complet.

## 11. Batch Rust et recherche

### 11.1 Mesure des monotypes

Conserver les 16 confrontations ordonnées, les deux observations de rôle par
confrontation, soit 32 points. Budget économique existant : 400 400 par camp,
effectifs calculés par division entière du budget par le coût du type.

Conserver les identifiants des scénarios, la dérivation des seeds et la plage de
1 à 100 répétitions du parcours courant. Les paramètres/modificateurs pertinents
font partie du contexte de mesure et des empreintes.

Le backend construit un travail complet et le transmet au batch Rust. Ne pas
convertir les deux boucles PHP actuelles en milliers d'appels FFI ou de processus.
Préparer les constantes réutilisables hors de la boucle de combats.

Le résultat conserve des sommes entières exactes avant calcul des moyennes.
Les plages d'itérations indépendantes se recomposent par leurs totaux, pas par
moyenne de moyennes affichées. Un résultat partiel est explicitement partiel.

### 11.2 Axes et égalités

X = fréquence de victoire du rôle observé. Y = ratio de morts bruts sur effectif
initial, conformément à l'axe actuel `rawLossRatio`. Les blessés ne deviennent pas
implicitement des morts sur cet axe. Un nouvel axe blessés ou hors-combat serait
une évolution distincte.

Avec les matchs nuls, les fréquences de victoire des deux camps ne sont plus
complémentaires. Conserver le taux de nuls et désactiver toute symétrie UI qui
supposerait automatiquement `winRateB = 1 - winRateA`.

La compression et les captures ne changent pas les positions des points bruts.
Elles peuvent changer les colonnes projetées, pas les conclusions physiques.

### 11.3 Recherche de candidats

Conserver la recherche bornée actuelle, au plus huit candidats, son ordre et ses
règles d'évaluation. Elle peut appeler une interface de mesure différente sans
devenir une responsabilité du moteur.

Pour cette livraison, conserver les dimensions de recherche actuelles — attaque,
structure, coefficient défensif, contres — plutôt qu'ajouter implicitement précision,
fractionnement ou météo à l'espace exploré. Les autres réglages restent figés et
sont listés dans le résultat. Toute extension ultérieure sera explicite.

Le moteur accepte un ruleset et des scénarios : cette frontière suffit pour un futur
optimiseur avancé. Ne pas développer maintenant un solveur, un framework de plugins
ou un service distribué « en prévision ».

## 12. Raccordement de l'interface existante

### 12.1 Parcours

Conserver la navigation discrète **Unités · Contres · Combat · Test · Zones**, le
fond bleu/noir, les arrondis et les conventions visuelles actuelles. La météo reste
une section dédiée accessible dans le parcours, par exemple sous Combat, sans
imposer une nouvelle navigation générale ni un formulaire géant.

Unités : ajouter précision de base, amplitude et nombre de frappes ; conserver
attaque, structure, coûts, rôle/coefficient défensif et éligibilité à la capture.
Expliquer les pourcentages : 15 % ± 2 points correspond à `0,15 ± 0,02`, pas à un
coefficient de 2 % appliqué à 15 %.

Combat : rounds avec tirette, activation/seuil de reddition, critère de départage,
égalité, compression et captures. Afficher ce qui est désactivé sans appliquer un
ancien défaut caché. Expliquer que la politique de conséquences est une projection.

Le combat-test reste accessible à tout moment et dans les deux sens. Les erreurs
signalent le type/champ manquant sans perdre le brouillon. Les deux camps conservent
leurs quantités, leurs coûts et leurs modificateurs lors de la navigation.

Les modificateurs non météo peuvent être éprouvés via un jeu de démonstration et
un import explicite ; pas besoin de construire un éditeur d'arbre technologique
pour terminer cette livraison. La météo possède son véritable éditeur de coefficients.

### 12.2 Éditeur de zones

Préserver le dessin, déplacement et redimensionnement des ellipses à la souris,
la sélection des points, les axes, tooltips et possibilités d'affinage T27.
Ne pas remplacer le graphe par un canvas simplifié ou un tableau de coordonnées.
Les vecteurs restent un outil expert, pas un prérequis au premier combat-test.

Un changement de profil ne doit pas effacer le travail de dessin. Il rend les
mesures et leur attachement aux objectifs obsolètes. La géométrie peut être conservée
comme brouillon, puis réassociée au nouveau contexte après mesure et action explicite
de l'utilisateur. Conserver l'ancienne provenance : ne pas simplement réécrire son
empreinte comme si les zones avaient toujours été créées avec le nouveau moteur.

### 12.3 États de calcul

Afficher calcul en cours, erreurs structurées et résultat obsolète. Un résultat
ancien peut rester visible avec son statut ; il ne remplace jamais une requête plus
récente. Le backend valide modèle, profil et contexte avant d'accepter une mesure.

Ne pas utiliser des délais HTTP toujours plus longs comme preuve de performance.
Les limites sont finies. Si une annulation est proposée, elle intervient à une
frontière de batch explicite et ne produit pas un résultat complet fictif.

## 13. Exécution native et migration

### 13.1 Point d'accès commun

Créer un adaptateur backend commun pour le moteur par cohortes. Le service duel,
le service monotypes et la recherche passent par ce contrat. Ne pas laisser le
duel sur le moteur individuel pendant que les mesures passent en cohortes.

Le choix du transport natif reste étroit : FFI avec bibliothèque dédiée lorsque
disponible, ou CLI natif avec un appel par travail. Vérifier la version réellement
chargée. Une bibliothèque absente, incompatible ou inaccessible provoque une erreur
claire ; pas de retour silencieux à un moteur d'une autre physique.

PHP reste la référence et un chemin de diagnostic explicitement sélectionné. Le
runtime utilisé figure dans la provenance. Le moteur natif est le chemin des études
normalement exposées à l'utilisateur.

Le namespace `App` de l'adaptation est actuellement isolé. Choisir en tranche A/B
soit un namespace dédié avec migration mécanique, soit une frontière de processus
garantissant l'absence de collision. Ne pas charger plusieurs copies historiques
dans le même autoloader et espérer que l'ordre des `require` fasse foi.

### 13.2 Anciens profils

Le migrateur conserve les choix de l'utilisateur : caractéristiques, coûts, contres,
coefficient défensif, capturable, rounds et géométrie des zones. Il documente :

- les nouveaux champs ajoutés avec leurs valeurs de départ ;
- les anciens paramètres devenus sans effet dans le nouveau modèle ;
- le changement de physique et de politique de conséquences ;
- l'obsolescence des mesures et des classements antérieurs.

Pour l'ancienne météo d'attaque seule : conserver ses coefficients d'attaque et
ajouter les coefficients de précision neutres. Ne pas déduire une pénalité de précision
de son libellé. Les anciens identifiants de presets restent migrables.

Le migrateur produit un nouveau document ; il n'écrase pas les anciennes références
ou sauvegardes. Un profil déjà au nouveau format est réimportable sans perte.
Depuis l'amendement #14, un profil sans `combat.woundDamageThreshold` est normalisé
à `"0"` au chargement, sans réécriture en masse. Une valeur explicite, y compris
`"0"`, est conservée. Les nouveaux profils portent explicitement `"0.2"`.

### 13.3 Retour arrière

Conserver les données historiques et la dernière version utilisable pendant la
qualification. Une bascule de runtime ne doit jamais autoriser à comparer comme
équivalents des résultats issus de modèles différents. Le retour arrière consiste
à rouvrir un ancien profil avec son moteur/version, pas à relabelliser les nouveaux
résultats comme anciens.

## 14. Vérification obligatoire

### 14.1 Oracles et cas métier

| Sujet | Preuve minimale |
| --- | --- |
| Modificateurs | Exemple `0,15 × 1,20 × 0,80 = 0,144` ; permutation des effets sans changement ; valeurs de base non mutées ; doublons/paramètres interdits rejetés ; entiers fractionnaires et conflits booléens rejetés. |
| Météo | Effets distincts par type et camp ; amplitude météo rejetée ; amplitude d'entraînement acceptée ; pas de double application. |
| Uniforme | Amplitude zéro, bornes près de 0/1, vecteurs déterministes, test statistique borné et reproductible ; même précision pour toutes les cohortes du type dans le round. |
| Fractionnement | Chevalier 350 et N=5 : 5 tentatives à 70 ; cohorte 100 : 500 tentatives ; N=1 ; limites/overflow ; aucune génération d'extraballs. |
| Contres | Une cohorte adverse quatre fois plus nombreuse reçoit les allocations correspondantes indépendamment du `attackFactor` ; le facteur change uniquement les dégâts. |
| Défense | Même unité en attaque sans coefficient, en défense avec coefficient appliqué une seule fois ; rôles A/B inversés ; coefficient nul. |
| Blessés | À paramètres égaux, même puissance qu'un indemne ; structure restante conservée ; aucun ancien multiplicateur encore actif. |
| Réallocation | Régressions héritées protégées, compteurs exprimés en tentatives fractionnées, aucune nouvelle règle tactique. |
| Arrêt | Reddition désactivée, seuil exact, un/deux camps au seuil, élimination simultanée, limite de rounds, deux critères, deux politiques d'égalité. |
| Conséquences | Exemple 1000 → 929/21/48/2 du §9 ; capture exclue pour indemnes/non capturables/vainqueur/nul ; conservation des effectifs ; compression 0 et 100 %. |
| Économie | Coûts personnalisés, blessés libres non comptés détruits, coût perdu et pourcentage, séparation du départage brut. |

Les tests doivent inclure des contre-exemples où une formule erronée donnerait un
autre résultat : pas seulement des cas neutres où tous les facteurs valent 1.

### 14.2 Parité PHP/Rust

- Résultats physiques complets, rounds, états des cohortes et compteurs de trace.
- Modificateurs propres à chaque camp, blessés, fractionnement et variations de précision.
- Seeds 0, usuelles et limites ; cohortes de 1, 64, 65, et grandes populations.
- Batch contre boucle PHP de référence ; versions avec et sans trace ; reprises
  de plages ; recomposition d'agrégats ; politiques de conséquences.
- Normaliser uniquement l'ordre des clés d'objets JSON pour comparaison ; préserver
  l'ordre des listes, les types et les valeurs exactes. Les indicateurs flottants
  dérivés ont une tolérance documentée, jamais les effectifs ou microstructures.
- FFI/Rust indisponible : tests de parité en échec explicite, pas ignorés en silence.
- Au moins un contrôle négatif pour défense, fractionnement et conséquences :
  désactiver ou altérer la règle doit faire échouer les tests correspondants.

### 14.3 Bout en bout et UX

Parcours réel : créer/charger profil → modifier archer → sélectionner météo → tester
A/B → lire résumé et détail → mesurer → dessiner une ellipse → rechercher huit
candidats au plus → modifier un paramètre → constater l'obsolescence → remesurer.

Vérifier dans le navigateur, pas seulement par inspection du HTML : glisser une
ellipse, redimensionner, ouvrir un tooltip au clavier, revenir à une étape, conserver
les quantités et brouillons, importer/exporter et rejouer après rechargement.

Vérifier en HTTP réel le chargement du runtime Rust, les erreurs et les timings ;
une réussite CLI ne prouve pas le fonctionnement du serveur PHP.

### 14.4 Commandes et preuves

Exécuter les contrôles du dépôt : `composer validate --strict`, `composer install`,
`composer test`, `composer test:js`, `composer smoke`. Utiliser `php composer.phar`
si le binaire global n'est pas disponible. Pour un changement touchant le rendu des
références, exécuter aussi `php bin/render-finalist-comparison.php`.

Exécuter les tests Rust avec verrou, le build release, les tests propres à
`engines/waar-cohort/` et la vérification du manifeste d'import. Mettre à jour les
commandes documentées si un adaptateur ou un namespace change.

Les preuves distinguent ce qui a été exécuté, ce qui a échoué et ce qui n'a pas pu
être exécuté. Ne pas présenter les anciens rapports importés comme une nouvelle recette.

## 15. Trois grandes tranches de livraison

### A — Moteur complet, explicable et mesurable

**Problème :** l'adaptation ne porte qu'une partie des décisions et n'a pas encore
le contrat complet nécessaire au jeu et à la soufflerie.

**Livrer ensemble :** contrats/versionnement, préparation des modificateurs,
précision uniforme, fractionnement remplaçant extraball, suppression du facteur
blessés, reddition/départage, trace agrégée, politique de conséquences isolée et
batch Rust adapté. Utiliser le moteur copié existant, pas une réécriture.

**Démonstration de sortie :** un exemple autonome PHP/Rust avec entraînement et
météo distincts par camp, chevaliers à plusieurs frappes, rapport détaillé rejouable,
projection de sortie et mesure d'armées-types par le batch natif.

**Acceptation :** oracles et parité du §14, absence de physique individuelle,
logs identiques au calcul, politique remplaçable, premières mesures de fragmentation
et de débit. L'ancien prototype UI reste utilisable pendant cette tranche.

### B — Soufflerie actuelle raccordée de bout en bout

**Problème :** les réglages et observations de l'interface utilisent encore l'ancien
résolveur, alors que l'admin doit pouvoir éprouver le moteur adapté sans écrire du JSON.

**Livrer ensemble :** adaptateur backend, nouveaux champs du wizard, météo émulée,
combat-test bidirectionnel, résumé/détails/matrices, batch de monotypes, recherche
bornée raccordée, conservation de T27, migration de profils et gestion d'obsolescence.

**Démonstration de sortie :** le parcours navigateur complet du §14.3, avec des
résultats réellement issus du Rust adapté et un aller-retour export/import.

**Acceptation :** aucune perte de manipulation des zones, aucun calcul de combat
dans JS, aucun appel natif par combat dans une étude, aucune conversion silencieuse
des anciens paramètres, aucun mélange de résultats entre modèles. À la fin de cette
tranche, Tristan doit pouvoir utiliser et comparer l'outil, pas attendre une nouvelle
tranche pour que les boutons fonctionnent ensemble.

### C — Qualification, performances et bascule assumée

**Problème :** un prototype démontré doit devenir le chemin normal utilisable sans
régression, blocage HTTP ou divergence entre runtime de référence et runtime servi.

**Livrer ensemble :** recette navigateur/HTTP, benchmarks reproductibles, durcissement
du chargement natif et des erreurs, audit de migration/provenance, corrections des
défauts constatés, documentation de lancement courte et bascule du chemin normal.

**Acceptation :** démonstration finale reproduite après redémarrage, contrôles complets
verts, performances publiées pour les charges réelles, anciens résultats préservés,
absence de fallback silencieux et liste de limites explicite. L'admin peut créer un
profil, tester, mesurer, dessiner et rechercher sans intervention de développeur.

Cette tranche ne devient pas un prétexte à refaire l'habillage ou le moteur. Les
corrections restent liées aux critères de la spécification. Les nouveautés reportées
restent hors du chemin critique.

## 16. Performance : mesurer le vrai parcours

Benchmarker le build release, sans autres tests lourds simultanés, avec environnement
et paramètres consignés. Distinguer préparation, résolution, projection, sérialisation,
temps HTTP total et mémoire maximale.

Charges minimales : duel du wizard ; 16 monotypes × 100 répétitions ; recherche
bornée de huit candidats ; armées mixtes ; plusieurs valeurs de N dont la borne
admise ; facteurs causant des structures restantes variées.

Comparer sans trace et trace complète sur un combat sélectionné. Le batch ne conserve
pas toutes les traces de toutes les simulations. Vérifier que la préparation des
modificateurs n'est pas répétée inutilement et que les sommes projetées ne requièrent
pas un aller-retour PHP par combat.

Objectifs de recette proposés, à mesurer sur la machine de développement : duel
bidirectionnel courant inférieur à une seconde après chargement, mesure de monotypes
standard inférieure à cinq secondes, recherche standard huit candidats inférieure à
trente secondes. Ce sont des objectifs de cette livraison, pas des performances déjà
obtenues ni une garantie pour tous les profils extrêmes. Un écart impose une mesure
et une explication avant clôture, pas une diminution cachée du budget d'échantillonnage.

Ne pas compromettre la parité, les arrondis ou la conservation des effectifs pour
atteindre un chiffre. Ne pas lancer les recherches historiques longues T31/T33 pour
qualifier le parcours courant.

## 17. Définition finale de « terminé »

La livraison est terminée lorsque :

- Le moteur adapté exécute les décisions actées et ne réactive aucune règle supprimée.
- La soufflerie existante l'utilise pour les duels, mesures et candidats.
- Les rapports permettent d'expliquer base, acquis, météo, précision, frappes,
  contres, défense, blessés, cause de victoire et conséquences de sortie.
- Le dessin T27 fonctionne toujours, les matchs nuls sont correctement représentés
  et la compression n'affecte pas les observations brutes.
- Les résultats sont rejouables, les versions distinguées et les anciens profils
  migrables sans perte silencieuse.
- Les batchs utilisent réellement Rust, les chiffres de performance sont mesurés
  et les tests de parité ne sont pas contournés.
- La politique provisoire de sortie peut être remplacée sans modifier le combat brut.
- Les limites encore ouvertes — réallocation, formations, optimiseur avancé,
  blessures antérieures et intégration au jeu réel — sont visibles mais ne donnent
  lieu à aucune nouvelle infrastructure non demandée.
- La recette utilisateur a été faite avant le commit/merge final demandé par Tristan.

La maintenance de cette spécification se fait par modification explicite des
sections concernées dans la même PR que le changement de contrat. Pas de nouveau
document de reprise pour reformuler les mêmes règles.
