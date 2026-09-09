# Relais T28 — départage défensif versionné

9 septembre 2026 — tranche terminée, prête pour une revue légère d'Astra.

Contre-recette Astra effectuée : acceptée sans réserve dans le périmètre livré. [Vérifications indépendantes](waar-micro-combat-t28-astra-review.md).

## Comportement livré

- La politique `tieBreakPolicy` appartient au ruleset et accepte `draw` ou
  `defender`.
- Un manifeste historique sans ce champ conserve exactement le comportement
  `draw` et sa forme exportée antérieure.
- Sous `defender`, une égalité exacte à la limite donne la victoire au défenseur
  avec `defender-tie-break-round-limit-equality`.
- Une extinction mutuelle donne la victoire au défenseur avec
  `defender-tie-break-mutual-extinction`.
- Les victoires déjà établies par extinction adverse ou meilleure préservation
  restent inchangées.

Le départage ne modifie aucun round, dégât, survivant, mort ni reste de
structure. Les raisons dédiées permettent de distinguer les victoires obtenues
par cette règle.

## Artefacts

- Manifeste : `packages/waar-micro-combat/experiments/t28-defender-tie-break.json`
- Prévol : `var/waar-micro-combat/t28-defender-tie-break/evaluation.json`
- Rapport court : `var/waar-micro-combat/t28-defender-tie-break/report.md`
- Rapport micro : `var/waar-micro-combat/t28-defender-tie-break/micro-report.json`
- Objectifs PO inchangés : `var/waar-micro-combat/t28-defender-tie-break/objectives.json`

Les variantes témoin et candidate déclarent toutes deux la politique
`defender` et la version `t28.0`. L'identifiant de l'expérience reste
`t26-monotype-equal-cost`, car il identifie le corpus auquel les 32 objectifs
canoniques sont rattachés ; le manifeste, les variantes et le répertoire de
sortie portent la version du comportement rejoué.

## Résultats

- Objectifs atteints par le candidat courant : **0 / 32**.
- Nuls : **0 / 3 200**, contre 174 dans T27B.
- `archer-vs-knight` : 0 victoire attaquante et 200 victoires défensives.
- Comparaison T27B/T28 : 0 différence de rounds ou de métriques de pertes sur
  les 32 lignes et les deux variantes.
- Le contrôle strict d'absence de nuls passe ; le prévol global reste en échec
  parce qu'aucun des 32 objectifs n'est encore atteint.

## Vérifications

- 27 tests PHP du paquet, 8 864 assertions.
- 13 tests Legacy/export, 201 assertions.
- Test Node, lints PHP et manifeste JSON réussis.
- Cas testés séparément : égalité exacte, extinction mutuelle, conservation des
  pertes, victoire attaquante hors égalité et compatibilité des manifestes
  historiques.
- Deux générations T28 successives sont strictement identiques.
- T24 conserve l'empreinte `1D3CFE2363690131324DB392588B4EB4DBFEEF08374F4B5E5F080BD82312549A`.

SHA-256 T28 :

- manifeste source : `64B698F84B5CA02E0210F9EBBFA7756E141D787015EB23D5410AD1D2F10692FE`
- expérience exportée : `66AD161BAAA105F318E603B2C0278101EF38E03F5B74D4F272278EBCFD6E65D4`
- rapport micro : `7714F87417A1083C20D83C210ED55EAB4F4536197DA11580922BDFF3636EE513`
- objectifs : `F4399119A6A82ABFE7FD14965CFE125D5645FBBC2CF5311D3B78F86F76DB8C79`
- évaluation : `C06638B8280798BDE13A659C88F2019DF1656BD8D5BCF0F21FB7C4FA2DBDBC1D`
- rapport Markdown : `7F8FCB1FC1740E4424C50E1A6CDE1D7258550EE431D911AA04200EE0BB4FF96A`

Aucun paramètre de combat n'a été exploré et aucun optimiseur n'a été lancé.
La formule continue de recherche reste une tranche distincte.
