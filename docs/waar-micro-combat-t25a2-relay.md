# Relais T25A2 — calque Legacy et zones proposées

8 septembre 2026 — livraison A terminée, arrêt pour recette humaine.

## À ouvrir

- Rapport autonome : `var/waar-micro-combat/t25a2/report.html`
- Contraintes proposées : `var/waar-micro-combat/t25a2/acceptance-zones.json`
- Données fusionnées : `var/waar-micro-combat/t25a2/overlay.json`
- Référence embarquée : `var/waar-micro-combat/t25a2/legacy-reference.json`
- Rapport micro inchangé : `var/waar-micro-combat/t25a2/micro-report.json`
- Captures : `var/waar-micro-combat/t25a2/captures/desktop-1440x900.png`, `desktop-1440x900-all-zones.png`, `tablet-1024x900.png` et `mobile-390x844.png`

Reproduction :

```powershell
php tools/export-waar-micro-legacy-reference.php
php packages/waar-micro-combat/bin/run-acceptance-overlay.php
```

## Sources et contrats

- Fusion pure : `packages/waar-micro-combat/src/Experiment/AcceptanceOverlayBuilder.php`
- Géométrie : `AcceptanceZoneEvaluator.php`, frontière incluse avec tolérance `1e-12` et domaine tronqué à `[0,1]²`
- Rendu : `AcceptanceOverlayRenderer.php` et `resources/acceptance-overlay.html`
- Lanceur séparé : `bin/run-acceptance-overlay.php`
- Schéma portable : `schema/acceptance-zones.schema.json`
- Tests : `tests/AcceptanceOverlayTest.php`
- Apache ECharts 5.6.0 embarqué localement : `resources/vendor/echarts-5.6.0.min.js`, SHA-256 `BF4A223524E40B77C304BEC67E1222CF551F14880CF42C69DC046558E11C07B1`; licence Apache 2.0 conservée à côté.

Le document portable contient uniquement les contraintes et leur provenance. Les
états `inside/outside` restent dérivés dans `overlay.json`, afin qu'un futur
recalcul du candidat ne transporte pas un état périmé.

## Ce qui est figé et ce que montre le calque

Le corpus, le témoin, `roles-a`, les 200 répétitions, la seed 42 et le barème
`80/110/130/350` restent ceux de T24/T25A1c. Aucun paramètre n'a été exploré.
Le vecteur reste témoin micro → candidat micro.

Le rapport contient 12 vecteurs, 12 losanges Legacy et 48 ellipses : deux axes
comparables × deux camps × six scénarios × base/pointe. Toutes commencent en
brouillon, centrées sur Legacy avec rayons X `0,05` et Y `0,10`. Les 48
extrémités sont actuellement hors de leur proposition, principalement parce que
les pertes Legacy sont beaucoup plus faibles. Ce constat n'est ni une
validation, ni une demande d'optimiser vers le centre.

Sur Structure, le graphe micro reste disponible ; les repères et ellipses Legacy
sont absents et le panneau annonce explicitement l'indisponibilité.

## Vérifications

- 16 tests du paquet, 8 369 assertions ; 13 tests Legacy/export, 201 assertions.
- Hash du rapport micro T25A2 identique à T24 : `1D3CFE2363690131324DB392588B4EB4DBFEEF08374F4B5E5F080BD82312549A`.
- Deux générations consécutives identiques. Hashes : zones `52447352477365153E4F63D36404F4D88895CA154B2280FBF8E3D138DA672922`, overlay `5808624740E45DDE554012156CCE5E822F8977731696964FD2D14330D32F8618`, HTML `7D73AE569FD0E630761AFA060F97BFA5E11238ED5E7BD62B437F0672AC79D70B`.
- Les 12 combinaisons des trois axes et quatre filtres de camp ont été parcourues. « Les deux camps » affiche 12 lignes ; les autres filtres en affichent 6. Aucun gel ni erreur de page.
- Les couches ont été vérifiées séparément : 12 zones et 6 références dans une vue simple, aucune après masquage, aucune sur Structure. Le rapport autonome ne charge aucune ressource réseau.
- Captures contrôlées à 1440 × 900, 1024 × 900 et 390 × 844 ; aucun débordement horizontal à 390 px. Les contrôles ont des noms accessibles et les valeurs complètes restent dans le panneau et le tableau.
- Transition ECharts configurée à 180 ms et interruptible. Sur Chrome headless isolé : chargement et premier rendu observés entre 0,23 et 0,38 s ; deux changements animés contrôlés terminés en 0,246 et 0,247 s ; changements sans mouvement observés entre 0,029 et 0,108 s.
- Mesures CLI ponctuelles, séparées : export Legacy 0,75–0,92 s ; calcul des 2 400 combats micro 0,67–3,32 s selon la charge ; fusion du calque 2,3–7,0 ms.

L'audit axe automatisé n'est pas revendiqué : sa sous-commande a redémarré le
daemon `agent-browser` sur `about:blank`. La recette par arbre accessible,
contrôles natifs, clavier automatisé et captures responsive a bien été effectuée.

## Limites restantes

- Les zones sont en lecture seule et non confirmées. Il n'y a ni poignée, ni saisie numérique, ni import/export utilisateur, ni historique.
- Le bilan affiche correctement `0 / 0 contraintes confirmées` et 48 brouillons ; masquer une couche ne change pas ce statut.
- Aucun solveur, aucune recherche de paramètres et aucune activation de ruleset ne sont inclus.
- Livraison B ne commence qu'après le jugement du PO sur cette représentation.
