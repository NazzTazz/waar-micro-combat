# Handoff T34 — point de reprise

T34 est livrée et sa contre-recette légère Astra a reproduit les mesures
officielles, avec une réserve R1 de provenance avant clôture. Voir la
[contre-recette](waar-micro-combat-t34-astra-review.md), le
[relais de livraison](waar-micro-combat-t34-relay.md) et la
[spécification](waar-micro-combat-t30-t34-spec.md).

## Reprise prioritaire — réserve R1 ouverte

Avant correction, le builder T34 vérifiait les identifiants du plan et du résultat
T33, mais pas l'empreinte du plan reçu contre `result.planSha256`. La revue demande de
vérifier ce lien, de recouper les empreintes des variantes entre plan, résultat
et copies, puis de couvrir le rejet d'un plan altéré avant toute mesure.
Une correction locale est préparée ci-dessous ; aucune clôture ni approbation de candidat
ne découle de la reproduction des mesures officielles.

## Correction R1 préparée — 12 septembre 2026

La branche `fix/t34-r1-provenance` contrôle le SHA-256 des octets réellement lus
pour le plan T33 contre `result.planSha256`. Pour chacun des quatre candidats,
elle recoupe les déclarations du plan, du résultat et de `frozenCopies` avec
les octets de la copie consommée. Les empreintes absentes, non textuelles,
non hexadécimales ou divergentes sont rejetées explicitement. Les empreintes
`parameterFingerprint` conservent leur contrat distinct.

La non-régression couvre un plan modifié (seed ou simple saut de ligne), les
empreintes invalides aux quatre emplacements, les quatre positions de candidat,
et une copie modifiée avec son hash de copie. Les mutations du plan destinées
aux contrôles des candidats actualisent le lien plan/résultat pour atteindre
ces contrôles. Le test CLI vérifie un échec sans aucun artefact ni démarrage de
mesure. Le témoin officiel compare le plan complet après normalisation des
seuls chemins de provenance et exclusion du manifeste ajouté par la CLI.

Preuve discriminante : les tests rejettent le code antérieur ; retirer le
contrôle plan/résultat dans une copie isolée refait échouer la régression.
Les tests corrigés passent. Aucun run T31, T33 complet ou T34 officiel n'est
lancé ; les tests de mesure existants restent bornés et le smoke reste celui
d'AGENTS.md. Les 107 fichiers des références sont identiques avant/après.

Vérification locale sous PHP 8.2.33 : `composer validate --strict`,
`composer install --no-interaction --prefer-dist --no-progress`, `composer test`
(75 tests, 10 509 assertions), `composer test:js` (quatre suites),
`composer smoke` (2 400 combats), `php bin/render-finalist-comparison.php`,
les deux lints PHP et `git diff --check` réussis. Composer a été appelé via
`php ../waar-micro-combat/composer.phar` ; les dépendances et l'autoload sont
propres au worktree R1. PHP 8.4 et la CI GitHub ne sont pas vérifiés localement.

La contre-recette indépendante locale a réussi 152 contrôles, dont 149 rejets
et six essais CLI sans artefact ni démarrage de mesure. Le témoin officiel
produit le même plan que le code antérieur et le plan figé après normalisation
des chemins. L'ancien code et une copie avec le contrôle plan/résultat désactivé
acceptent la seed altérée que le correctif rejette. Un témoin CLI borné à
24 combats réussit également. Les 107 références de chaque worktree et le
chantier Legacy sont inchangés. La suite PHP a réussi malgré un avertissement
d'écriture du cache PHPUnit ; le smoke a été rejoué dans une copie temporaire
des sources vérifiée identique, et le rendu dans une nouvelle sortie temporaire.

Cette correction ne clôt pas T34 : intégration et décision PO restent requises.
La revue historique est conservée intacte ; aucun candidat n'est approuvé.
Les résultats de CI de la PR doivent être vérifiés séparément.

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
