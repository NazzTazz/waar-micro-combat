# Contre-recette Astra T32 — 9 septembre 2026

**Contrôles automatisés et fidélité des artefacts validés, aucun défaut bloquant
identifié dans le périmètre examiné. Contre-recette navigateur indépendante
non achevée pour une limitation d'outillage, détaillée ci-dessous.**
Aucun code applicatif ni objectif PO modifié.

## Vérifications exécutées

- Suite PHP du paquet : **57 tests, 9 717 assertions**, réussis sous PHP 8.2.33.
- Suites Node `finalist-comparison-model.test.js` et
  `acceptance-zones-model.test.js` : réussies.
- Lints PHP du builder et de la commande ; vérification de syntaxe JavaScript
  de l'application ; `git diff --check` sur le paquet et le suivi : réussis.
- Génération isolée dans `var/waar-micro-combat/t32-astra-review/` depuis le run
  standard T31 et le rapport micro T28 officiels. Les trois empreintes reproduisent
  exactement le relais Sol :
  - HTML : `167F1512B60535B60F059F23B2D7A0348D5B251D13CEE14671D1C85DE1CA1DDB` ;
  - présentation : `17C89014E49D78B7ACC326BCC26BC9048EBF64D4FE1FBED220CF2E8EA549679B` ;
  - Markdown : `7A47ADEEC077967BE15F1CE90D9568882336CF387CFC86AAAA9652BDB7ADF7B4`.
- Audit supplémentaire du modèle sur les vraies données : **288 combinaisons**
  (3 finalistes × 16 confrontations × 3 axes × témoin visible/masqué), deux camps
  présents et sélection cohérente dans chaque vue, 32 objectifs globaux uniques,
  économique associé aux objectifs survivants et structure sans cible.
- Les **six documents d'export** (variante/évaluation des trois finalistes)
  contiennent exactement les artefacts T31 correspondants et les bons candidat,
  rang et plan. Vérification des documents préparés par le modèle, pas du
  téléchargement effectif dans Chrome.
- Présentation inchangée avant/après ces 288 changements de vue et six sélections
  d'export. Lecture du code : génération et interactions utilisent les mesures
  préexistantes ; aucun appel au résolveur de combat dans le chemin T32.
- Le maintien de l'initial, de l'ordre des trois finalistes et des statuts
  exploratoires est conforme à T31. Les tests livrés couvrent notamment le run
  partiel, un seul finaliste, les points superposés et les états 0/32 et 32/32.

## Inspection visuelle et limite navigateur

Les captures **livrées par Sol** ont été ouvertes et examinées : bureau
1 440 × 1 000 et mobile 390 × 844. Les contrôles, les camps et les cibles sont
visibles ; le bandeau indique clairement l'absence de candidat strict. Cette
inspection porte uniquement sur les portions capturées, pas sur toute la page
ni sur les interactions. La capture fournie par Tristan est cohérente avec ce rendu.

Tentatives de navigateur indépendant :

- `agent-browser`, session dédiée `t32-astra`, échoue au démarrage avec
  `CDP response channel closed`, puis `Auto-launch failed` malgré une reprise.
- Le navigateur intégré refuse ensuite l'ouverture du rapport `file:///…` par
  sa politique d'URL, qui interdit aussi les contournements. Aucun contournement
  ni changement de permissions n'a été tenté.

En conséquence, **clics réels, parcours clavier, téléchargement navigateur,
console, redimensionnement et audit axe-core n'ont pas été rejoués par Astra**.
Les résultats Chrome/axe-core annoncés dans le relais restent des preuves de
Sol, pas des vérifications exécutées pendant cette contre-recette.

## Conclusion et suite

T32 présente fidèlement les résultats T31 sur les contrôles exécutés. La revue
ne demande aucune correction du code. La clôture complète de la contre-recette
visuelle reste à confirmer par le parcours navigateur documenté dans le relais,
lorsqu'un accès autorisé à cette surface est disponible, ou par la recette PO.

Les doutes du PO sur les cibles monotype contre soi-même sont une question de
design distincte : T32 conserve volontairement les 32 objectifs existants.
La revue ne les déplace pas et ne lance ni nouvelle recherche ni T33.
Suite applicative complète, Legacy/export, PHP 8.4 et CI non exécutés ici.
