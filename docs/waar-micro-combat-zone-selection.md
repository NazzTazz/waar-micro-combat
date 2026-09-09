# Sélection directe des zones — 9 septembre 2026

Rapport autonome (archive non incluse dans ce dépôt : `var/waar-micro-combat/t26-zone-selection/report.html`).

- Cliquer au centre d'une zone sélectionne son camp et met à jour le panneau « Zone sélectionnée », en lecture comme en édition.
- Le survol renforce légèrement le remplissage et le contour dans la couleur du camp : turquoise pour l'attaquant, corail pour le défenseur.
- Lorsque les centres sont superposés à deux pixels près, les clics successifs alternent entre les zones concernées. Le menu de camp reste disponible.
- Le déplacement nécessite toujours le mode édition. Un drag conserve le camp choisi ; la liaison des X et l'historique restent opérationnels.
- Sélection et survol ne modifient ni les coordonnées ni les approbations du document.

Sources : `packages/waar-micro-combat/resources/acceptance-overlay-app.js` et `acceptance-overlay.html`.

Recette Chrome : clics dans les deux sens en lecture et édition ; superposition exacte à X = Y = 0,5 ; drag suivi d'Annuler ; état exporté conservé après sélection ; aucune erreur JavaScript. Captures dans `var/waar-micro-combat/t26-zone-selection/qa/`.

Vérifications : syntaxe JavaScript, suite Node du modèle et 19 tests PHP / 9 142 assertions réussis. Objectifs initiaux et rapport micro identiques au livrable de liaison horizontale.

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php packages/waar-micro-combat/experiments/t26-monotype-equal-cost.json var/waar-micro-combat/t26-zone-selection
```
