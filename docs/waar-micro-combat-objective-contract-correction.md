# Correctif prioritaire pour Sol — objectifs monotypes uniques

Décision PO du 9 septembre 2026 : les trois axes ont été compris comme des représentations des mêmes objectifs. Le PO a défini **32 objectifs**, soit 16 paires × 2 camps, sur les effectifs survivants. Les 64 autres entrées du précédent export ne sont pas des contraintes supplémentaires voulues.

## Contrat de la prochaine tranche

- Le solveur doit consommer uniquement les 32 zones canoniques `yMetric = survivors`, une fois chacune dans le score et les contrôles stricts.
- En monotype, la valeur économique restante normalisée est identique à la proportion de survivants : vue et édition du même objectif, sans duplication dans le JSON ni dans le score.
- La structure restante inclut les dégâts partiels (`remainingStructureMicro`) ; les survivants sont arrondis au nombre entier supérieur. Elle ne se déduit donc pas exactement d'un objectif de survivants. Cette vue reste un diagnostic des simulations, sans nouvelle zone cible.
- Les X attaquant/défenseur restent complémentaires. Y reste indépendant entre les deux camps.
- Les centres, rayons, activations, approbations et provenances des 32 objectifs survivants du PO sont conservés exactement. Aucune demande de ressaisie.
- Ancien export original intact : `var/waar-micro-combat/objectives/20260909-po-design-01/acceptance-zones.json`. Ne pas l'utiliser directement comme 96 contraintes.
- [Export corrigé pour la recherche](../experiments/objectives/20260909-po-design-01-canonical/acceptance-zones.json) : livré, 32 zones activées et confirmées. Les 32 objets sont strictement identiques aux entrées survivants de l'original.

Correction livrée dans `MonotypeObjectiveOverlayBuilder.php`, `acceptance-zones-model.js`, `acceptance-overlay-app.js` et leurs tests. Conserver le travail du solveur, mais adapter son entrée à ce contrat avant recherche. T24 reste en visualisation seule, à budget égal.

## Interface et validation

- Éditeur corrigé (archive non incluse dans ce dépôt : `var/waar-micro-combat/t26-canonical-objectives/report.html`). Importer le JSON corrigé pour retrouver le design PO ; l'ancien export est également accepté et converti avec un message explicite.
- Le schéma JSON reste `waar-acceptance-zones/0.2`, avec génération `canonical-monotype-survivors-v1` et seulement 32 zones `survivors`. `zoneIds.economicValue` renvoie au même identifiant que `zoneIds.survivors`. Aucune zone `structure`.
- Confirmation groupée : au maximum 32 objectifs. Les actions d'édition, historique, suppression, activation et confirmation utilisent la même zone dans les deux vues équivalentes.
- L'import valide également les anciennes entrées retirées ; il conserve les zones manquantes et les incompatibilités de provenance, sans inventer d'objectif ni réancrer silencieusement.
- Tests PHP : 19 tests, 8 823 assertions. Suite Node : conservation exacte des objectifs, conversion idempotente, rejet des données invalides, respect des suppressions et de la provenance.
- Chrome : import du vrai export PO, bilan 32 confirmées, édition économique retrouvée dans survivants, Annuler, structure sans cible ni édition, export puis réimport. Captures : `var/waar-micro-combat/t26-canonical-objectives/qa/`.
- Les livrables HTML T26 historiques et T27A restent intacts ; ce correctif remplace le contrat à 96 contraintes. Il conserve aussi la séparation des libellés apportée par Sol dans T27A.

Note de lecture actuelle : la mention historique de T24 à budget égal est
supplantée par la [spécification T30–T34](waar-micro-combat-t30-t34-spec.md) et
le [relais T34](waar-micro-combat-t34-relay.md) : les compositions et budgets T24,
y compris inégaux, sont conservés en observation seule.
