# Soufflerie Waar — calque Legacy et zones d'acceptabilité

8 septembre 2026 — Spécification de livraison pour Sol, issue de la demande du PO.
Statut : à implémenter. Ce document ne vaut ni recette de T24 ni validation du candidat `roles-a`.

Actualisation après livraison T25B : les livraisons A/B ci-dessous décrivent le corpus historique figé. Le PO précise désormais deux passes, détaillées dans le [cadrage actualisé](waar-micro-combat-scope.md) : (1) équilibrer les quatre unités via des confrontations monotypes à coût égal et rechercher des candidats sur ces objectifs ; (2) évaluer ces candidats sur les compositions T24 inchangées, en visualisation seule. T24 ne fournit ni contraintes ni score au solveur. L'interprétation précédente de l'assistant, qui proposait uniquement des armées mixtes à budgets égaux, est abandonnée. Les artefacts historiques restent conservés ; cette note ne livre pas les deux nouvelles passes.

## 1. Résultat produit attendu

Le PO exprime le comportement de combat souhaité en dessinant des zones acceptables sur le graphe, sans devoir manipuler les coefficients du moteur.

Le Legacy fournit une référence mesurée en fond de graphe. Les zones sont un cahier des charges modifiable, pas une obligation de reproduire ce moteur. Une extrémité située dans sa zone satisfait la contrainte : le centre n'est pas un optimum à poursuivre.

**Première livraison : voir le calque, les zones initiales et les vecteurs réels dans une représentation professionnelle. Deuxième livraison : déplacer/redimensionner les zones et les sauvegarder. Aucun solveur inverse dans ces deux livraisons.**

Entrées de départ :

- `packages/waar-micro-combat/experiments/t24-astra-vector-corrections.json` ;
- les résultats T24, dans `var/waar-micro-combat/t24/` ;
- l'oracle `src/Game/Combat/LegacyAggregateCombatResolver.php` et son ruleset `src/Game/Combat/Rules/LegacyCombatRuleset.php` ;
- la caractérisation `docs/combat-legacy-v2.md`.

Le cadrage de [périmètre](waar-micro-combat-scope.md) et le [contrat de calcul](waar-micro-combat-contract.md) restent applicables. Le moteur Arbestra/3.1 reste hors périmètre.

## 2. Sens des éléments : ne pas changer silencieusement les vecteurs

| Élément | Signification | Modifiable dans cette livraison |
|---|---|---|
| Base du vecteur | Résultat du témoin micro-combat T24, pour un scénario et un camp | Non |
| Pointe du vecteur | Résultat du candidat micro-combat T24, même scénario et même camp | Non |
| Repère Legacy | Résultat de l'oracle Legacy sur ces mêmes compositions et ce même camp | Non |
| Zone de base | Ensemble des positions acceptées pour le témoin | Oui, dans la deuxième livraison |
| Zone de pointe | Ensemble des positions acceptées pour le candidat | Oui, dans la deuxième livraison |

Le vecteur principal reste **témoin micro → candidat micro**. L'ajout du calque ne remplace pas sa base par le Legacy. Un repère Legacy unique peut initialiser deux zones distinctes ; on ne lui invente pas deux résultats différents.

Les positions calculées ne sont pas déplaçables. L'utilisateur déplace les objectifs, pas les observations. Les poignées appartiennent aux zones et n'apparaissent qu'en mode édition.

Le témoin est gelé. Une zone de base hors du témoin produit « Base hors zone — témoin gelé ». Ne pas prétendre qu'un futur solveur du candidat pourra corriger cela. L'ouverture des paramètres du témoin sera une décision ultérieure.

## 3. Calque Legacy : données et provenance

### Exporter la référence existante, ne pas réécrire le Legacy

Créer un petit exporteur côté outillage `waar-v3` qui utilise l'oracle existant. Ses dépendances `App\…` restent dans cet outillage : aucune dépendance Symfony/Doctrine/Arbestra ajoutée au paquet autonome `waar-micro-combat`.

La soufflerie consomme un artefact JSON. Une fois cet artefact produit, afficher/masquer le calque, changer d'axe et éditer les zones n'invoque aucun résolveur.

Exporter au minimum :

- version de schéma, identifiant de référence, version du ruleset et empreinte des sources utilisées ;
- empreinte du corpus canonique : identifiants, compositions des deux camps, conventions de contexte ;
- contexte neutre explicitement déclaré et périmètre des conséquences retenues ;
- graine de base, nombre de répétitions, identifiant de dérivation des seeds ;
- identifiants et définitions des métriques, barème économique commun ;
- pour chaque scénario et chaque camp : victoires, nuls, répétitions, agrégats Y et coordonnées dérivées.

