# Waar Micro Combat

Prototype PHP 8.2 autonome du micro-résolveur décrit dans
`docs/waar-micro-combat-contract.md`.

Le paquet ne dépend ni de Symfony ni de Doctrine. Il consomme des valeurs déjà
préparées, résout les échanges et retourne des pertes brutes. Il n'applique
aucune conséquence dans Waar.

La politique de départage appartient au ruleset. Les anciens manifestes qui ne
la déclarent pas conservent `draw`. Les variantes T28 utilisent explicitement
`"tieBreakPolicy": "defender"` : une égalité exacte ou une extinction mutuelle
est une victoire défensive, sans changement des dégâts et des pertes.

## Soufflerie T24

`experiments/t23-first-quadruplet.json` conserve le premier essai.
`experiments/t24-astra-vector-corrections.json` reprend le même candidat et
désature deux scénarios. Ce dernier est exécuté par défaut et peut être copié
ou modifié sans changer le code du moteur.

Depuis la racine de `waar-v3` :

```powershell
php packages/waar-micro-combat/bin/run-wind-tunnel.php
```

Le lanceur écrit `experiment.json`, `report.json`, `report.md` et un
`report.html` autonome dans `var/waar-micro-combat/t24/`. La page permet de
passer des effectifs survivants à la structure ou à la valeur économique et
d'afficher un camp ou les deux sans relancer les combats. Les positions passent
du dernier état visible au nouvel axe en 200 ms ; une égalité reste un point.

Un fichier d'expérience et un répertoire de sortie peuvent être fournis :

```powershell
php packages/waar-micro-combat/bin/run-wind-tunnel.php chemin/experience.json chemin/sortie
```

## Référence Legacy T25A1

L'exporteur côté Waar rejoue l'oracle Legacy sur les compositions T24 et écrit
un artefact autonome, sans ajouter de dépendance `App\…` au paquet :

```powershell
php tools/export-waar-micro-legacy-reference.php
```

La sortie par défaut est
`var/waar-micro-combat/t25a1/legacy-reference.json`. Son contrat versionné est
`schema/legacy-reference.schema.json`.

## Cahier des charges d'acceptabilité T25

Après avoir produit la référence Legacy, générer le rapport autonome éditable
avec :

```powershell
php packages/waar-micro-combat/bin/run-acceptance-overlay.php
```

Les artefacts courants sont écrits dans `var/waar-micro-combat/t25b/` ; la
livraison en lecture seule reste figée dans `var/waar-micro-combat/t25a2/`. Le rapport
embarque Apache ECharts 5.6.0, épinglé avec sa licence dans `resources/vendor/`,
et n'effectue aucun chargement réseau. Les 48 ellipses commencent en brouillon.
Leur centre et leurs rayons peuvent être réglés par poignées ou par saisie
numérique, puis confirmés, désactivés et exportés localement. Annulation,
rétablissement, migration du document T25A2 et contrôle de provenance restent
locaux au navigateur. Le format portable est défini par
`schema/acceptance-zones.schema.json` ; les rayons acceptés vont de `0.005` à
`1` dans l'espace normalisé.

## Éditeur d'objectifs monotypes

La première passe du cadrage courant utilise les seize confrontations ordonnées
entre monotypes. Chaque camp engage exactement `400 400` unités de valeur au
barème `80/110/130/350`, soit respectivement 5 005 Soldats, 3 640 Lanciers,
3 080 Archers ou 1 144 Chevaliers :

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php
```

La sortie courante `var/waar-micro-combat/t26-canonical-objectives/` propose 32
objectifs en brouillon : seize confrontations × deux camps, uniquement
pour les pointes du candidat. La vue par paire distingue l'attaquant et le
défenseur, relie leurs centres et permet de sélectionner directement une zone.
Les taux de victoire visés sont complémentaires : modifier X ou son rayon sur
un camp lie les deux objectifs de la confrontation, tandis que Y reste indépendant entre camps.

Survivants et valeur économique sont deux vues du même objectif monotype :
coordonnées, rayons, approbation, activation et suppression sont communs. Seules
les zones `survivors` sont exportées et doivent entrer une fois dans le score.
La structure restante inclut les dégâts partiels : diagnostic seul, sans cible.

Les X attaquants partent de l'observation `roles-a`; les X défenseurs partent de
leur complément à 100 %. Les Y gardent les observations comme commodité
d'édition. Ces valeurs initiales ne constituent pas des objectifs acceptés. Les
anciens exports compatibles sont validés puis convertis avec un message explicite :
les zones survivants sont conservées exactement, les anciennes zones structure
et économiques sont retirées et un nouvel export est demandé. L'original reste
intact. Une liaison X incohérente doit être demandée explicitement. Les contraintes T25B,
Legacy et T24 ne sont pas importées dans cette surface. Aucun solveur n'est
exécuté.

Un second argument conserve la possibilité de produire un artefact historique
ou de recette dans un autre répertoire :

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php packages/waar-micro-combat/experiments/t26-monotype-equal-cost.json var/waar-micro-combat/ma-recette
```

