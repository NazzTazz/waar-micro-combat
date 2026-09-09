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

## Tests

```powershell
php vendor/bin/phpunit packages/waar-micro-combat/tests
node packages/waar-micro-combat/tests/acceptance-zones-model.test.js
```
