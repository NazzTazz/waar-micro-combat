# Profil de première utilisation

Réglages de démonstration choisis le 20 septembre 2026, appliqués uniquement en l’absence de brouillon local ou lors du chargement explicite du profil par défaut.

- Unités : valeurs Test 2 conservées (coûts 10 / 70 / 70 / 550).
- Aucun contre.
- Combat : 20 rounds, reddition activée à 50 %, compression 5 %, prisonniers 10 %.
- Plafond réglable des prisonniers : 50 %, validé par l’atelier et les moteurs PHP/Rust. La formule de capture puis compression reste inchangée.

## Météo inspirée du legacy

Inspection autorisée des services météo et armée du dépôt local waar-sf, sans copie de leur code. Le legacy réduit la puissance du seul type concerné : facteur 1 − impact × 25 / 10000, avec absence de malus pour impact ≤ 25.

Les presets de la soufflerie sont une adaptation fixe, pas une reproduction de la météo dynamique du jeu : impact représentatif 50 pour les conditions intermédiaires (×0,875) et 100 pour les fortes (×0,75). La précision reste à ×1 partout ; les autres unités ne sont pas affectées.

| Unité | Intermédiaire ×0,875 | Forte ×0,75 |
|---|---|---|
| Soldat | Blizzard | Froid mordant |
| Lancier | Ensoleillé | Canicule |
| Archer | Vents violents | Tempête |
| Chevalier | Pluies diluviennes | Orages |

Beau temps et Nuageux restent neutres. L’ordre Blizzard/Froid mordant suit la correspondance des libellés constatée dans le legacy. Les valeurs fixes choisies sont expérimentales et ne constituent pas une règle du moteur Waar actuel.
