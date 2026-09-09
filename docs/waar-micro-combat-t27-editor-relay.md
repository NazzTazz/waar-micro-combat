# Relais T27A — éditeur d'objectifs consolidé

> Relais historique remplacé par le contrat à 32 objectifs de
> `docs/waar-micro-combat-objective-contract-correction.md`. Les mentions de 96
> objectifs ci-dessous décrivent l'état alors observé et ne doivent pas servir
> d'entrée à la recherche.

9 septembre 2026 — tranche terminée, prête pour une revue légère d'Astra.

## Livrable courant

- Rapport autonome : `var/waar-micro-combat/t27-objective-editor/report.html`
- Objectifs initiaux : `var/waar-micro-combat/t27-objective-editor/acceptance-zones.json`
- Rapport micro : `var/waar-micro-combat/t27-objective-editor/micro-report.json`
- Brouillon PO reçu, conservé séparément : `var/waar-micro-combat/objectives/20260909-po-draft/acceptance-zones.json`
- Commande : `php packages/waar-micro-combat/bin/run-monotype-objectives.php`

La commande par défaut et le README pointent désormais vers cet artefact T27,
sans réécrire les livrables T26 historiques.

## UX consolidée

- Une confrontation est affichée par défaut, avec l'attaquant turquoise, le
  défenseur corail et un losange pour distinguer sa cible.
- Les centres des deux objectifs sont reliés en pointillés. Les libellés de la
  paire sont placés de part et d'autre lorsque les centres se superposent.
- Un clic sur un centre sélectionne directement le camp ; des clics successifs
  alternent les cibles superposées sans modifier le document.
- Le taux de victoire X est lié : `X défenseur = 1 - X attaquant`, avec un rayon
  X partagé sur les trois axes. Les valeurs Y restent indépendantes.
- Les anciens exports restent importables sans normalisation silencieuse. Une
  liaison explicite applique la complémentarité en une mutation annulable.

Le brouillon PO réel a été importé dans Chrome : 96 zones actives et en
brouillon, 16 marquées comme modifiées manuellement, aucune confirmée. L'import
ne crée aucune mutation ; lier puis annuler restaure exactement le document
importé. Il ne constitue donc pas encore un jeu de contraintes pour un solveur.

## Vérifications

- 19 tests PHP du paquet, 9 142 assertions.
- 13 tests Legacy/export, 201 assertions.
- Test Node du modèle, lints PHP, syntaxe JavaScript et schéma JSON réussis.
- Deux générations successives strictement identiques.
- Chrome : import du brouillon PO, liaison/annulation, sélection directe sans
  mutation, vue mobile à 390 × 844 sans débordement horizontal ni erreur.
- Audit axe-core 4.12.1 avant le dernier ajustement purement visuel des libellés :
  0 violation, 0 contrôle incomplet, 41 règles réussies.

SHA-256 :

- zones : `C2D4BA5A089FF7678DF21349CAB8251D67EF630E51213D587A199CD59F06E2B0`
- expérience : `ACF997CCE28605187B0EFECAD92074E1B77A4B30DAACE39830DCD16BA617EC11`
- rapport micro : `5234BA9A117F82FC33F3F6238278A6C3A339006AD077A4ED9BB88677E1C9680F`
- overlay : `819DA2AEA5A7C615271E734A935C1312712D511740E00213F4DBA28BF339C5C2`
- rapport Markdown : `1E10C8B5C64E6D5CC16236D8AC9C4EBEB74E5E1CA6B2ACC657DDB30B4A364A58`
- rapport HTML : `73D6F098F8D161D00E95AF0262A6C3C563FC7F7227EE8EC11CCE329BF8D5D932`

Les témoins historiques conservent leurs empreintes : T24 `report.json`
`1D3CFE2363690131324DB392588B4EB4DBFEEF08374F4B5E5F080BD82312549A`,
T25A2 HTML `7D73AE569FD0E630761AFA060F97BFA5E11238ED5E7BD62B437F0672AC79D70B`,
T25B HTML `B6B18E8044DBB2AC7151171A66C25F6668E3605D4FEBCDC52927D4C535830831`
et T26c HTML `A2EACE2C89F1E3C1F0CA89F655B1DF41218442FB10068675FA8B6AF28B764B50`.

## Écart persistant

Waar ne doit pas produire de nul, mais le départage n'est pas spécifié. Le
candidat actuel donne 174 nuls sur 200 pour `archer-vs-knight`. Aucun départage
n'a été inventé et aucun solveur n'a été lancé. La prochaine tranche de moteur
doit partir d'une politique de départage validée ; la calibration attend aussi
des objectifs explicitement confirmés par le PO.
