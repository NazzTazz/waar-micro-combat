# T26 — intentions monotypes exportées par le PO

9 septembre 2026. Le PO a testé l'interface, déplacé les zones et exporté son JSON sans confirmer de contraintes strictes.

## Document reçu

- Source locale : `C:/Users/trist/Downloads/t26-monotype-equal-cost-acceptance-zones.json`, export du 9 septembre à 00:48.
- [Copie exacte conservée](../var/waar-micro-combat/objectives/20260909-po-draft/acceptance-zones.json).
- Validation par `WaarAcceptanceZonesModel.validateAndClassify()` contre le calque T26 courant : format `waar-acceptance-zones/0.2` valide, provenance compatible, aucune zone absente ou périmée.
- 96 zones présentes et activées, toutes en brouillon ; aucune confirmée.
- Comparaison géométrique avec le document T26 initial : 16 zones retouchées, exclusivement les pointes attaquantes sur l'axe `survivors`. Les 80 autres zones sont inchangées.
- Les rayons sont restés à X = 0,05 et Y = 0,10. Ils ne sont pas confirmés comme contraintes strictes.

## Lecture des centres déplacés

Valeurs arrondies ci-dessous uniquement pour la lecture ; le JSON conserve les coordonnées exactes. Chaque cellule indique **victoires de l'attaquant / effectifs attaquants survivants**, en pourcentage. La survie est une moyenne sur tous les combats, pas seulement les victoires.

| Attaquant / Défenseur | Soldat | Lancier | Archer | Chevalier |
|---|---:|---:|---:|---:|
| Soldat | 50,1 / 62,1 | 5,2 / 62,7 | 25,2 / 49,8 | 75,0 / 75,2 |
| Lancier | 5,2 / 49,8 | 5,2 / 75,0 | 30,2 / 74,9 | 17,9 / 74,9 |
| Archer | 75,2 / 50,1 | 75,1 / 24,8 | 55,1 / 24,5 | 25,1 / 24,6 |
| Chevalier | 24,9 / 74,7 | 75,0 / 85,5 | 74,9 / 75,0 | 50,1 / 86,3 |

Ce tableau exprime des intentions à examiner ; il ne prouve ni leur faisabilité simultanée ni l'acceptation d'un modèle de combat. Le candidat courant est hors des 16 ellipses retouchées. Il ne s'agit pas d'un échec de contraintes confirmées, puisqu'il n'y en a aucune.

## Conséquences pour la suite

- Conserver intégralement le document reçu et son statut de brouillon. Aucun `approval` ne doit passer implicitement à `confirmed`.
- Distinguer les 16 intentions retouchées des 80 zones initiales : la présence de ces dernières dans l'export ne signifie pas que le PO souhaite préserver les résultats actuels sur ces axes ou camps.
- La recherche future doit expliciter comment elle utilisera ces intentions non strictes (distance aux zones, pondération éventuelle, restitution des écarts). Aucun score ou choix de pondération n'est validé par cet export seul.
- En monotype, les proportions d'effectifs et de valeur économique restants coïncident au barème fixe ; les brouillons économiques non retouchés ne doivent pas devenir accidentellement des objectifs concurrents des intentions de survie.
- Conserver la démarche en deux passes : caractérisation monotype à coût égal, puis T24 en visualisation seule. Aucune cible T24 n'entre dans la recherche.

Aucun solveur, nouveau paramètre ou changement d'objectif effectué lors de la réception de ce fichier.
