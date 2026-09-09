# Recette Astra T27B — 9 septembre 2026

T27B acceptée pour son périmètre de prévol des objectifs canoniques. Aucun défaut bloquant identifié sur le parcours livré ; aucune modification applicative effectuée pendant cette recette.

- Lecture du service pur, de la commande, des quatre tests ajoutés et du relais.
- Suite PHP du paquet rejouée : 23 tests / 8 842 assertions, sous PHP CLI 8.2.33. Suite Node réussie. La suite Legacy/export annoncée par Sol n'a pas été rejouée dans cette revue ciblée.
- Nouvelle génération isolée dans `var/waar-micro-combat/t27b-astra-review/` : évaluation identique à celle livrée.
- Recalcul indépendant en JavaScript des observations, cibles, distances elliptiques et contributions : 32 identifiants uniques, une contribution par objectif, 0 objectif atteint.
- Comptage indépendant des nuls depuis les lignes attaquantes : 174 combats sur 3 200, tous dans `archer-vs-knight`. Les deux camps ne doublent pas le nombre de combats.
- Rejets reproduits : ancien export 96 zones, brouillon, désactivation, rayon nul, mauvais corpus, doublon, objectif manquant et X non complémentaires.

Ce prévol mesure uniquement le candidat courant. Le résultat 0/32 ne prouve pas l'impossibilité des objectifs. Aucune exploration de paramètres n'est livrée ou validée par cette recette. Le contrôle séparé d'absence de nuls est conforme à la cible PO, mais ne résout pas le départage encore à définir.

La décision de pondération ou de distance pour guider la recherche reste distincte de la restitution binaire des objectifs atteints fournie ici.
