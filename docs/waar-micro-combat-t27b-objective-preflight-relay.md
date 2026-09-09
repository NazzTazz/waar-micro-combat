# Relais T27B — prévol des 32 objectifs canoniques

9 septembre 2026 — tranche terminée, prête pour une revue légère d'Astra.

Revue Astra effectuée : acceptée pour le périmètre de prévol. Vérifications indépendantes et limites : [recette Astra](waar-micro-combat-t27b-astra-review.md).

## Entrée et artefacts

- Contrat prioritaire : `docs/waar-micro-combat-objective-contract-correction.md`
- Design PO canonique : `var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json`
- Prévol : `var/waar-micro-combat/t27b-objective-preflight/evaluation.json`
- Rapport court : `var/waar-micro-combat/t27b-objective-preflight/report.md`
- Service pur : `packages/waar-micro-combat/src/Experiment/CanonicalMonotypeObjectiveEvaluator.php`
- Commande : `php packages/waar-micro-combat/bin/evaluate-monotype-objectives.php`

L'entrée contient 32 zones `survivors`, toutes actives et confirmées. Elles sont
strictement identiques aux 32 objets survivants de l'export PO original à 96
zones. Les 64 anciennes représentations structure et valeur économique ne sont
ni copiées dans l'entrée, ni comptées dans le score.

## Contrôles livrés

- Le prévol exige exactement les 16 paires ordonnées × 2 camps, avec des
  identifiants uniques, le profil, le corpus et le barème attendus.
- Il rejette explicitement l'ancien document à 96 zones, les objectifs en
  brouillon ou désactivés et les X qui ne sont plus complémentaires.
- Chaque objectif produit une seule contribution binaire au taux de
  satisfaction. Les distances normalisées restent publiées par objectif pour
  la future recherche, sans pondération cachée.
- L'absence de nul est un contrôle strict séparé du score des 32 zones. Aucun
  départage n'est ajouté au moteur.

## Mesure du candidat courant

- Objectifs atteints : **0 / 32**.
- Nuls : **174 / 3 200 combats** (5,4 %), tous dans une confrontation.
- Contrôles stricts : échec sur les objectifs et sur l'absence de nuls.

Ce résultat est un état initial, pas une calibration. Aucun paramètre n'a été
exploré ou modifié. Les paramètres ouverts et la politique de départage restent
à valider avant une recherche.

## Vérifications

- 23 tests PHP du paquet, 8 842 assertions.
- 13 tests Legacy/export, 201 assertions.
- Test Node, lints PHP, syntaxe JavaScript et schéma JSON réussis.
- Le fichier PO canonique compte 32 zones actives et confirmées ; comparaison
  objet par objet avec les survivants de l'original : 0 différence.
- Deux prévols successifs produisent des artefacts strictement identiques.

SHA-256 :

- expérience : `ACF997CCE28605187B0EFECAD92074E1B77A4B30DAACE39830DCD16BA617EC11`
- rapport micro : `5234BA9A117F82FC33F3F6238278A6C3A339006AD077A4ED9BB88677E1C9680F`
- objectifs : `F4399119A6A82ABFE7FD14965CFE125D5645FBBC2CF5311D3B78F86F76DB8C79`
- évaluation : `DD72B00A0992B2C503EE28CEF10AF40184186B375336EDBDA7C4BBFFA785B751`
- rapport Markdown : `DC76B2769E0D2DF76763394A226DE21954DB90E28E4C192CFED646D041E23058`

Les livrables T24, T26 historiques, T27A et l'export PO original restent
intacts.