La dérivation des seeds doit être identifiée sans ambiguïté ; ne pas revendiquer un appariement statistique entre les RNG de moteurs différents. L'appariement témoin/candidat micro existant reste inchangé.

Rejouer l'export à inputs identiques doit produire des résultats identiques. Pas de date volatile dans la partie de l'artefact qui sert à la comparaison de reproductibilité.

### Comparabilité obligatoire

Pour cette première livraison, garder les compositions exactes de T24, sans les remettre à budget égal entre moteurs. Aucun rééchantillonnage d'adversaires ni variation cachée du contexte.

À composition/contexte fixes, le vainqueur de l'oracle Legacy est déterministe : X vaut donc 0 ou 1. C'est normal, et l'interface doit le dire. Les répétitions concernent les arrondis de pertes ; elles ne rendent pas la victoire aléatoire. Ne pas lisser artificiellement ces points vers l'intérieur du graphe.

La comparaison porte sur les **effectifs encore opérationnels à l'issue du duel**, avant traitement hospitalier :

- Legacy : effectifs initiaux moins pertes brutes, ou catégorie `valid` du résultat ; ne pas utiliser les seuls morts comme pertes, ni inclure les hospitalisés dans les opérationnels ;
- micro-combat : survivants, y compris l'unité partiellement endommagée qui continue à agir selon le contrat ;
- vérifier cette projection sur un petit exemple avec infirmerie et sur un exemple où sa capacité est dépassée. L'export doit rester indépendant du nombre de lits pour ces métriques.

Le ruleset Legacy courant comprend une infirmerie de 500 places ; sa structure minimale de blessé est un marqueur technique, pas une mesure physique comparable. La documentation historique comporte des mentions de périmètre à interpréter avec le code et les tests, pas à recopier aveuglément.

| Axe Y | Calque Legacy | Convention |
|---|---|---|
| Effectifs opérationnels restants | Oui | Moyenne des opérationnels / effectif initial, tous les combats |
| Valeur économique opérationnelle restante | Oui | Même calcul pondéré par un barème commun fixe, tous les combats |
| Structure restante | Non, dans cette livraison | Résistance micro sans équivalent Legacy démontré |

Barème commun proposé pour l'expérience initiale : Soldat 80, Lancier 110, Archer 130, Chevalier 350, repris du témoin T24, avec identifiant `t24-common-valuation-v1`. C'est une convention de mesure, pas une modification des coûts ni du moteur Legacy. Ne pas comparer directement les coûts historiques 10/70/70/550 aux coûts micro.

L'évaluateur du calque doit calculer la valeur des trois jeux de résultats avec ce même barème. Si une future expérience change les coûts du candidat, ne pas réutiliser silencieusement ses agrégats économiques natifs comme métrique commune.

Les numérateurs sont agrégés sur toutes les répétitions, défaites et nuls inclus ; denominator nul = `null`. Exposer aussi moyenne absolue, valeur initiale et nombre de répétitions.

Sur Y = structure, conserver le graphe micro et ses éventuelles zones manuelles, mais afficher « Calque Legacy indisponible pour cette métrique ». Ne pas transférer les zones effectifs ou valeur vers cet axe.

## 4. Zones : une géométrie de contraintes, pas une image

Le fond est vectoriel et piloté par les données, pas une capture bitmap. Une zone appartient exactement à :

`corpus + scénario + camp + extrémité (base/pointe) + paire de métriques + conventions de mesure`.

Première forme supportée : ellipse alignée sur les axes. Un cercle est son cas particulier avec deux rayons égaux dans l'espace des données. Pas de polygones, rotation ou unions de zones à ce stade.

Coordonnées normalisées entre 0 et 1, stockées indépendamment de la taille de l'écran :

```text
inside = ((x - centerX) / radiusX)^2 + ((y - centerY) / radiusY)^2 <= 1
```

Les rayons sont strictement positifs ; toutes les coordonnées sont finies. Utiliser une petite tolérance numérique documentée pour la frontière, identique dans les implémentations qui évaluent l'appartenance. Les extrémités sont évaluées sur leurs coordonnées finales, jamais sur une position animée.

Le centre reste dans [0,1]². L'ellipse peut déborder : la zone admissible est son intersection avec [0,1]², et le dessin est découpé à la limite du plot. Un centre Legacy à X = 0 ou 1 ne doit pas être déplacé artificiellement pour faire tenir le cercle.

### Initialisation proposée, explicitement provisoire

