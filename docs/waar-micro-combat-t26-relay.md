# Relais T26 — objectifs monotypes à coût égal

8 septembre 2026 — passe 1 livrée, arrêt pour définition humaine des objectifs.

## À ouvrir

- Rapport autonome : `var/waar-micro-combat/t26/report.html`
- Cahier d'objectifs initial : `var/waar-micro-combat/t26/acceptance-zones.json`
- Données du calque : `var/waar-micro-combat/t26/overlay.json`
- Résultats micro : `var/waar-micro-combat/t26/micro-report.json`
- Expérience rejouable : `packages/waar-micro-combat/experiments/t26-monotype-equal-cost.json`
- Captures : `var/waar-micro-combat/t26/captures/desktop-1440x900-objectives.png`, `tablet-1024x900-objectives.png` et `mobile-390x844-objectives.png`

Reproduction :

```powershell
php packages/waar-micro-combat/bin/run-monotype-objectives.php
```

## Surface livrée

La passe 1 contient les 16 confrontations ordonnées de la matrice 4 × 4, y
compris les quatre miroirs. Chaque camp engage exactement 400 400 de valeur au
barème `80/110/130/350` : 5 005 Soldats, 3 640 Lanciers, 3 080 Archers ou
1 144 Chevaliers. Le builder refuse une matrice incomplète, un doublon, une
armée non monotype et toute inégalité réelle de budget.

Le rapport montre le témoin neutre et le candidat `roles-a` actuel. Les couleurs
regroupent les vecteurs par type attaquant. Les trois axes restent disponibles :
survivants, structure et valeur économique. La vue prévue affiche les 16
attaquants ; la vue « Les deux camps » expose les 32 observations.

Le cahier contient 96 zones de pointe : 16 scénarios × 2 camps × 3 axes. Elles
sont toutes en brouillon. Leur centre initial reprend l'observation `roles-a`
comme commodité d'édition ; ce centre n'exprime aucun objectif accepté. Le PO
peut déplacer ou saisir les zones, les confirmer, les désactiver, utiliser
l'historique et exporter son document JSON.

La correction T26c lie désormais l'état du mode Cercle à la zone sélectionnée.
Changer de scénario, d'axe ou de camp ne reporte plus ce mode sur une autre
ellipse. Annuler et Rétablir le resynchronisent avec la géométrie restaurée ;
une désactivation explicite reste effective sur la zone courante. Échap invalide
immédiatement le drag actif : les mouvements résiduels avant relâchement sont
ignorés et ne modifient ni la géométrie, ni l'état d'export, ni l'historique.

L'import T25B est refusé parce que ses identifiants T24 n'appartiennent pas au
corpus monotype. L'état T26 courant est conservé en cas de rejet. La provenance
propre à T26 utilise le profil `monotype-equal-cost-v1`, la valorisation
`t24-common-valuation-v1`, la référence `roles-a@t23.0` et l'empreinte de corpus
`fe04b09795eed9760e4c75f0b4ecb8840a0857554a6ed090049c041e9f98c48f`.

## Sources et vérifications

- Construction pure : `packages/waar-micro-combat/src/Experiment/MonotypeObjectiveOverlayBuilder.php`
- Lanceur : `packages/waar-micro-combat/bin/run-monotype-objectives.php`
- Interface partagée : `packages/waar-micro-combat/resources/acceptance-overlay.html` et `acceptance-overlay-app.js`
- Modèle portable : `packages/waar-micro-combat/resources/acceptance-zones-model.js`
- Tests T26 : `packages/waar-micro-combat/tests/MonotypeObjectiveOverlayTest.php`

Vérifications réussies : 19 tests PHP du paquet et 9 142 assertions ; 13 tests
Legacy/export et 201 assertions ; test Node du modèle ; lints PHP, syntaxe
JavaScript et JSON. La recette Chrome couvre les trois axes, les quatre vues de
camp, 32 lignes en vue complète, une édition défense/structure conservée après
changement d'axe, un import T25B rejeté sans mutation et 48 changements rapides
d'axe/camp sans erreur. Axe-core 4.12.1 rapporte 0 violation et 0 contrôle
incomplet sur 41 règles. Aucun débordement horizontal de page n'apparaît à
1 024 px ou 390 px.

Recette corrective T26c : Cercle activé sur une zone puis changement de
scénario, d'axe et de camp sans altération des rayons de la nouvelle ellipse ;
Annuler/Rétablir rejoués avec synchronisation du mode. Un drag réel a ensuite
été annulé par Échap avant relâchement : retour à `0,50 / 0,50`, aucune mutation
supplémentaire, puis Annuler a repris l'ancienne saisie attendue. Aucune erreur
JavaScript ; audit axe-core inchangé à 0 violation et 0 contrôle incomplet.

Deux générations successives sont strictement identiques. SHA-256 :

- zones : `DBA2EBC45272C6FE865F81F22903EC7A78B20DDF8FEEB8FAF1377541CBE6C760`
- overlay : `2BAFD5C27DC2A401B0D84E83CEB2C290ED80F09356FDE6F5BBCF1FCAE7DE9021`
- rapport micro : `5234BA9A117F82FC33F3F6238278A6C3A339006AD077A4ED9BB88677E1C9680F`
- HTML corrigé T26c : `A2EACE2C89F1E3C1F0CA89F655B1DF41218442FB10068675FA8B6AF28B764B50`

Les empreintes historiques T24, T25A2 et T25B ont été revérifiées inchangées.

## Arrêt de tranche

T24 et Legacy ne fournissent aucune zone, contrainte ou composante de score à
cette passe. Aucun solveur, score d'optimisation, recherche de paramètres ou
activation de ruleset n'a été exécuté. La suite dépend du cahier monotype que le
PO aura défini et exporté dans le rapport T26.