## Prévol de la recherche monotype

Le prévol consomme le design PO canonique à 32 objectifs confirmés et rejette
explicitement les anciens documents à 96 zones :

```powershell
php packages/waar-micro-combat/bin/evaluate-monotype-objectives.php
```

Il rejoue le candidat courant, compte chaque objectif `survivors` une seule fois
dans le taux de satisfaction et publie séparément le contrôle d'absence de nuls.
La valeur économique ne duplique pas le score et la structure reste un
diagnostic. La sortie est écrite dans
`var/waar-micro-combat/t27b-objective-preflight/`. Cette commande ne cherche et
ne modifie aucun paramètre.

Pour rejouer le même corpus avec la politique T28 validée :

```powershell
php packages/waar-micro-combat/bin/evaluate-monotype-objectives.php var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/t28-defender-tie-break
```

Le manifeste déclare `tieBreakPolicy = defender` sur le témoin et le candidat.
Le rapport doit alors compter zéro nul, tout en conservant les pertes mesurées
avant le départage.

## Objectif continu de recherche T29

Le calcul continu destiné à guider une future recherche se rejoue avec :

```powershell
php packages/waar-micro-combat/bin/evaluate-monotype-search-objective.php
```

La commande conserve les 32 objectifs canoniques et les contrôles stricts du
prévol. Pour chaque ellipse, elle normalise les écarts X et Y par leurs rayons,
attribue une pénalité nulle à tout point accepté, puis mesure l'excès radial au-delà
de la frontière. La perte à minimiser est la moyenne arithmétique des 32 excès ;
le pire écart et son objectif sont publiés séparément. Valeur économique et
structure ne contribuent pas au calcul.

Hors de la zone, la différence des racines est calculée sous sa forme
rationalisée `(q - frontière) / (√q + √frontière)`. Cette forme conserve une
pénalité strictement positive au voisin flottant immédiat de la frontière.

La sortie est écrite dans `var/waar-micro-combat/t29-search-objective/`. Cette
commande mesure le candidat T28 courant ; elle ne cherche et ne modifie aucun
paramètre.

## Validation de l'espace proposé T30

Le manifeste T30 décrit les 15 paramètres proposés, leurs bornes inclusives et
le pas `0.001`. La commande exige un répertoire de sortie explicite et vide :

```powershell
php packages/waar-micro-combat/bin/validate-monotype-search-space.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json var/waar-micro-combat/t30-search-space-proposed
```

Elle contrôle l'aller-retour du candidat initial, les minima et maxima, les
champs figés, la quantification et les identités dirigées des contres. Le rapport
T30 conserve le statut historique `proposed-awaiting-review` ; la contre-recette
T30d a ensuite accepté ces bornes pour T31. Les sondes min/max servent uniquement
à détecter un dépassement numérique ; aucun candidat n'est recherché par cette
commande.

Le manifeste `t30.1-proposed` lie aussi l'expérience T28 complète à une
empreinte canonique vérifiée avant toute simulation. La mise en forme JSON et
l'ordre des contres n'affectent pas cette empreinte ; toute valeur modifiée dans
le témoin, le candidat initial ou le corpus est rejetée. La validation complète
compare elle aussi les identités dirigées sans dépendre de l'ordre des contres.

## Recherche bornée T31

La contre-recette T30d autorise l'utilisation du manifeste `t30.1-proposed`
dans le premier run borné. Les recettes smoke et standard utilisent la seed de
recherche `314159` et respectivement 8 et 128 candidats uniques :

```powershell
php packages/waar-micro-combat/bin/search-monotype-candidates.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json 314159 8 var/waar-micro-combat/t31-smoke-seed-314159

php packages/waar-micro-combat/bin/search-monotype-candidates.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json 314159 128 var/waar-micro-combat/t31-standard-seed-314159
```

