# Handoff T33 — point de reprise

T33 est techniquement validée après contre-recette corrective Astra : R1/R2 levées
le 9 septembre 2026. Le rapport est `docs/waar-micro-combat-t33-astra-review.md`. Le relais autoritaire est
`docs/waar-micro-combat-t33-relay.md` et la spécification reste
`docs/waar-micro-combat-t30-t34-spec.md`.

## État à préserver

- T32 a été validée par Tristan avant le démarrage de T33.
- Le plan T33 a été écrit avant la première mesure et porte le SHA-256
  `e878d8e4208dae322726e52033d0c18192adc2311d8c520321b5ee682e1449b9`.
- Les cinq seeds spécifiées n'ont subi aucune correction ; aucune collision
  avec T31 ni entre lots n'a été détectée.
- L'initial et les trois finalistes restent à 0/32 sur chaque lot et l'agrégat,
  avec zéro nul. Les quatre statuts sont `objectifs-non-atteints`.
- Le rang 2 a une perte agrégée inférieure au rang 1 sur T33, mais l'ordre T31
  reste autoritaire et aucun reclassement opportuniste n'a été produit.
- La sortie complète est `var/waar-micro-combat/t33-finalist-stability/`.
- R1 est corrigée : l'expérience T31 vérifiée fournit le sampling autoritaire et
  toute divergence du plan est rejetée avant création du plan T33.
- R2 est corrigée dans la commande : le chronomètre d'un lot démarre avant
  l'initial et le témoin et se ferme après le troisième finaliste. Les durées par
  lot de la sortie officielle antérieure restent historiques et ne sont pas réécrites.

## En cas de correction T33

Conserver le plan et les mesures officielles si la correction est purement
documentaire ou visuelle. Si une correction touche la simulation, l'agrégation,
les seeds ou les entrées gelées, produire une nouvelle sortie explicitement
identifiée ; ne pas réécrire silencieusement le run réservé.

Rejouer la suite PHP du paquet, les trois tests Node, les lints et le parcours
Chrome. Toute nouvelle génération dans une sortie officielle exige un répertoire
vide et peut changer les empreintes du relais.

## Tranche suivante

Tristan a accepté T33 sans réserve. T34 est désormais livrée ; reprendre depuis
`docs/waar-micro-combat-t34-handoff.md`.
