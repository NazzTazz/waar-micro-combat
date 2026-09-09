# Handoff T32 — point de reprise

T32 est validée par Tristan. Ce handoff est conservé comme état historique ; le relais est
`docs/waar-micro-combat-t32-relay.md` et la spécification reste
`docs/waar-micro-combat-t30-t34-spec.md`.

## État à préserver

- T31 est acceptée sans réserve. Consommer
  `var/waar-micro-combat/t31-standard-seed-314159/` sans modifier ses fichiers.
- La sortie T32 est
  `var/waar-micro-combat/t32-finalist-comparison/report.html` ; ses empreintes
  et son parcours de recette sont dans le relais.
- Le design PO canonique reste
  `var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json`.
- Les trois finalistes restent classés selon T31, sans promotion fondée sur le
  nombre d'objectifs atteint.
- Aucun candidat n'est strict : chacun est à 0/32 avec zéro nul sur les mesures
  T31. T32 décrit ce résultat ; elle ne l'accepte pas au nom du PO.

## Si T32 reçoit des corrections

Rejouer les tests PHP du paquet, les deux tests Node, la génération vers une
sortie vide, puis Chrome sur le vrai run T31 en bureau et à 390 px. Vérifier
zéro simulation, 32 objectifs uniques, aucune erreur console, navigation
clavier et provenance des deux exports. Régénérer les captures et mettre à jour
les empreintes du relais et du journal si le rendu change.

## Tranche suivante

T33 est désormais livrée. Reprendre depuis
`docs/waar-micro-combat-t33-handoff.md` sans utiliser T32 pour recalculer ou
sélectionner un candidat.
