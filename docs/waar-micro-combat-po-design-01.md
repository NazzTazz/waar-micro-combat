# Premier design d'attendus combat — 9 septembre 2026

Le PO a placé ses attentes dans l'interface puis indiqué que son JSON est enregistré dans Downloads.

- Dernier export trouvé : `C:/Users/trist/Downloads/t26-monotype-equal-cost-acceptance-zones (4).json`, 9 septembre à 01:43:13, 70 754 octets.
- [Copie exacte conservée](../var/waar-micro-combat/objectives/20260909-po-design-01/acceptance-zones.json). Le premier brouillon reçu précédemment reste conservé séparément.
- Validation par `WaarAcceptanceZonesModel.validateAndClassify()` contre le rapport `t26-zone-selection` : 96 zones compatibles, aucune absente ou périmée.
- Les 16 confrontations ont leurs taux et rayons X liés entre camps et axes, conformément à `hasLinkedWinRate()`.
- Les 32 centres Y de l'axe survivants ont changé par rapport aux observations d'origine. Les 64 centres Y structure et valeur économique sont restés initiaux.
- Le fichier contient **96 zones activées et confirmées**, y compris ces deux derniers axes. Les statuts sont conservés exactement, sans conversion.

## Interprétation clarifiée par le PO

Le PO a compris les axes comme des vues du même objectif et a défini les 32 zones survivants. Les 64 autres entrées ne sont pas des intentions supplémentaires. Le fichier original ci-dessus reste une archive exacte, pas l'entrée à utiliser directement pour le solveur. Voir le [contrat corrigé et le JSON canonique](waar-micro-combat-objective-contract-correction.md).

Aucun paramètre de combat, score ou solveur modifié lors de la réception. T24 reste en visualisation seule, avec comparaison souhaitée à budget égal.
