# Contre-recette Astra T28 — 9 septembre 2026

T28 acceptée sans réserve dans son périmètre de départage défensif versionné. Aucun code applicatif modifié lors de cette revue.

- Lecture du branchement de politique dans le ruleset, de son import/export, des deux branches de départage et des tests ciblés.
- 27 tests PHP / 8 864 assertions et suite Node rejoués avec succès, sous PHP CLI 8.2.33. Les tests couvrent égalité exacte, extinction mutuelle, conservation des résultats détaillés et victoire attaquante déjà acquise. La suite Legacy/export annoncée par Sol n'a pas été rejouée dans cette contre-recette ciblée.
- Nouvelle génération isolée : `var/waar-micro-combat/t28-astra-review/`. Rapport micro et évaluation identiques aux artefacts T28 livrés.
- Recalcul avec le manifeste historique : `var/waar-micro-combat/t28-astra-review-historical/`. Rapport micro strictement identique à T27B, donc conservation du comportement et de la sérialisation historiques sur cette expérience.
- Comparaison indépendante des 32 lignes, pour le témoin et le candidat : métriques de survivants, structure et valeur économique strictement identiques, même moyenne de rounds ; nombre de victoires attaquantes inchangé ; chaque ancien nul devient une victoire défensive, aucune autre victoire déplacée.
- Candidat : 0 nul sur 3 200 combats, contre 174 auparavant. Objectifs PO inchangés, toujours 0/32 atteints. Le contrôle d'absence de nuls passe ; le contrôle global reste en échec sur les objectifs.

Cette validation ne constitue pas une calibration : aucun paramètre n'a encore été exploré. Le cadrage des paramètres de recherche et de la fonction continue reste distinct.
