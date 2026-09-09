# Handoff T34 — point de reprise

T34 est livrée et sa contre-recette légère Astra a reproduit les mesures
officielles, avec une réserve R1 de provenance avant clôture. Voir la
[contre-recette](waar-micro-combat-t34-astra-review.md), le
[relais de livraison](waar-micro-combat-t34-relay.md) et la
[spécification](waar-micro-combat-t30-t34-spec.md).

## Reprise prioritaire — réserve R1 ouverte

Le builder T34 vérifie les identifiants du plan et du résultat T33, mais ne
compare pas l'empreinte du plan reçu à `result.planSha256`. La revue demande de
vérifier ce lien, de recouper les empreintes des variantes entre plan, résultat
et copies, puis de couvrir le rejet d'un plan altéré avant toute mesure.
Cette correction reste à réaliser ; aucune clôture ni approbation de candidat
ne découle de la reproduction des mesures officielles.

## État à préserver

- T33 a été acceptée sans réserve par Tristan avant le démarrage de T34.
- Les quatre variantes restent `objectifs-non-atteints` sur T33, à 0/32 et sans
  nul. T34 ne change ni ce statut ni l’ordre T31.
- Le corpus T24 est figé par le SHA-256
  `551B41923A588E01CD3CB5A2A38D822E4E01A231CA2818F90B5DC7E623E761FB`.
- Le plan T34 a été écrit avant mesure avec la seed `32452843`, 1 000 répétitions
  par scénario et variante et le SHA-256
  `08D7AD8FC1CAC621A59700DE9FF9958E967B340C0D13AE29E18C3766FE41140A`.
- Les six scénarios, leurs compositions et leurs budgets parfois inégaux sont
  conservés exactement. Les quatre variantes utilisent le départage défenseur
  et les coûts figés.
- La sortie officielle contient 24 000 combats et dix renversements de majorité.
  Elle est conservée dans
  `experiments/references/t34-mixed-composition-observation/` ; ouvrir le
  [rapport figé](../experiments/references/t34-mixed-composition-observation/report.html).
- Aucun score T24, objectif mixte, ellipse, verdict Legacy, classement ou choix
  automatique n’a été ajouté.
- Les rapports historiques T24 restent intacts et utilisent le départage nul
  implicite ; leurs mesures ne sont pas affichées dans T34.

## Synthèse T30–T34

T30 a figé 15 paramètres inspectables. T31 a exploré 128 candidats, sans candidat
strict, et exporté trois finalistes. T32 les a rendus comparables. T33 les a
mesurés sur cinq lots réservés : 0/32 pour tous, zéro nul. T34 montre leurs effets
sur six compositions mixtes, dont des changements majeurs de vainqueur et de
préservation, sans les convertir en validation.

## En cas de correction T34

Une correction purement documentaire ou visuelle peut conserver le plan et les
mesures. Toute correction touchant le corpus, les variantes, les seeds, le runner
ou les agrégats exige une nouvelle sortie explicitement identifiée ; ne pas
réécrire silencieusement le run réservé.

Rejouer la suite PHP du paquet, les quatre tests Node, les lints et le parcours
navigateur. La commande officielle exige une sortie absente ou vide.

## Suite

Le programme spécifié s’arrête à T34. Attendre la décision PO et une nouvelle
spécification avant toute recherche supplémentaire, intégration Legacy,
projection vers l’infirmerie, application transactionnelle des pertes ou
activation de ruleset.
