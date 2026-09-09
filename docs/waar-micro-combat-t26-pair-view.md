# T26 — édition par paire

9 septembre 2026. Demande PO : réduire le nombre de zones simultanées, distinguer les camps par couleur et relier les deux camps de la paire sélectionnée en pointillés.

## Livrable

[Rapport autonome](../var/waar-micro-combat/t26-pair-view/report.html). T26c reste disponible à son chemin historique. Les JSON T26 déjà exportés restent importables, sans changement de provenance ou de statut.

- La vue ouvre uniquement la paire sélectionnée, avec les deux camps et leurs deux zones sur l'axe courant.
- Attaquant turquoise, défenseur corail ; les points défenseurs utilisent aussi un losange, contre un cercle pour l'attaquant. La poignée centrale reprend la couleur du camp édité.
- Un segment pointillé sans flèche relie les centres des deux zones-cibles. C'est l'interprétation retenue en attendant la réponse à la clarification sur les extrémités du lien. Le segment suit l'édition ; il n'est ni une trajectoire calculée ni une contrainte entre les camps. Il disparaît si un camp est masqué, si une zone manque ou est désactivée, ou si le calque des objectifs est masqué.
- Décocher « Paire sélectionnée uniquement » restitue la vue globale. Les autres zones restent masquées jusqu'à l'activation explicite de « Afficher les zones des autres paires ».
- Les filtres ne changent ni les objectifs ni le bilan global. Les fichiers exportés restent complets.

## Sémantique retenue par le PO

Le PO prévoit de refaire ses zones en pensant à l'intensité interne du combat. Les coordonnées restent les taux de victoire et les proportions survivantes/structurelles/économiques du micro-combat ; aucune nouvelle métrique d'intensité n'a été créée. La projection éventuelle vers les pertes appliquées dans Waar reste une décision distincte. La compression linéaire à 5 % discutée précédemment n'est pas implémentée ni présentée comme une règle validée.

## Sources et reproduction

Sources : `packages/waar-micro-combat/resources/acceptance-overlay-app.js`, `acceptance-overlay.html` et `src/Experiment/MonotypeObjectiveOverlayBuilder.php` dans le même paquet. La nouvelle vue est activée explicitement par les métadonnées UI monotypes.

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php packages/waar-micro-combat/experiments/t26-monotype-equal-cost.json var/waar-micro-combat/t26-pair-view
```

## Vérifications

- 19 tests PHP, 9 142 assertions ; modèle Node ; syntaxe JavaScript : réussis.
- Navigateur : 2 lignes / 2 zones / 1 lien en vue par paire ; 32 lignes / 2 zones en vue globale ; 32 zones sur activation explicite des autres zones.
- Lien contrôlé après édition défenseur et annulation. Changements d'axe, de camp et de vue vérifiés. Le bilan conserve les 96 brouillons.
- Import du document PO, changement de vue et édition annulée, puis réexport : contenu JSON strictement identique à l'original.
- Rapport micro et zones initiales strictement identiques à T26c ; aucun paramètre ou résultat de combat modifié.
- Mobile 390 px sans débordement ; audit axe sans violation ni contrôle incomplet ; aucune erreur JavaScript remontée.
- Captures et audit dans `var/waar-micro-combat/t26-pair-view/qa/`.