À la première importation, proposer pour chaque repère comparable deux zones centrées sur le Legacy : base et pointe. Rayons initiaux proposés : **X = 5 points de pourcentage, Y = 10 points**. Les afficher comme « Tolérances proposées — à confirmer », pas comme résultat de l'audit ni décision déjà prise par le PO.

Ces rayons sont un réglage initial visible et modifiable. Les zones peuvent être déplacées loin du Legacy pour corriger volontairement son gameplay. Ne jamais les agrandir automatiquement pour faire entrer le candidat.

Toutes les zones commencent en brouillon. L'utilisateur peut les confirmer individuellement ou confirmer explicitement les propositions en groupe. Le tableau distingue les brouillons des contraintes confirmées.

### États et comptage

- `inside` : extrémité dans la zone, frontière comprise ;
- `outside` : extrémité hors zone ;
- `not-applicable` : métrique absente ou non comparable ;
- `disabled` : zone volontairement désactivée ;
- `stale` : provenance incompatible avec l'expérience courante.

L'absence de zone n'est pas une réussite. Le bilan affiche « N / M contraintes confirmées satisfaites », avec brouillons, désactivées, incompatibles et non applicables séparés. Changer le filtre de camp ne change pas le bilan global ; un bilan de la sélection doit être nommé comme tel.

Ce bilan concerne les estimations courantes. **Ne pas afficher « équilibrage validé » ou une garantie statistique.** Les zones de tolérance ne sont pas des intervalles de confiance. L'ajout ultérieur de l'incertitude ne devra pas mélanger leurs codes visuels.

## 5. Direction artistique : un instrument de travail professionnel

Priorité au graphe et à la lisibilité, pas à une décoration médiévale. Garder une identité sobre Waar : graphite, texte ivoire lisible, accent discret. Titre compact en sans-serif ; chiffres tabulaires dans les valeurs. Supprimer le grand titre éditorial qui repousse le graphe sous la ligne de flottaison.

Composition desktop cible :

```text
Soufflerie Waar      Expérience T24 · référence Legacy versionnée     Importer / Exporter
Axe Y [Effectifs]    Camp [Prévu]    [Calque Legacy] [Zones]           Lecture / Édition

┌─────────────────────────────────────────────┬──────────────────────────┐
│                                             │ Scénario sélectionné     │
│       GRAPHE : observations + calque         │ Camp et extrémité        │
│       Légende compacte intégrée             │ Base / pointe / Legacy   │
│                                             │ Zone : centre, tolérance │
│                                             │ État et confirmation     │
└─────────────────────────────────────────────┴──────────────────────────┘
Contraintes confirmées : N / M satisfaites · brouillons et incompatibilités
Tableau détaillé repliable : compositions, valeurs absolues, provenance
```

Le panneau latéral sert au scénario sélectionné, jamais à quarante coefficients. Pas de solveur fictif ni de bouton inactif « Optimiser ».

### Ordre des couches et langage graphique

1. Grille neutre fine, axes 0–100 %, graduations stables ; aucun auto-zoom au changement d'axe.
2. Zones : aplats légers, environ 6–10 % d'opacité ; contours fins. Base = contour discontinu ; pointe = contour continu. Doubler le code couleur par ces styles et par les libellés.
3. Repères Legacy : petits losanges neutres, fixes et identifiables au survol/sélection. Éviter de répéter « Legacy » sur chaque point.
4. Vecteurs micro : origine évidée, destination pleine et vraie flèche ; couleur stable par scénario. Une égalité reste un point, sans pointe de flèche arbitraire.
5. Sélection : mise en avant du scénario choisi, poignées d'édition et infobulle. Les autres scénarios sont atténués mais restent consultables.

Afficher les zones du scénario sélectionné à opacité normale et celles des autres très atténuées ; fournir « Toutes les zones » pour examiner le cahier des charges complet. Conserver un moyen de sélectionner chaque scénario superposé via la légende/liste.

Ne pas recolorer tout le vecteur en rouge/vert selon sa conformité : préserver l'identité du scénario. État textuel accompagné d'un indicateur discret dans le panneau et le tableau.

Les libellés complets restent accessibles sans survol dans le détail. Masquer/abréger une annotation si elle ne tient pas ; ne jamais déplacer les coordonnées de mesure pour séparer les points. Aucune boucle de collision sans borne.

Animations : transition courte 150–200 ms sur changement de vue, interrompable, depuis la dernière position visible ; aucune animation décorative continue. Pendant le drag, réponse directe sans retard interpolé. Respecter `prefers-reduced-motion`.

### Responsive et accessibilité

