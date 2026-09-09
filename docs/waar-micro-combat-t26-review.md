# Recette T26 — Astra

8 septembre 2026. Verdict : cadrage respecté, deux corrections fonctionnelles demandées avant acceptation sans réserve.

**Contre-recette du 9 septembre 2026 : les deux réserves sont levées sur T26c.** Le verdict ci-dessus et les reproductions ci-dessous décrivent la livraison initiale. Les contrôles ciblés de la correction figurent en fin de document.

T26 prépare le cahier d'objectifs des monotypes à coût égal ; elle ne livre pas le solveur. Cette limite est conforme au relais. Les résultats et artefacts historiques T24/T25 restent inchangés.

## Corrections demandées

### 1. Synchroniser le mode Cercle avec la zone sélectionnée

Activer Cercle sur Lancier attaque Chevalier, puis sélectionner Soldat attaque Soldat. Le mode reste coché alors que les rayons de la nouvelle zone valent encore 0,05 et 0,10. Modifier uniquement Centre X à 0,55 réduit implicitement le rayon Y à 0,05.

Le réglage d'une zone ne doit pas modifier silencieusement la forme d'une autre. Synchroniser le mode avec la sélection ou demander une application explicite. Couvrir le changement de scénario, d'axe et de camp, ainsi que l'annulation/rétablissement.

### 2. Terminer effectivement le geste annulé par Échap

Déplacer la poignée centrale, appuyer sur Échap sans relâcher la souris, puis déplacer encore la souris et relâcher. Le centre revient d'abord à 0,50, puis conserve une nouvelle position (0,639535 dans la reproduction). Cette reprise n'est pas enregistrée comme un nouveau geste dans l'historique.

Après Échap, ignorer les mouvements résiduels jusqu'au relâchement. Vérifier que géométrie, état de modifications non exportées et historique restent ceux d'avant le geste annulé.

Les deux défauts sont reproduits deux fois avec interactions navigateur. Ils sont de sévérité moyenne : l'édition reste possible, mais ces gestes peuvent altérer les objectifs de façon inattendue.

## Vérifications et preuves

- 19 tests PHP du paquet, 9 139 assertions ; 13 tests Legacy/export, 201 assertions ; test Node : réussis.
- Confirmation, désactivation, suppression et historique ; conservation des cibles entre vues ; import T25B refusé sans perte ; export et réimportation du fichier réel : vérifiés.
- Mobile 390 px sans débordement ; audit axe : 0 violation, 0 contrôle incomplet, 41 contrôles réussis.
- HTML et rapport micro T26 conformes aux empreintes livrées ; rapport T24 et HTML T25B inchangés.

[Rapport détaillé et captures](../var/waar-micro-combat/t26-review/report.md). Les vidéos n'ont pas pu être produites (ffmpeg absent). Les limites de la recette sont explicitées dans ce rapport ; les mesures de performance et toutes les poignées de rayon revendiquées par Sol n'ont pas été intégralement rejouées.

Aucune correction applicative effectuée lors de cette recette. Retester les deux gestes après correction ; ne pas confondre leur correction avec une tranche solveur.

## Contre-recette T26c — 9 septembre 2026

Les deux gestes ont été rejoués dans une session Chrome isolée sur le HTML corrigé `A2EACE2C89F1E3C1F0CA89F655B1DF41218442FB10068675FA8B6AF28B764B50`.

- **Cercle : corrigé.** Après activation sur Lancier attaque Chevalier puis sélection de Soldat attaque Soldat, la case est décochée et les rayons restent à 0,05 / 0,10. Modifier Centre X à 0,55 préserve les rayons. Changer d'axe et de camp resynchronise aussi le mode. Annuler restaure l'ellipse et décoche la case ; Rétablir restaure le cercle et la coche. [Capture](../var/waar-micro-combat/t26-recheck/circle-selection.png).
- **Échap : corrigé.** Un drag réel fait passer X de 0,50 à 0,586681. Échap suivi d'un mouvement résiduel et du relâchement conserve X/Y = 0,50/0,50. Annuler reprend bien la saisie antérieure de Y (0,22775), sans geste parasite dans l'historique. Un nouveau drag normal fonctionne et s'annule normalement. [Avant](../var/waar-micro-combat/t26-recheck/escape-before.png), [après](../var/waar-micro-combat/t26-recheck/escape-after.png).
- 19 tests PHP, 9 142 assertions, et test Node du modèle : réussis. Le rapport micro conserve son empreinte `5234BA9A117F82FC33F3F6238278A6C3A339006AD077A4ED9BB88677E1C9680F`.
- Vérification supplémentaire après import du document initial : un drag annulé conserve exactement X/Y = 0,49/0,22775, le statut « Document exporté » et les deux boutons d'historique désactivés. [Capture](../var/waar-micro-combat/t26-recheck/escape-clean.png). Aucune erreur JavaScript remontée ; session de recette fermée.

Contre-recette ciblée sur les deux réserves et leurs interactions avec la sélection et l'historique ; la recette complète, les mesures de performance et l'audit d'accessibilité n'ont pas été répétés. Aucun changement applicatif effectué par Astra.
