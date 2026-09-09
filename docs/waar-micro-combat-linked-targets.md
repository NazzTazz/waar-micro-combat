# Objectifs de victoire complémentaires

9 septembre 2026. Décision PO : Waar n'accepte aucun match nul ; les objectifs attaquant et défenseur doivent donc être liés horizontalement.

## Interface livrée

Rapport autonome (archive non incluse dans ce dépôt : `var/waar-micro-combat/t26-linked-targets/report.html`).

- Déplacer ou saisir X pour un camp impose `X adverse = 1 - X`.
- Le taux de victoire est commun aux trois vues d'une même confrontation : la liaison met à jour les six zones existantes (deux camps × trois axes), même masquées. Elle ne touche pas une autre confrontation ni le scénario aux rôles inversés.
- La tolérance horizontale est partagée : changer le rayon X le reporte aux autres zones de la paire. Les centres Y et les rayons Y des autres zones restent indépendants. Le mode Cercle garde son effet explicite sur la zone en cours d'édition uniquement.
- Déplacement, saisie, liaison explicite, Annuler/Rétablir et Échap agissent sur un snapshot du document complet, donc sur tous les objectifs liés ensemble.
- Les statuts d'approbation et d'activation sont conservés. Une liaison ne confirme pas de brouillon et ne réactive pas de zone.
- Les nouveaux objectifs démarrent avec X attaquant repris de l'observation, X défenseur complémentaire et Y inchangés. La provenance conserve les coordonnées observées originales ; cette initialisation n'altère pas les résultats du prototype.
- Un ancien JSON s'importe sans conversion silencieuse. Les paires dont les X ou tolérances divergent affichent une explication et un bouton « Lier les X depuis ce camp et cet axe ». Une modification horizontale lie aussi la paire depuis la zone éditée. L'import seul, les changements de vue et les modifications purement verticales ne normalisent pas l'ancien document.

Les objectifs anciens éventuellement non liés restent exportables comme brouillons : la présence d'un fichier exporté ne valide pas leur cohérence pour un futur solveur.

## Écart moteur à traiter avant calibration finale

Le micro-prototype produit encore `mutual-extinction` et `round-limit-equality` avec `winner = null`. La décision PO impose un vainqueur unique pour la cible Waar. Il reste à définir puis tester le départage de ces cas avant la calibration finale ; aucun départage arbitraire n'a été ajouté dans cette modification d'éditeur. Les points mesurés gardent leurs vrais taux, même lorsque leur somme est inférieure à 100 %.

## Sources et vérifications

- Logique pure des objectifs : `acceptance-zones-model.js`, fonctions `linkWinRate()` et `hasLinkedWinRate()` ; couverture Node pour réciprocité, frontières, partage sur trois axes, Y/provenance/approbations inchangés, isolement des autres scénarios et restauration complète.
- Génération PHP : `MonotypeObjectiveOverlayBuilder`, complémentarité testée tout en préservant les coordonnées mesurées originales.
- Interface : `acceptance-overlay-app.js` et `acceptance-overlay.html` ; saisie limitée au champ réellement modifié pour ne pas arrondir ou lier X lors d'un changement de Y.
- 19 tests PHP, 9 142 assertions ; tests Node et syntaxe JavaScript : réussis.
- Chrome : 0,75 attaquant → 0,25 défenseur sur plusieurs axes ; édition défenseur à 0,40 → attaquant 0,60 ; Annuler/Rétablir ; drag réel et Échap avec mouvement résiduel ; état exporté conservé après annulation.
- Export JSON vérifié : X liés sur les six zones, Y et rayons Y exacts, approbations en brouillon, autres paires inchangées. Ancien export PO importé, lié explicitement puis annulé sans perte.
- Rapport micro identique à T26c. Aucun solveur, changement de pertes ou modification du résolveur.
- Captures et audit navigateur : `var/waar-micro-combat/t26-linked-targets/qa/`.

Reproduction :

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php packages/waar-micro-combat/experiments/t26-monotype-equal-cost.json var/waar-micro-combat/t26-linked-targets
```