Un argument final facultatif remplace le plafond de propositions, fixé sinon à
dix fois le budget. La commande refuse une sortie non vide. Elle évalue
l'initial, effectue un échantillonnage global puis des perturbations locales du
meilleur, déduplique après quantification et classe par perte T29c puis empreinte
paramétrique. Les métadonnées de temps et de machine sont isolées de ses sorties
déterministes. Elle exporte jusqu'à trois finalistes sans nul et les rejoue avec
le runner autoritaire complet. Voir `docs/waar-micro-combat-t31-relay.md`.

## Comparaison visuelle T32

T32 transforme les artefacts standard T31 et le rapport micro T28 de l'initial
en un rapport autonome. La commande ne lance aucun combat et exige une sortie
absente ou vide :

```powershell
php packages/waar-micro-combat/bin/render-finalist-comparison.php var/waar-micro-combat/t31-standard-seed-314159 var/waar-micro-combat/t28-defender-tie-break/micro-report.json var/waar-micro-combat/t32-finalist-comparison
```

Ouvrir ensuite
`var/waar-micro-combat/t32-finalist-comparison/report.html`. Le rapport compare
toujours l'initial au finaliste sélectionné, expose les 32 objectifs, les trois
axes Y, le témoin neutre facultatif et les valeurs exactes dans un tableau. Les
objectifs restent en lecture seule. Les téléchargements de variante et
d'évaluation embarquent la provenance T31. Voir
`docs/waar-micro-combat-t32-relay.md`.

## Validation de stabilité T33

T33 gèle l'initial et les trois finalistes dans leur ordre T31, écrit le plan
et ses empreintes avant la première simulation, puis exécute les cinq lots
réservés de 1 000 répétitions :

```powershell
php packages/waar-micro-combat/bin/validate-finalist-stability.php var/waar-micro-combat/t31-standard-seed-314159 var/waar-micro-combat/t33-finalist-stability
```

La commande refuse une sortie non vide. Elle audite les seeds dérivées contre
la recherche T31 et entre lots, conserve les mêmes seeds pour l'initial, le
témoin et les finalistes, puis agrège les compteurs exacts avant de réévaluer
les 32 ellipses. Les résultats par lot, les agrégats, la « variation entre
lots », le JSON, le Markdown et le rapport HTML autonome sont écrits sous
`var/waar-micro-combat/t33-finalist-stability/`. Le rapport permet d'exporter
localement une décision PO sans lancer de combat ni modifier les objectifs.
Voir `docs/waar-micro-combat-t33-relay.md`.

Avant de construire le plan T33, l'expérience T31 vérifiée est l'autorité pour
la seed de combat et les répétitions. Le plan et le résultat T31 doivent confirmer
ces valeurs, leur identifiant commun, leurs empreintes d'entrée, leurs budgets
et la clé du cache témoin. Tout désaccord est rejeté sans plan ni mesure. Les
durées de lot commencent avant l'initial et son témoin, puis se terminent après
le troisième finaliste.

## Observation des compositions mixtes T34

T34 rejoue l'initial T28 et les trois finalistes figÃ©s sur les six scÃ©narios
T24, avec 1 000 rÃ©pÃ©titions et la seed rÃ©servÃ©e `32452843` :

```powershell
php packages/waar-micro-combat/bin/observe-mixed-compositions.php
```

La commande refuse une sortie non vide et Ã©crit son manifeste avant toute
simulation. Elle conserve les compositions et budgets T24 exacts, applique le
dÃ©partage `defender` aux quatre variantes et calcule sÃ©parÃ©ment effectifs,
structure et valeur Ã©conomique. Le JSON publie aussi survivants et pertes par
type, victoires, dÃ©faites, nuls et rounds. Le rapport HTML autonome permet de
changer finaliste, scÃ©nario et axe, puis d'exporter les valeurs mesurÃ©es. Aucun
score ou verdict d'acceptation T24 n'est calculÃ©. Voir
`docs/waar-micro-combat-t34-relay.md`.

## Tests

```powershell
php vendor/bin/phpunit packages/waar-micro-combat/tests
node packages/waar-micro-combat/tests/acceptance-zones-model.test.js
node packages/waar-micro-combat/tests/finalist-comparison-model.test.js
node packages/waar-micro-combat/tests/finalist-stability-model.test.js
node packages/waar-micro-combat/tests/mixed-composition-observation-model.test.js
```