- À 1440 × 900, graphe et panneau de sélection visibles sans passer un écran de titre.
- À 1024 px, conserver un graphe exploitable ; empiler le panneau si nécessaire.
- À 390 px, contrôles repliés sur plusieurs lignes, panneau sous le graphe, annotations réduites ; pas de simple réduction homothétique rendant le texte minuscule.
- Texte de graphique au moins 12 px à l'écran ; contraste vérifié sur les aplats et les états atténués.
- Contrôles natifs nommés, focus visible, alternative clavier/numérique aux gestes de souris, état de zone accessible en texte. Une souris n'est pas obligatoire pour fixer centre et rayons.
- L'infobulle donne scénario, camp, extrémité, référence, X/Y, unités, effectifs, répétitions et statut. Ne pas annoncer chaque frame aux lecteurs d'écran.

## 6. Interactions de la deuxième livraison

1. Sélectionner un scénario, puis « Base » ou « Pointe ».
2. Passer explicitement en mode édition ; la zone choisie affiche centre et poignées horizontale/verticale.
3. Déplacer le centre ou modifier les rayons. Un mode « Cercle » lie les deux rayons en unités de données ; le mode « Ellipse » les sépare.
4. Le panneau affiche immédiatement les nouvelles tolérances en points de pourcentage et l'état dedans/dehors du résultat actuel.
5. Relâcher confirme le geste ; Échap l'annule. Prévoir annulation/rétablissement des gestes validés et des suppressions, sans y mêler les simples sélections.
6. Confirmer ou désactiver la contrainte, puis exporter.

Changer Y restitue les zones propres à cette métrique, sans écraser les autres. Changer de camp préserve l'association de chaque zone. Le mode « deux camps » doit rester utilisable avec douze vecteurs et les zones associées.

Masquer un calque ne désactive pas ses contraintes ; désactiver une zone est une action distincte. Modifier une zone ne modifie ni les paramètres ni les résultats.

Chargement d'un corpus différent : signaler les zones incompatibles et demander un réancrage explicite ; jamais de correspondance par index ou libellé. Recalcul d'un candidat sur le même corpus et les mêmes conventions : les zones restent valables, seuls leurs états changent. Une modification de composition avec le même identifiant invalide l'ancienne association.

## 7. Persistance et sécurité des imports

Livrer des schémas validés et versionnés séparés pour `legacy-reference.json` et `acceptance-zones.json`. Ne pas casser les expériences/rapports T23 et T24 ; enrichissement explicite ou nouvelle version de rapport si nécessaire.

Exemple de zone **illustratif, non issu d'un calcul Legacy** :

```json
{
  "schemaVersion": "waar-acceptance-zones/0.1",
  "experimentId": "t24-astra-vector-corrections",
  "corpusFingerprint": "<empreinte du corpus canonique>",
  "comparisonProfileId": "operational-common-value-v1",
  "valuationId": "t24-common-valuation-v1",
  "zones": [
    {
      "id": "archers-attack-tip-counts",
      "scenarioId": "archers-against-spears",
      "side": "attacker",
      "endpoint": "tip",
      "xMetric": "winRate",
      "yMetric": "operationalSurvivorsRatio",
      "shape": "ellipse",
      "center": { "x": 0.7, "y": 0.3 },
      "radii": { "x": 0.05, "y": 0.1 },
      "enabled": true,
      "approval": "draft",
      "source": { "kind": "manual" }
    }
  ]
}
```

Une zone issue du calque indique `source.kind = legacy` et l'identifiant de référence ; conserver cette provenance après édition avec un état « modifiée manuellement ». Le centre original reste disponible dans l'artefact de référence.

Importer/exporter via fichiers locaux, sans compte, base de données ou endpoint supplémentaire. Le rapport autonome embarque la référence et le dernier jeu de zones exporté ; le JSON de zones reste la source portable des modifications. Afficher clairement « Modifications non exportées » ; ne pas promettre une sauvegarde automatique du fichier HTML ouvert.

Refuser les identifiants inconnus, doublons de zones pour une même clé, formes non prises en charge, valeurs non finies et rayons nuls/négatifs. Définir et annoncer une limite de taille raisonnable des imports. Un import invalide conserve l'état courant intact.

Tous les libellés importés sont des données : rendu textuel/échappé, aucun HTML exécutable dans légende, tableau, infobulle ou panneau. Les paramètres affichés proviennent des données réellement comparées, jamais de phrases figées sur T24.

## 8. Choix technique : ECharts, pas un nouveau moteur de dessin

