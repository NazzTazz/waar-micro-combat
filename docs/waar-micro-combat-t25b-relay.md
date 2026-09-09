# Relais T25B — cahier des charges éditable

8 septembre 2026 — livraison B terminée, arrêt pour choix humain des zones.

## À ouvrir

- Rapport autonome : `var/waar-micro-combat/t25b/report.html`
- Document de zones initial : `var/waar-micro-combat/t25b/acceptance-zones.json`
- Données fusionnées : `var/waar-micro-combat/t25b/overlay.json`
- Rapport micro inchangé : `var/waar-micro-combat/t25b/micro-report.json`
- Captures : `var/waar-micro-combat/t25b/captures/desktop-1440x900-editing.png`, `tablet-1024x900-editing.png`, `mobile-390x844-editing.png` et `mobile-390x844-editor.png`

Reproduction :

```powershell
php tools/export-waar-micro-legacy-reference.php
php packages/waar-micro-combat/bin/run-acceptance-overlay.php
```

## Ce que le PO peut faire

Le mode édition affiche une poignée centrale et quatre poignées de rayon sur la
zone scénario/camp/extrémité choisie. Les quatre valeurs restent saisissables au
clavier. Les centres sont bornés à `[0,1]²`; les rayons à `0,005–1`, soit `0,5–100`
points. Le mode Cercle lie les rayons. Échap annule un geste en cours ; Annuler et
Rétablir couvrent les gestes terminés, la saisie, les confirmations, les
activations et les suppressions.

Chaque contrainte peut rester en brouillon, être confirmée ou désactivée. Une
confirmation groupée explicite couvre les brouillons compatibles. Le bilan
global sépare satisfaites, brouillons, désactivées, incompatibles, non
applicables et absentes ; il ne change pas avec les filtres ou la visibilité des
calques.

Importer et exporter opèrent uniquement sur des fichiers locaux. Le rapport
signale les modifications non exportées et ne promet aucune sauvegarde
automatique. L'import est limité à 1 Mio et validé avant remplacement de la
session. T25A2 (`waar-acceptance-zones/0.1`) est migré explicitement vers `0.2`.
Une empreinte de corpus différente rend les zones `stale`; le réancrage reste
une action explicite. Les identifiants inconnus, associations dupliquées, formes
non prises en charge, valeurs non finies et rayons hors bornes sont refusés sans
perte de l'état courant.

La provenance Legacy et son centre original restent présents. Une géométrie
retouchée porte `source.modifiedManually = true`. Les observations micro et
Legacy sont gelées : déplacer une zone ne déplace aucun point et ne relance
aucun combat.

## Sources et format

- Modèle portable et validation réellement utilisés par le navigateur : `packages/waar-micro-combat/resources/acceptance-zones-model.js`
- Application ECharts : `packages/waar-micro-combat/resources/acceptance-overlay-app.js`
- Gabarit autonome : `packages/waar-micro-combat/resources/acceptance-overlay.html`
- Construction et rendu PHP : `src/Experiment/AcceptanceOverlayBuilder.php`, `AcceptanceOverlayRenderer.php`
- Schéma : `schema/acceptance-zones.schema.json`, version `waar-acceptance-zones/0.2`
- Tests du modèle : `tests/acceptance-zones-model.test.js`; tests du paquet : `tests/AcceptanceOverlayTest.php`

Apache ECharts 5.6.0 reste embarqué localement, SHA-256
`BF4A223524E40B77C304BEC67E1222CF551F14880CF42C69DC046558E11C07B1`,
avec sa licence Apache 2.0. Aucun CDN, endpoint ou stockage serveur n'est ajouté.

## Vérifications et empreintes

- 16 tests PHP du paquet, 8 388 assertions ; 13 tests Legacy/export, 201 assertions ; test Node du modèle réussi.
- 29 fichiers PHP lintés ; syntaxe des deux scripts JavaScript et JSON du schéma valides ; `git diff --check` sans erreur.
- Round-trip JSON, migration 0.1, frontière incluse, bornes, rejet des imports invalides et empreinte de composition périmée couverts par le modèle exécuté.
- Recette Chrome : centre et quatre bords déplacés réellement, mode Cercle, Échap, saisie clavier, confirmation groupée, désactivation, suppression, annulation/rétablissement, import invalide conservant l'état et réancrage après import périmé.
- 40 changements rapides d'axe/camp avec mouvement réduit, 108 rendus observés entre 14,5 et 136,3 ms, aucune erreur ; aucune requête réseau pendant l'édition et les changements de vue.
- Axe 4.12.1 : 0 violation, 0 contrôle incomplet, 41 règles réussies. Aucun débordement horizontal à 390 px.
- Deux générations successives identiques. Hashes : zones `E335038669D793670E0F36F26E91D2EF30853F34F73C41BB9E677A0C9EFBF9E3`, overlay `586DCAB13385A87524DF432DA2E5620008BC2897A335E3CAFE0BD5BE119A04D3`, HTML `B6B18E8044DBB2AC7151171A66C25F6668E3605D4FEBCDC52927D4C535830831`.
- Le rapport micro conserve le hash T24 `1D3CFE2363690131324DB392588B4EB4DBFEEF08374F4B5E5F080BD82312549A`; Legacy conserve `BCCE3DDD65218BD3F752148F864D9CF00AE7E45AF3BAEEB58BE8ED25C459A494`. Les hashes T25A2 restent inchangés.

## Limite et arrêt

Les 48 zones embarquées restent les propositions Legacy initiales, toutes en
brouillon et hors des observations courantes. Le rapport permet maintenant au
PO de les déplacer, confirmer, désactiver et exporter ; il ne décide pas à sa
place quelles zones expriment le gameplay voulu.

Aucun solveur, ajustement de paramètres, activation de ruleset, intervalle de
confiance ou intégration production n'est inclus. La prochaine tranche ne doit
commencer qu'après export ou instruction explicite du PO sur le cahier des
charges.