Utiliser **Apache ECharts** pour axes, points, segments fléchés, légende, interactions et stratégie de libellés. Une fine couche de formes graphiques pour les ellipses et leurs poignées est acceptable ; ne pas recréer à côté le système d'axes ou le placement général des libellés.

La documentation officielle décrit les [éléments graphiques déplaçables et conversions données/pixels](https://echarts.apache.org/handbook/en/how-to/interaction/drag/), ainsi que la [gestion des libellés et interactions](https://echarts.apache.org/handbook/en/basics/release-note/v5-feature/). Les gestes d'édition demandent du code applicatif : ECharts n'est pas un éditeur de contraintes prêt à l'emploi.

Version exacte épinglée et licence conservée. Rapport réellement autonome : bundle local embarqué à la génération, pas un CDN indispensable à l'ouverture. L'échec de chargement doit produire un message visible, pas un graphe vide.

La géométrie écran est obtenue par les conversions du graphique et recalculée au redimensionnement. Les critères d'appartenance utilisent les coordonnées de données non arrondies. Le frontend peut évaluer cette géométrie de présentation ; aucune règle de combat ne migre vers JavaScript.

## 9. Découpage et critères de recette

### Livraison A — calque lisible et traçable

Périmètre : export Legacy pour le corpus T24, profil de mesure commun, import/embarquement, migration du graphe vers ECharts, génération des zones proposées et lecture des états. Pas encore de drag.

Attendus :

- nouvelle expérience/artefacts dans un répertoire distinct, sans écraser T23/T24 ;
- références effectifs et valeur pour les six scénarios et les deux camps ;
- calque indisponible explicitement sur structure ;
- identification immédiate de la base, pointe, référence et zone de chaque extrémité ;
- toutes les combinaisons des trois axes et des quatre filtres de camp testées, points superposés compris ;
- tests du vainqueur Legacy déterministe, projection opérationnelle avec/sans saturation des lits, coûts communs et dénominateurs nuls ;
- tests géométriques centre/frontière/extérieur, domaine tronqué, métrique absente ;
- preuve de reproductibilité des références et absence de modification des résultats T24 ;
- mesures séparées du calcul de référence, du chargement HTML et des changements de vue ; ne pas annoncer du temps réel sur la seule durée d'un duel ;
- captures 1440 × 900, 1024 px et 390 px, avec références et zones réelles ; un scénario sélectionné et la vue complète ;
- reprise de la recette navigateur après T24, notamment « Tous les attaquants » et « Les deux camps ». Le relais T24 ne constitue pas cette preuve.

**Arrêt A : le PO peut lire son calque et juger sa représentation. Pas de recherche de nouveaux paramètres.**

### Livraison B — dessiner et sauvegarder le cahier des charges

Ajouter déplacement/redimensionnement, saisie numérique, confirmation/désactivation, annulation/rétablissement, import/export des zones et détection de provenance périmée.

Recette :

- round-trip JSON préservant exactement centres, rayons, associations et confirmations ;
- déplacement/redimensionnement aux quatre bords, rayons minimum et maximum documentés ;
- changements rapides d'axe/camp, redimensionnement fenêtre et mode mouvement réduit ;
- édition clavier seule et comportement responsive ;
- zéro invocation du moteur ni requête réseau pendant édition/changement de vue ;
- statut immédiat, sans optimiser vers le centre ni déplacer les points réels ;
- import hostile traité comme texte et import invalide sans écraser la session ;
- bilan global stable lorsqu'on masque une couche ou filtre les camps ;
- nouvelle composition sous le même ID détectée comme incompatible.

**Arrêt B : le PO exporte un cahier des charges visuel versionné. Le solveur sera une tranche distincte.**

## 10. Hors périmètre et relais attendu

Hors périmètre : optimisation inverse, activation d'un ruleset, retouche du quadruplet/corpus, migration de la stack Legacy, intégration combat en production, famille probabiliste d'adversaires, extension Arbestra, validation statistique de l'équilibrage.

Les tests permanents/mutations du noyau restant après T22/T23 restent une dette explicite à traiter avant de faire confiance à une recherche automatique. Ne pas les déclarer faits au titre de cette livraison visuelle.

Sol consigne dans un relais : chemins source/artefacts, commandes reproductibles, version de bibliothèque, conventions de mesure effectivement appliquées, tests exécutés, captures, latences mesurées et limites restantes. Ne pas annoncer la livraison B avec une simple maquette de poignées non fonctionnelles.

La prochaine décision du PO doit pouvoir porter sur **les zones qu'il veut accepter**, pas sur une nouvelle liste de coefficients à régler.
