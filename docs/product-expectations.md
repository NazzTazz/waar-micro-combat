# Attentes produit, décisions et acquis à préserver

Registre établi le **21 septembre 2026**, à la demande de Tristan après le constat
de fonctions reconstruites sans exploiter les quinze jours d'historique disponibles.
But : retrouver une décision et son implémentation avant de proposer autre chose.
Ce fichier est un index maintenu, pas une nouvelle spécification du moteur.

## Reprise courante — B1, 26 septembre 2026

- **Suivi :** [issue #25](https://github.com/NazzTazz/waar-micro-combat/issues/25), suite de #24 ; attentes ATT-01/02/03/04/07/08/10/11/14. Branche locale `bench/issue-25-b1` issue de `main` / `origin/main` à `05ebe6f3f2c29c9faf80e28a2558ed60c13e130f` au départ de la mesure. Voir le [rapport B1 et ses données](benchmarks/2026-09-26-b1/README.md) pour le contrôle d'histoire, le corpus, les résultats et les limites.
- **Réalisé localement :** geste navigateur chronométré, corpus Nazz/Test 2, pilote borné processus PHP/Rust contre Rust persistant, profils natifs, partition exacte et timings HTTP activables par variable d'environnement. Aucun changement de gameplay, d'objectif ou de parcours utilisateur. Sur le geste Test 2 mesuré, saisie → DOM 900,2 ms ; sur le mélange Nazz, `resolve_fast` domine le profil natif. Le découpage 4 × 5 avance le premier résultat à 2,4 s et augmente le total par rapport à 1 × 20. Ce constat n'est ni une optimisation livrée ni une acceptation PO.
- **Vérification et reste :** parités exactes des résultats comparables et contrôle de partition exact ; 161 tests PHP / 24 132 assertions, suite JavaScript, smoke 2 400 combats, Rust normal et diagnostic passants. Ouvrir la PR B1 sur #25, puis examiner les pistes classées dans le rapport. Fichiers non suivis préexistants et scripts locaux `reports/issue25-b1/` conservés hors de la livraison. Aucun déploiement ou contrôle VPS.

## Comment reprendre un travail

1. Repérer les attentes concernées ci-dessous. Lire leurs sources, y compris les
   corrections et contre-recettes, puis chercher le code correspondant.
2. Consigner brièvement : **attentes / sources lues / existant réutilisé / échec
   antérieur / écart à corriger / geste utilisateur qui le prouvera**. Utiliser
   l'issue, la PR ou le compte rendu existant, sans nouvelle série de handoffs.
3. Distinguer l'intention produit, la décision de gameplay, le contrat de données
   et le composant visuel. Réutiliser un graphe n'autorise pas à changer le moteur.
4. Mettre à jour les lignes affectées avec preuve et contexte dans la même
   livraison. Ne pas effacer un échec passé ou déclarer une attente satisfaite
   parce qu'un test passe. Une recette historique n'est pas une vérification du VPS.

Les instructions actuelles de Tristan priment. Ensuite, lire les statuts et les
amendements applicables au projet concerné : une ancienne proposition n'est pas
un arbitrage, et un document récent ne remplace pas automatiquement tous les autres.
Les inconnues ci-dessous appellent une vérification ciblée, pas une nouvelle demande
à Tristan de redéfinir ce qu'il a déjà écrit.

## Sources et limites de ce relevé

- **Décision PO communiquée le 21 septembre 2026, après la contre-recette #13 :**
  la branche de #11 a été refusée ; la production a été rollbackée pendant la nuit.
  Les mentions de livraison de #11 ci-dessous décrivent donc un travail proposé
  et examiné, pas une acceptation ou l'état déployé. Le PO a ensuite confirmé le SHA
  de production `3cbc8ab8734c67300b71fdd7aa3610b1d8f3323c`, identique à `main`.
  #13 a été détachée de la branche refusée et cible désormais cette base ; aucun
  nouveau déploiement ou contrôle direct du VPS n'a été effectué.
- Dépôt courant examiné à `b440b93833cd8fb8c3bbdacbcf8e3456602ce05e` ; les états
  ci-dessous sont un point de départ daté, pas une certification exhaustive.
- Les liens `../../waar-v3/…` visent le dépôt **local voisin**, pas un fichier de ce
  dépôt sur GitHub. Son HEAD est `98ac1186f3c9c82bac63f73d87b791bfa2a6a850` ; son
  arbre contient aussi du travail local. Ce SHA ne prouve pas que chaque document
  cité y est commité. Si ce dépôt manque, signaler précisément les sources
  indisponibles ; ne pas conclure que le travail n'a jamais existé.
- [Provenance de l'extraction](extraction-provenance.md) et
  [index historique](historical-documents.md) expliquent les copies, chemins
  historiques et références figées. Ne pas réexécuter aveuglément leurs commandes.
- Les preuves citées ont été lues pour ce relevé ; toutes leurs recettes n'ont pas
  été rejouées. L'examen documentaire ne valide ni un profil ni un équilibrage.
- Le [workflow préparé le 15 septembre](../../agent-workflow/INSTALLATION.md)
  demandait déjà une réconciliation documentaire et signalait les mentions R1
  périmées. Le contrôle de `planSha256` est présent dans le
  [builder courant](../src/Experiment/MixedCompositionObservationPlanBuilder.php),
  avec des [tests dédiés](../tests/MixedCompositionObservationTest.php). Le workflow
  rapporte la fusion de la PR2 ; son état distant n'a pas été revérifié pendant
  cette lecture. Ne pas traiter l'ancienne réserve comme une correction à refaire,
  ni déduire une validation PO de T34 de son intégration technique.

## Chronologie et portée des décisions

| Repère | Source | Décision ou acquis à retrouver |
| --- | --- | --- |
| 1er septembre 2026 | [Pérennité du moteur](../../waar-v3/docs/combat-engine-durability.md) | Décision acceptée : seeds, versions stochastiques et numériques, anciens combats rejouables, optimisation précédée de mesures. Les identifiants historiques ne sont pas ceux à imposer au moteur actuel. |
| Avant le split, état étudié le 7 septembre | [Spec générique 3.1](../../waar-v3/docs/combat-engine-v3.1-specification.md), [revue et chantiers](../../waar-v3/docs/combat-engine-v3.1-review-and-workstreams.md) | Ambition PHP de référence + Rust, règles JSON indépendantes du jeu et soufflerie autonome. La spec complète n'est pas la liste des mécanismes tous livrés : consulter le [sous-ensemble décidé](../../waar-v3/docs/combat-engine-v3.1-subset-decisions.md) et son [contrat minimal](../../waar-v3/docs/combat-engine-v3.1-minimal-contract.md). |
| Ancien Lab puis tranches 02–04b | [Lab interactif](../../waar-v3/docs/combat-lab-interactive.md), [état Rust/WASM du 7 septembre](../../waar-v3/docs/combat-engine-v3.1-rc-status.md), [04b](../../waar-v3/docs/combat-engine-v3.1-slice-04b-live-vectors-handoff.md), [recette 04b](../../waar-v3/packages/combat-wind-tunnel/reports/slice-04b-review.md) | Comparaison référence/brouillon, aperçu progressif, vecteurs animés et exports. 04b est déclarée acceptée après corrections ; ne pas lui attribuer une date non portée par le document. |
| 8 septembre : séparation | [Frontières des projets](../../waar-v3/docs/combat-project-boundaries.md), [cadrage Waar](waar-micro-combat-scope.md), [journal T19](../../waar-v3/docs/sol-autocalibration.md#t19--séparation-du-micro-combat-waar-et-du-moteur-31--terminée) | La cible Waar et la trajectoire 3.1/Arbestra se séparent. La bibliothèque unique pour les deux jeux cesse d'être la cible Waar. Méthodes, fixtures et outils visuels restent réutilisables ; le travail antérieur n'est pas annulé en bloc. |
| 8–9 septembre : T24–T27 | [T24](waar-micro-combat-t24-relay.md), [vue par paire](waar-micro-combat-t26-pair-view.md), [éditeur T27](waar-micro-combat-t27-editor-relay.md), [correction des objectifs](waar-micro-combat-objective-contract-correction.md) | Vecteurs, distinction des camps, édition des objectifs et corrections de gestes déjà livrés. 32 objectifs canoniques, pas 96 ; structure diagnostique sans objectif supplémentaire. |
| T28–T34, reprise du 12 septembre | [programme T30–T34](waar-micro-combat-t30-t34-spec.md), [handoff T34](waar-micro-combat-t34-handoff.md) | Recherche, comparaison et validation ont des contrats distincts. Les résultats 0/32 ne sont pas une acceptation ; observer les compositions mixtes ne change pas les objectifs. Consulter les reviews de chaque tranche via l'index historique. |
| 12 septembre : accès aux capacités | [Cadrage ergonomique](ergonomie-creation-profil-2026-09-12.md) | Créer, essayer, puis affiner le même profil dans la même soufflerie. Conserver ellipses et vecteurs ; rendre leur accès compréhensible. Ce texte décrit une intention, pas toutes les fonctions déjà livrées. |
| 13 septembre : raccordement cohortes | [Spec consolidée](spec-moteur-cohortes-soufflerie-2026-09-13.md), [contre-recette PR5](../reports/pr5-review/review.md), [recette tranche B](../reports/slice-b-dogfood/report.md), [recette complémentaire](../reports/slice-b-review/report.md) | Raccorder l'existant, conserver T27, utiliser Rust et démontrer le vrai parcours. Les limites des recettes restent explicites. |
| 20–21 septembre : testeurs | [Profils partagés](shared-profiles.md), [profil initial](workshop-starting-profile.md), [comparaison épinglée](workshop-pinned-experiment.md) | Sauvegardes partagées, réglages de première utilisation, matrice et témoin fixe. La comparaison livrée ne rétablit pas à elle seule le graphique progressif historique. |

## Registre des attentes

Les statuts distinguent **décision**, **livraison documentée**, **constat actuel**
et **écart ouvert**. « À vérifier » ne signifie ni absent ni rejeté.

### ATT-01 — Réutiliser avant de reconstruire

- **Attente :** exploiter les moteurs, services, graphes et recettes existants ;
  expliquer toute substitution et ses conséquences sur le parcours.
- **Sources :** [ergonomie du 12](ergonomie-creation-profil-2026-09-12.md),
  [spec du 13, §1–2 et §12](spec-moteur-cohortes-soufflerie-2026-09-13.md).
- **État :** règle active ; défaut de reprise historique constaté le 21 septembre.
  Avant la prochaine modification d'interface, produire la correspondance entre
  besoin, code réemployé et capacité conservée. Un renommage ou une nouvelle page
  n'est pas une preuve de progrès.

### ATT-02 — Voir l'effet des réglages par des vecteurs comparatifs

- **Attente :** référence → réglages courants, par confrontation et par camp,
  valeurs/deltas/unités/n visibles ; égalité représentée sans déplacement inventé.
- **Sources :** [04b et ses critères V04b](../../waar-v3/docs/combat-engine-v3.1-slice-04b-live-vectors-handoff.md),
  [recette 04b](../../waar-v3/packages/combat-wind-tunnel/reports/slice-04b-review.md),
  [PR5 R2](../reports/pr5-review/review.md).
- **Réemploi :** [rendu SVG autonome](../../waar-v3/packages/combat-wind-tunnel/public/experiments.js),
  [graphe T27](../resources/acceptance-overlay-app.js),
  [modèle de présentation actuel](../public/workshop/model.js).
- **État :** ancien parcours livré et revu ; comparaison actuelle épinglée livrée
  sous forme de tableaux, sans équivalent progressif dans ce panneau. Écart ouvert.
  Vérifier l'adaptation au contrat actuel avant de copier le rendu.

### ATT-03 — Observer progressivement et rester libre de modifier

- **Attente :** recalcul temporisé, vrais résultats intermédiaires, nombre réel de
  simulations, interruption/remplacement sans affichage de résultats périmés.
- **Sources et réemploi :** [04b](../../waar-v3/docs/combat-engine-v3.1-slice-04b-live-vectors-handoff.md),
  [Worker et fusion Rust](../../waar-v3/packages/combat-wind-tunnel/public/worker.js),
  [comparaison actuelle](workshop-pinned-experiment.md).
- **État :** livré dans l'ancien runtime WASM. L'interface actuelle documente une
  temporisation de 500 ms, un cache et des protections par révision ; cela ne
  prouve pas un rendu progressif par lots. Le transport natif/PHP actuel nécessite
  une adaptation. Une animation d'attente ne satisfait pas cette attente.

### ATT-04 — Mesurer au-delà du seul vainqueur ou du nombre de blessés

- **Attente :** rendre lisibles pertes, dégâts partiels/structure restante et durée,
  en distinguant combat brut et conséquences. Ne pas assimiler blessé et presque mort.
- **Sources :** [métriques du contrat historique](waar-micro-combat-contract.md),
  [structure diagnostique](waar-micro-combat-objective-contract-correction.md),
  [spec cohortes](spec-moteur-cohortes-soufflerie-2026-09-13.md).
- **État constaté :** [mesure actuelle](../src/Workshop/MonotypeMeasurementService.php)
  expose victoires et pertes, mais pas la structure restante ni la durée moyenne.
  [Rust](../engines/waar-cohort/rust/src/v2.rs) possède `roundSum` dans le batch et
  `total_structure` en interne ; les agrégats de structure restent à exposer.
  Ces indicateurs sont à raccorder selon le contrat courant, sans rétablir
  d'anciens objectifs ni changer le gameplay.
- **Recette de reprise :** inclure le cas testeur Chevaliers → Soldats du
  [rapport épinglé](workshop-pinned-experiment.md), où les compteurs restent
  identiques après édition. Ne pas promettre qu'une métrique bougera pour tout réglage.

### ATT-05 — Un parcours continu du profil vers l'affinage

- **Attente :** même profil, navigation libre, combat accessible, accès à l'affinage
  sans exporter/importer ni ressaisir ; préserver travail de dessin et réglages.
- **Sources :** [ergonomie](ergonomie-creation-profil-2026-09-12.md),
  [spec §12](spec-moteur-cohortes-soufflerie-2026-09-13.md).
- **État constaté :** [Zones existe mais ses entrées sont masquées](../public/workshop/index.html).
  La [recette du 13](../reports/slice-b-dogfood/report.md) documente déjà son
  raccordement Rust. Motif et décision de masquage à retrouver avant intervention ;
  ne pas reconstruire Zones ou la réafficher sans examiner le contexte courant.

### ATT-06 — Séparer observations, objectifs et candidats

- **Attente :** déplacer un objectif ne déplace jamais une observation ; une
  comparaison n'approuve aucun profil. Préserver les objectifs et leurs provenances.
- **Sources :** [PR5 R1–R3](../reports/pr5-review/review.md),
  [correction des 32 objectifs](waar-micro-combat-objective-contract-correction.md),
  [programme T30–T34](waar-micro-combat-t30-t34-spec.md).
- **État :** décisions à préserver dans leur contexte. Le critère de la soufflerie
  actuelle est désormais `rawCasualtyRatio`, selon l'[amendement de recherche](spec-generateur-optimiseur-candidats.md).
  Les 32 objectifs historiques sur survivants ne doivent pas être réinterprétés
  ni substitués silencieusement aux objectifs courants. T24 reste en observation,
  avec ses compositions et budgets d'origine, y compris inégaux.

### ATT-07 — Comparer des contextes identifiés et reproductibles

- **Attente :** témoin stable, seeds de mesure cohérentes, météo/budget/effectifs
  visibles, provenance et obsolescence explicites ; aucun mélange entre requêtes.
- **Sources :** [décision du 1er septembre](../../waar-v3/docs/combat-engine-durability.md),
  [PR5 R1/R3](../reports/pr5-review/review.md), [témoin épinglé](workshop-pinned-experiment.md).
- **État :** livraison actuelle documentée pour l'épinglage ; limites de cache et
  de provenance du binaire consignées dans ce document. Rejouer les cas A/B/A,
  édition en cours et changement de contexte lors d'une modification de ce parcours.
- **Contre-recette #13, 21 septembre, `36cf34d` :** changer seulement la compression
  dans la soufflerie change encore `rulesetVersion` et `replayHash` malgré des rounds
  et états bruts identiques. Défaut préexistant, garantie de #12 non tenue à cette
  frontière ; [preuve et attribution R1](../reports/pr13-review/review.md).

### ATT-08 — Expliquer en français ce que le testeur peut constater

- **Attente :** relier réglage et confrontation pertinente ; distinguer mécanisme
  local, effet mesuré et conseil. Ne pas fabriquer une explication causale ou une
  amélioration à partir de variations nulles ou de confrontations étrangères au geste.
- **Sources :** [intention ergonomique](ergonomie-creation-profil-2026-09-12.md),
  [livraison et limites du panneau](workshop-pinned-experiment.md).
- **État :** comparaison ciblée et mécanismes livrés ; compréhension insuffisante
  signalée par Tristan le 21 septembre. Le balayage d'un paramètre et la matrice
  des écarts sont documentés comme non livrés. L'examen à quatre variantes existe,
  mais ne prouve pas une compensation générale. Ne pas annoncer des conseils utiles
  sans les éprouver sur une manipulation réelle du testeur.

### ATT-09 — Préserver les profils des testeurs

- **Attente :** sauvegarder, charger et exporter sans écrasement implicite ; ne pas
  altérer un profil de test pour obtenir un résultat de recette souhaité.
- **Sources :** [profils partagés et volume VPS](shared-profiles.md),
  [PR5 R4/R5](../reports/pr5-review/review.md), [profil initial](workshop-starting-profile.md).
- **État :** sauvegardes partagées documentées. Préserver le volume Docker lors des
  déploiements. Les valeurs de démonstration ne s'appliquent pas silencieusement
  aux brouillons existants et ne sont pas des règles d'équilibrage approuvées.

### ATT-10 — Respecter le split sans jeter les acquis génériques

- **Attente :** distinguer cible Waar, ancien moteur générique 3.1, Arbestra et
  outils réutilisables ; ne pas reprendre la recherche de fidélité Legacy comme objectif.
- **Sources :** [frontières](../../waar-v3/docs/combat-project-boundaries.md),
  [T19 et historique des recherches](../../waar-v3/docs/sol-autocalibration.md),
  [périmètre Waar](waar-micro-combat-scope.md), [spec cohortes actuelle](spec-moteur-cohortes-soufflerie-2026-09-13.md).
- **État :** décision de séparation explicite. PHP/Rust/WASM 3.1 constitue un
  existant documenté, pas un moteur équivalent interchangeable avec `waar-cohort-v2`.
  La source des formules actuelles reste leur contrat actuel et ses amendements.

### ATT-11 — Livrer une amélioration démontrable, avec un coût borné

- **Attente :** changements étroits, budget annoncé, réemploi et tests ciblés
  pertinents ; démonstration utilisateur avec le vrai runtime avant de conclure.
- **Sources :** [règles de livraison du 13](spec-moteur-cohortes-soufflerie-2026-09-13.md),
  [workflow](agent-workflow.md), [benchmarks VPS/local](benchmarks/2026-09-20/README.md).
- **État :** règle active. Ne pas relancer T31/T33 pour qualifier une modification
  d'interface, ni confondre combats/seconde, délai HTTP et premier aperçu visible.
  Les décisions et recettes déjà disponibles évitent des recherches répétitives.
- **Tranche cartouche du 25 septembre 2026, avant livraison :** ATT-01/ATT-11 ;
  sources lues : cadrage de la soufflerie, issue #19, présent registre, retour
  de Darthmoule sur la matrice monotype, `DuelService::simulate()` et tests UI/HTTP.
  Réemploi du batch de 50 combats. La matrice appréciée venait de la PR #11
  refusée et reste hors de cette livraison, sur décision explicite du PO.
  Échec à éviter : confondre pertes physiques et sortie projetée. Écart :
  le cartouche détaillait chaque type mais rendait peu lisibles
  les indicateurs globaux et le budget ; résultat visé : deux petits tableaux par
  sens avec victoires, morts, blessés, prisonniers, budget perdu et rounds moyens,
  détail par unité replié, sans calcul de combat côté JavaScript.

## Échecs connus à ne pas reproduire

| Échec documenté | Source et contrôle à reprendre si le parcours change |
| --- | --- |
| Une réécriture conserve les calculs mais perd les vecteurs interactifs. | [Motif de 04b](../../waar-v3/docs/combat-engine-v3.1-slice-04b-live-vectors-handoff.md) ; comparer le geste ancien/nouveau avant substitution. |
| Un point d'observation suit l'objectif ; les candidats n'ont plus de vecteurs. | [PR5 R2](../reports/pr5-review/review.md) ; déplacer une zone puis comparer une vraie mesure. Ce rapport décrit le défaut de cette version, pas sa persistance actuelle. |
| Une nouvelle seed change les résultats du témoin inchangé ; une réponse tardive réapparaît. | [PR5 R1/R3](../reports/pr5-review/review.md), [recette 04b](../../waar-v3/packages/combat-wind-tunnel/reports/slice-04b-review.md) ; mêmes entrées/mêmes mesures, générations et exports correctement attribués. |
| La simplicité de l'interface efface les réglages, ou un geste annulé recommence. | [PR5 R4/R5](../reports/pr5-review/review.md), [T26 et contre-recette](waar-micro-combat-t26-review.md) ; sauvegarde, sélection, Échap et annulation réels. |
| L'assistant exclut les monotypes ou transforme plusieurs axes en objectifs supplémentaires. | [Correction du cadrage](waar-micro-combat-scope.md), [32 et non 96 objectifs](waar-micro-combat-objective-contract-correction.md) ; identifier contrat et amendement applicables avant toute recherche. |
| Les tests passent, mais le testeur ne voit pas ce que son réglage a changé. | [Livraison épinglée et exemple sans écart de pertes](workshop-pinned-experiment.md), retour utilisateur du 21 septembre ; montrer l'effet observé ou sa limite, pas seulement la réception du paramètre. |

### ATT-12 — Conserver une attrition possible aux petits effectifs

- **Décision spécifiée le 21 septembre 2026 :** remplacer la troncature systématique
  par une conservation probabiliste individuelle des morts, blessures et captures,
  reproductible et indépendante du combat brut. Conserver la politique historique
  rejouable ; ne modifier ni reddition, ni profil RC-1, ni objectifs.
- **Sources :** [retour RC-1, sections 13–14](Soufflerie-Waar-exploration-et-guide-equilibrage.md),
  [issue corrective #12](https://github.com/NazzTazz/waar-micro-combat/issues/12),
  [politique provisoire §9](spec-moteur-cohortes-soufflerie-2026-09-13.md).
- **État au 21 septembre 2026 :** correction implémentée sur
  `fix/issue-12-probabilistic-consequences`, prête pour recette PO.
  Politique `/3`, protocole `sha256-counter52-binomial-btrs/1`, `/2` conservée.
  Parité et non-régression ciblées : 415 combats Rust, 220 PHP, reprises comprises ;
  462 vecteurs communs et contrôles de loi sur seeds fixes. Pas de recette E2E,
  de merge, de déploiement ou d’acceptation produit. Preuves et suite dans la PR
  [corrective #13](https://github.com/NazzTazz/waar-micro-combat/pull/13) liée à #12 ;
  le §9.5 de la spec de cette branche est l’amendement.
- **Contre-recette indépendante du même jour, `36cf34d` :** [rapport local](../reports/pr13-review/review.md).
  Sampler, parité, replay et batch ciblés passants ; geste K18L/S puis compression
  5 → 0 vérifié dans Chrome avec Rust. Attrition devenue non nulle et physique
  conservée sur les cas examinés. Deux réserves P2 : identité brute encore liée
  aux taux dans la soufflerie (préexistant) et amorçage/import RC-1 annoncé mais
  inexécutable tel quel. Recette partielle documentée, aucune acceptation PO.
  [Revue publiée sur #13 à la demande du PO](https://github.com/NazzTazz/waar-micro-combat/pull/13#issuecomment-5759781661)
  le 21 septembre 2026, sur le même HEAD `36cf34d`.
- **Rebase après décision PO, même jour, `7ac5e6f` :** #13 cible désormais `main`
  à `3cbc8ab`, SHA de production confirmé par le PO. Aucun commit ou composant
  introduit par #11 réintroduit. Sources/tests moteur identiques à `36cf34d` ;
  raccordement de production revérifié, 44 assertions, sampler 28 803 et batch 40,
  harnais JS model/UI et geste Chrome K18L/S passants. R1 conservée comme dette
  explicite ; R2 traitée par préparation RC-1 isolée puis chargement par le menu.
  [Nouvelle passe et limites](../reports/pr13-review/review.md#rebase-sur-la-production--7ac5e6f).
  La revue initiale reste historique ; aucune acceptation PO déduite du rebase.

## Tenue du registre

Une attente nouvelle ou modifiée reçoit une source, une portée et un statut.
Une clôture exige une preuve liée à la version examinée et au geste attendu.
Une suppression/remplacement conserve le motif et la décision qui l'autorise.
Mettre à jour cette page et le document canonique concerné ; ne pas produire un
nouveau récit concurrent. Les fichiers historiques et artefacts figés restent intacts.

### ATT-13 — Distinguer dégâts légers et blessure

- **Décision PO du 21 septembre 2026 :** seuil global de dégâts dans `[0,1]`,
  affiché en pourcentage ; blessé uniquement au-delà du seuil, strictement.
  Nouveaux profils : 20 %. Profils antérieurs sans champ : 0 %, conservant
  le comportement historique. Les blessés continuent de combattre.
- **Source et mandat :** [issue #14](https://github.com/NazzTazz/waar-micro-combat/issues/14).
  Le classement affecte blessures, captures et conséquences économiques, ainsi
  que la mesure morts + blessés ; il ne restaure aucune structure et ne change
  pas la résolution physique. Aucun tarif de soins ajouté dans la soufflerie.
- **État au 21 septembre 2026 :** implémenté localement sur
  `feature/issue-14-wound-threshold`, soumis à validation complète. Le ruleset
  ancien sans champ conserve 0 % et sa sérialisation ; profils chargés normalisés
  à 0 %, profils neufs à 20 %. Détail, batch Rust, conséquences, mesure et interface
  utilisent la même frontière fixe stricte. La [validation locale du 21 septembre](../reports/wound-threshold-validation/review.md)
  confirme la règle et le geste Chrome 0 → 20 → 0 %, mais relève une provenance
  batch PHP manquante, deux tests devenus incohérents avec le nouveau défaut et
  un libellé projeté trompeur. Aucune fusion, publication ou acceptation produit.

### ATT-14 — Conserver le hasard de chaque armée entre variantes et rôles

- **Décision PO du 22 septembre 2026 :** identité A/B indépendante des rôles,
  séparation de tous les usages, binomiale couplée et vérification statistique
  et physique aux différentes échelles. Même seed et événement comparable :
  aucun décalage dû à une autre consommation. Les effets physiques restent actifs.
- **Sources :** [issue #16](https://github.com/NazzTazz/waar-micro-combat/issues/16),
  [amendement §8.7](spec-moteur-cohortes-soufflerie-2026-09-13.md#87-amendement-16--hasard-adressé-et-binomiale-couplée-22-septembre-2026),
  [PR #17, suivi canonique courant](https://github.com/NazzTazz/waar-micro-combat/pull/17).
- **État livré :** implémenté, recetté, commité et poussé sur
  `fix/issue-16-addressed-rng`, base `55cfbe4`, HEAD `7c732de`. Nouveau protocole
  `sha256-binomial-tree/1` et conséquences /4 ; anciens rapports/hashes conservés.
  Cas Darth : ancien 7/11 blessés reproduit, nouveau 9/9, tirs archers inchangés,
  cycle 25→30→25 et inversion A/B vérifiés par services réels PHP/Rust.
  CI PHP 8.2/8.4 verte sur ce HEAD (run `35738821572`). Pas d'E2E navigateur
  (mandat PO), fusion, déploiement ou acceptation implicite.
- **Limites conservées :** quantification de probabilité sur 52 bits, contrôles
  bornés, pas de pertes linéaires promises avec l'échelle, surcoût mesuré et
  sérialisation navigateur des poids flottants documentés dans §8.7 et la PR.
  La dette de hash liée à la compression de #13 reste distincte.
- **Contre-recette et correction publiée, 22 septembre :**
  [R1 publiée sur `7c732de`](https://github.com/NazzTazz/waar-micro-combat/pull/17#issuecomment-5779002936)
  reproduit une interruption pour des poids de ciblage valides très déséquilibrés.
  Correction commitée et poussée en `bd79dff` : recalcul et normalisation des probabilités
  conditionnelles en PHP/Rust, uniquement dans le protocole adressé ; sampler,
  adresses et chemin historique conservés. Cas exact, débordements, sous-normaux,
  conservation des tentatives et batch couverts par
  [les tests dédiés](../engines/waar-cohort/tests/AddressedTargetingTest.php).
  [Preuves et limites de correction](../reports/pr17-review/review.md#correction-locale-de-r1--22-septembre-2026).
  Les poids extrêmes révèlent aussi une divergence historique de sérialisation
  des hashes PHP/Rust, distincte du ciblage et non corrigée ici. Aucune acceptation
  produit déduite des tests ; aucun E2E navigateur selon le mandat courant.
- **Recette finale indépendante sur `bd79dff`, même jour :** R1 levée, suites
  et CI 8.2/8.4 passantes ; avis favorable au merge, avant autorisation de fusion.
  [Preuves et portée](../reports/pr17-review/review.md#recette-finale-indépendante--bd79dff-22-septembre-2026).
  La sonde complémentaire précise la dette extrême : poids JSON restitués et
  hash peuvent différer entre PHP/Rust, déjà sur la base ; aucune divergence
  physique observée sur les cas testés. Pas de garantie exhaustive ni d'E2E.
- **Intégration autorisée, 22 septembre à 16:32 UTC :**
  [avis final publié](https://github.com/NazzTazz/waar-micro-combat/pull/17#issuecomment-5780187635),
  puis #17 fusionnée en `d7b7b73fd436db16b4cec277add35edc0e859c54` ; #16 fermée.
  `main` distant et arbre du merge identiques au HEAD recetté, vérifiés par API.
  Aucun déploiement ni acceptation d'équilibrage déduite.

### ATT-15 — Déployer explicitement une release immuable et préserver les profils

- **Mandat PO du 22 septembre 2026 :** implémenter la proposition de
  [Sol](../codex-session-DEPLOY), avec les objections de revue acceptées : identité
  réelle des images, ancien Compose pour le rollback, sauvegardes non bloquantes
  pendant le verrou, tests de panne exécutables et verrou de déploiement VPS.
- **Reprise historique :** ATT-09 et ATT-11 ; `docs/shared-profiles.md`, PR #6/#8 ;
  WAI #71/#72, #77/#78/#81, #85/#86 ; code `ops/sim` et workflows WAI, frontière
  `../waar-v3/docs/combat-project-boundaries.md`. Réemploi du Dockerfile, Compose,
  volume et API Rust actuels. Échec historique évité : bootstrap consommé par un
  enfant lisant stdin. Aucun changement de gameplay ou des références gelées.
- **Résultat visé :** tag `engine-vX.Y.Z-rc.N` intégré à `main`, CI, archive SHA-256,
  SSH épinglé, build/réemploi d'image, contrôles internes et profils inchangés,
  puis bascule de `current`. Échec : retour à l'image exacte et l'ancien Compose.
- **État :** implémentation locale dans le checkout isolé
  [deployment-worktree](../reports/deployment-worktree/ops/demo/README.md),
  branche `feature/immutable-deployment`, commit `207d99a`, base `d7b7b73`.
  Recette locale des scripts et Docker réel passante. Pas de publication,
  fusion, configuration des secrets ou déploiement de production.

## Point de reprise courant — publication de la coupe complète, 25 septembre 2026

- **Décision et résultat :** ATT-07/ATT-08/ATT-14 ; le PO a précisé que les deux armées partagent par définition la même météo. La [coupe complète publiée](../engine-docs/campaign/2026-09-25-full/README.md) conserve les 187 plans complets, agrégats, comparaisons, plans exacts, empreintes et figure SVG. Sur W, 25,2 M combats à météos différentes sont archivés mais exclus des conclusions de gameplay ; 1,08 M combats effectifs uniques à météo commune restent lisibles. Réemploi de la structure de publication de la PR #21 et des scripts d'audit locaux ; échec évité : présenter les facteurs W asymétriques comme des conseils de réglage ou recompter les témoins. Les conclusions restent exploratoires, sans acceptation du moteur.
- **Checkout et suite :** branche `docs/publish-full-campaign-2026-09-25` issue de `main` `fb7e6f0`, dans le worktree `tmp/main-clean` initialement propre. Le checkout principal `fix/issue-16-addressed-rng` à `0f51f9d` et ses modifications préexistantes restent intacts. Les snapshots et réponses batch restent locaux sous `reports/` ignoré ; seuls Markdown, SVG, CSV agrégés, JSON de provenance et plans déclaratifs sont publiés. Vérifications : 187 plans et les CSV copiés ont les mêmes SHA que les sources ; 28 liens des documents publiés résolvent, SVG et JSON valides, `git diff --check` sans erreur. Livrer la PR documentaire, puis contre-lire les constats W avant tout nouveau protocole. Aucun combat, moteur, profil, worker ou déploiement modifié par cette publication.

## Point de reprise historique — livraison du CLI paramétrique, 24 septembre 2026

- **Attentes / sources :** ATT-07 et ATT-14 ; [format et commandes du CLI](parametric-campaign-cli.md), [protocole d'exploitation](../ops/campaign/README.md), issue #16 et PR #17 déjà fusionnée, code de préparation web, runtime Rust/PHP et tests natifs. Réemploi : `EngineProfile`, `CohortRequestFactory`, `ProcessCohortRuntime` et résolution batch existante. Échec évité : élargir le contrat web à 100, perdre les indices de répétition entre lots, ou construire l'image depuis un profil sous `reports/` ignoré par Git. Résultat visible : prévisualisation, reprise et export du CLI depuis les plans déclaratifs ; un clone propre contient le profil exact nécessaire à la construction de l'image de campagne.
- **Checkout :** branche `fix/issue-16-addressed-rng`, HEAD du code livré `645cbb5` après la passe PSR-12 `b1495cd` ; `origin/main` vérifié à `6620f85` avant publication de cette note. La PR #17 de cette branche est déjà fusionnée ; aucune nouvelle PR ouverte à ce stade. Les autres modifications locales de documentation et d'interface, les résultats sous `reports/` et les archives/binaires non suivis restent hors livraison.
- **Vérifications :** les 192 plans paramétriques versionnés pointent vers le même chemin de profil, et la référence versionnée a le SHA-256 `4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`, identique à l'export authentique local. Le Dockerfile de campagne copie cette référence à l'emplacement attendu ; son ignore-file spécifique inclut uniquement ses sources nécessaires sans élargir le contexte de la démo. Composer strict/install, PHPUnit 159 tests / 24 125 assertions, JS, Rust 11 tests, format Rust, style PHP et smoke T24 2 400 combats passent ; les deux JSON du smoke sont identiques aux précédents. Prévisualisation 2 000 répétitions/sens : 4 000 combats décomptés, aucun exécuté. Pas de campagne E/X/W relancée.
- **Reste :** Docker n'est pas disponible sur le PC, donc la construction depuis un clone propre reste à vérifier sur un hôte Docker isolé. Ouvrir une nouvelle PR vers `main`, exécuter la CI sur ce nouveau diff et examiner la fusion avec la livraison de déploiement immuable #18. Le push de branche ou sa fusion ne reconstruit pas l'image VPS épinglée ; aucun tag de déploiement n'est créé. Les workers VPS et leurs résultats restent inchangés.

## Point de reprise historique — première analyse figée, 24 septembre 2026

- **Attentes / sources :** ATT-04, ATT-07 et ATT-08 ; [README de campagne](../experiments/campagne-coeur/README.md), [bilan de préparation](../reports/campagne-coeur/preparation/PREPARATION.md), code Rust et exporteur PHP. Réemploi du CLI paramétrique et des lots enregistrés. Échec à éviter : interpréter comme A/B des pertes `B-A` étiquetées selon l'ordre des rôles.
- **Checkout :** `fix/issue-16-addressed-rng`, HEAD `bd79dff8e07e85c6d1c01d6adaaee2382576b459`, base distante non revérifiée lors de cette lecture. Travail local antérieur préservé, notamment les modifications moteur et interface déjà présentes ; trois plans C et le script de traces D3 sont ajoutés localement. Aucun commit, PR, moteur, profil ou déploiement modifié ici.
- **Réalisé :** 12 plans complets, 1 112 000 combats batch dont 620 000 exécutés par le PO, plus quatre replays individuels D3 ; profil et binaire d'empreintes constantes, zéro erreur de lot. D1–D5 et confirmations C1–C3, y compris pertes physiques/projetées et indicateur économique après correction d'export, sont lus dans [l'analyse intermédiaire](../reports/campagne-coeur/ANALYSE-DIAGNOSTICS.md). Les sorties brutes et CSV sont sous `reports/campagne-coeur/`.
- **Correction d'export autorisée et réalisée :** les colonnes de pertes/projections/indicateur économique A/B des lignes `B-A` étaient inversées par l'ordre d'insertion du CSV. L'exporteur écrit maintenant chaque ligne dans l'ordre de l'en-tête ; test de non-régression sur les deux sens, puis réexportation des 12 plans **sans combat**. Audit des 5 560 lots et 484 lignes : zéro écart sur les métriques par camp/type et l'indicateur économique. Manifests inchangés. L'ancienne attribution erronée de morts reste retirée ; les nouveaux chiffres sont explicitement recalculés dans l'analyse.
- **Bloc M autorisé et lancé :** 63 plans actifs, 15 876 000 combats prévus ; plans et profil SHA vérifiés, mêmes sources moteur que les diagnostics. Le PC a commencé le 23 septembre à 14:43 (heure de Paris), puis a été arrêté sur instruction du PO : 18 plans complets/exportés, 3 156 000 combats retenus ; un 19e plan partiel à 1 600 combats Windows est conservé mais exclu, car il repart de zéro sur VPS sans mélanger les provenances. Un lot témoin de 100 répétitions par sens sur Linux a donné exactement la même requête et réponse batch que sur PC. Après essai de charge, deux superviseurs VPS utilisent **la même image séparée de la production**, chacun hors réseau, à 0,7 vCPU / 600 Mio. A : 26 plans / 7 440 000 combats ; B : 19 plans / 5 280 000 combats. A+B et les 18 plans PC complets couvrent exactement les 63 plans sans chevauchement ; voir [protocole de répartition](../ops/campaign/README.md). Sous cette charge, l'hôte gardait 19–29 % de CPU libre, sans attente disque, swap actif ou steal observés sur l'échantillon ; les services de production restaient disponibles. Les deux shards VPS sont **en cours** : vérifier les 63 manifests et exports, puis rapatrier les résultats avant de déclarer le bloc complet. Aucun bloc W/E/X lancé ni acceptation de gameplay inférée. Modifications locales préexistantes préservées, aucun moteur, profil ou déploiement web changé.
- **Analyse locale du 24 septembre :** [instantané figé et première lecture](../reports/campagne-coeur/analyse/runs/2026-09-24T012437/README.md) (ATT-04, ATT-07, ATT-08). 70 plans complets disponibles à la coupure, 15 428 000 combats inscrits et 12 936 000 uniques après déduplication de témoins partagés ; 77 140 lots et exports vérifiés. Les 117 plans actifs non terminés (5 M, 90 W, 6 E, 16 X) restent hors conclusions. Cinq constats exploratoires et deux raffinements **proposés, non exécutés** sont documentés ; ni acceptation de gameplay ni correction moteur. Scripts et résultats sont locaux sous `reports/` (ignoré par Git), les workers VPS n'ont pas été modifiés. **Prochaine action :** contre-analyse des constats, puis nouvel instantané quand des plans complets supplémentaires seront disponibles ; conserver celui-ci immuable. Base distante, état des workers après la coupure et disponibilité W/E/X non revérifiés dans cette analyse.
- **Amendement M→E→X→W et préparation distante, 24 septembre :** décision explicite du PO après contre-validation d'Astra, ATT-07/ATT-14. [Protocole opérationnel](../ops/campaign/README.md) et [inventaire amendé](../ops/campaign/active-next-plans.csv). Les 22 plans actifs E/X, contrairement aux seuls huit plans sources examinés initialement par Astra, ont été vérifiés neutres, intacts et conformes à leurs SHA. Les 112 plans E/X/W sont transférés au VPS ; E (6 plans, 2,448 M) et X (16 plans, 2,520 M) sont répartis sur deux workers, W en deux shards de 45 plans et 13,32 M chacun. `run-next-vps.sh --check-only` et prévisualisations E/X/W dans l'image passent sans combat. Un relais `start-e-x-after-m.sh` est détaché et **attend** les 45 manifests/exports M distants avant de lancer E et X ensemble ; au dernier contrôle, M était à 44/45 distants. W est préparé mais **non lancé et non programmé automatiquement**. Ni moteur, RNG, profil, plan de combat ni déploiement web modifiés. Prochaine action : vérifier le passage du relais puis la complétude E/X ; décider ensuite du lancement W1/W2. Les anciens instantanés et résultats M restent inchangés.
- **État VPS et quota, 24 septembre 10:19 UTC :** M distant est complet/exporté (45/45, 12,72 M), E complet/exporté (6/6, 2,448 M), X en cours (7/16 complets/exportés au dernier relevé, zéro erreur) ; W1/W2 restent non lancés. À la demande du PO, X actif est passé de 0,7 à 1,0 vCPU par `docker update` sans interruption ; le lanceur distant a été remplacé atomiquement pour les conteneurs futurs et ses deux shards W ont passé `--check-only` (45 plans, 13,32 M chacun). Un [surveillant ponctuel](../ops/campaign/keep-x-at-one-vcpu.sh) garde à 1,0 vCPU les prochains conteneurs X du superviseur déjà lancé. Scripts locaux non suivis sous `ops/campaign/`, ancien lanceur distant conservé en `.0.7-preserved` ; moteur, profils, plans et image inchangés. **Suite :** vérifier le quota et le journal du surveillant au prochain plan X, puis auditer les 16 manifests/exports X avant toute décision de lancement W.
- **Lecture du combat demandée pour Darthmoule, 24 septembre :** [PDF pseudocode](../output/pdf/waar-micro-combat-v3-deroulement-combat.pdf), produit par [son script](../bin/render-combat-pseudocode-pdf.py), et [version simplifiée en français courant](../output/pdf/waar-micro-combat-v3-deroulement-explique.pdf), produite par [son script](../bin/render-combat-explique-pdf.py) ; la [première version Markdown](deroulement-combat-cohortes-pseudocode.md) reste conservée. Sources vérifiées : factory web, `v2.rs`, RNG adressé et projection `/4`. Écart corrigé : le pseudocode seul ne répondait pas au besoin d'une lecture courante, notamment sur la précision tirée au début du round et les cohortes. Les effets acquis sont déjà importables en JSON, tandis que des compétences d'entraînement restent futures. Aucun moteur, profil, binaire ou résultat de campagne modifié pour ces documents ; PDFs rendus et vérifiés localement, **pas encore relus par le testeur**. L’UX bornée de l’issue #19 reste le prochain chantier choisi par le PO ; ne pas étendre cette note en refonte implicite.
- **Convention PHP approuvée par le PO, 24 septembre :** PSR-12, avec séparation visible de chaque instruction et contrôle complémentaire des frontières `}foreach` / `}$next`. Sources relues : présent registre, [convention PHP-FIG](https://www.php-fig.org/psr/psr-12/), `DuelService`, factory, runtime PHP et tests ; aucun formateur local préexistant. Réemploi : PHP-CS-Fixer v3.95.15 épinglé, [configuration](../.php-cs-fixer.dist.php), [contrôle des frontières](../bin/check-php-block-boundaries.php). Échec évité : mélanger reformattage, gameplay et provenance du moteur utilisé par la campagne. La passe a formaté 117 des 160 PHP initiaux du périmètre ; le contrôle complémentaire a séparé 205 frontières que le formateur laissait accolées. Les références figées, les plans et le snapshot `waar-v3` restent hors périmètre. Vérification locale : 162 lint PHP sans erreur, formateur à zéro écart sur 161 fichiers, frontières à zéro, Composer strict valide et `install` sans changement de dépendances, PHPUnit 159 tests / 24 125 assertions, scripts JS verts ; smoke T24 de 2 400 combats dans un dossier temporaire, `report.json` et `experiment.json` strictement identiques aux exports `reports/smoke/` préexistants, dossier temporaire retiré. Aucun moteur Rust ni image de campagne reconstruit ; PDFs et résultats de campagne non modifiés. Les longs littéraux de tableaux ne sont pas automatiquement coupés à 120 caractères (limite souple). Checkout `fix/issue-16-addressed-rng` au HEAD `bd79dff8e07e85c6d1c01d6adaaee2382576b459`, nombreux fichiers locaux préexistants non suivis ou modifiés préservés ; prochaine action : revue du diff de formatage avant tout commit, sans toucher aux workers VPS.

## Point de reprise historique — déploiement immuable, 22 septembre 2026

- **Checkout :** `reports/deployment-worktree`, branche
  `feature/immutable-deployment`, HEAD
  `207d99a359ab1df38ec190fcc9948abce4b083a4`, arbre et index propres ;
  base `origin/main` revérifiée en fin de session à
  `d7b7b73fd436db16b4cec277add35edc0e859c54`. Le checkout principal reste sur
  `fix/issue-16-addressed-rng` à `bd79dff`, avec toutes les modifications
  préexistantes préservées. Ce registre reste local et non suivi.
- **Réalisé :** workflow de tag RC ; scripts de transport, activation, déploiement,
  vérification et bibliothèque commune ; tests de transactions et stack Docker ;
  documentation d'exploitation ; sauvegarde HTTP 503 immédiate si le verrou est
  occupé, lectures conservées et nouvelle tentative possible.
- **Contrôles passants :** Composer validate/install, 149 tests PHP / 24 069
  assertions, 11 harnais JS dont sauvegarde HTTP réelle sous verrou, smoke 2 400
  combats. Syntaxe Bash, ShellCheck 0.10.0, actionlint 1.7.7, YAML, liens ajoutés,
  diff et 18 scénarios de scripts passants (dont signal TERM, stockage initialement
  absent et blocage d'une nouvelle promotion après rollback échoué).
- **Recette Docker réelle :** Docker 27.5.1 / Compose 2.32.4 temporaires sous WSL,
  image PHP/Apache/Rust construite avec les bases épinglées ; adoption sans `current`,
  premier déploiement, réemploi du même SHA sans rebuild, puis runtime volontairement
  refusé dans une archive de test et rollback vérifié. Ancienne image exacte
  `sha256:5d2995be2ffcb6ffa6a7a0d1c473e164cc9aaa74e61979db896eeb33a0a18f27`,
  ancien lien et empreinte du stockage restaurés ; six combats Rust minuscules
  dans les trois vérifications. [Log local](../reports/deployment-worktree/reports/docker-stack.log).
  Limite environnementale : builder Docker historique et réseau hôte pour les
  builds uniquement, via un wrapper local ignoré (WSL sans iptables). Scripts de
  production et réseau/protections des conteneurs inchangés. CI distante et chemin
  SSH/VPS non exécutés. Aucun E2E navigateur de recette métier.
- **Local seulement :** dépendances et binaire Rust identique à la base réutilisé
  dans le worktree ; helpers, logs et smoke sous son `reports/` ignoré. Aucun
  profil existant de démonstration utilisé ; volume et profils Docker de test
  créés dans un daemon privé temporaire, socket distinct, aucun port publié.
  Daemon arrêté et fichiers temporaires éliminés à la fin ; aucune donnée réelle
  supprimée. Les 16 fichiers de livraison sont dans le commit local ; ni politique
  personnelle, ni registre local, ni transcript Sol n'y sont inclus.
- **État de livraison :** environnement GitHub `waar-engine-production` configuré
  avec les quatre secrets attendus, la variable de port et une politique limitée
  aux tags `engine-v*-rc.*` ; clé Ed25519 dédiée ajoutée au compte VPS, connexion
  OpenSSH avec hôte épinglé et `sudo -n` vérifiés. Les clés privées temporaires
  locales ont été supprimées après enregistrement. Prérequis VPS vérifiés : Docker
  29.7.2, Compose 5.4.0, réseau, volume et Compose original. Publication, fusion,
  tag et première promotion sont terminés dans la section suivante. DNS/TLS/Caddy
  n'ont pas été modifiés ; aucune acceptation PO implicite.

### Promotion v3 RC1 — 22 septembre 2026

- **Intégration :** [PR #18](https://github.com/NazzTazz/waar-micro-combat/pull/18)
  créée sur `207d99a`, trois checks verts (PHP 8.2/8.4 et transaction Docker),
  puis fusionnée dans `main` par
  `6620f8530654c4149e757048d4eea758a0ec2f51`. Le `main` distant a été vérifié
  sur ce SHA avant promotion.
- **Promotion explicite :** tag annoté `engine-v3.0.0-rc.1` créé et poussé sur le
  merge. [Workflow 35766702395](https://github.com/NazzTazz/waar-micro-combat/actions/runs/35766702395)
  terminé avec succès : validation complète 3 min 31, déploiement 1 min 17.
- **VPS vérifié après Actions :** `/opt/waar-micro-combat/current` pointe vers la
  release `6620f85`, image `waar-engine-demo:6620f85…`, conteneur healthy et
  volume `waar-engine-demo_profile-saves` monté en écriture. Contrôles internes :
  `runtime=rust`, 10 profils listés et chargés, empreinte `profiles.json` inchangée.
  Image réelle `sha256:beb9b00c6496434aefc50b22362420c1ae347c1279c1925669ea74c3f7368399`.
  Rollback non requis (`rollback=not-needed`).
- **Limites :** aucun changement DNS/TLS/Caddy, aucune recette métier navigateur,
  aucune campagne T31/T33 et aucune acceptation d'équilibrage implicite. Le checkout
  principal et ses modifications préexistantes restent inchangés.

## Point de reprise historique — #17 intégrée, 22 septembre 2026

Le suivi de livraison distant reste la [PR #17](https://github.com/NazzTazz/waar-micro-combat/pull/17).
Correction, recette finale, publication de l'avis et fusion terminées à la
demande du PO. La description distante a été réconciliée après fusion ; la
livraison initiale à `7c732de` y reste historique.
[Avis final sur `bd79dff`](../reports/pr17-review/review.md#recette-finale-indépendante--bd79dff-22-septembre-2026) :
R1 levée, favorable au merge dans le périmètre vérifié, puis intégration autorisée.

- **Branche/HEAD :** `fix/issue-16-addressed-rng`,
  `bd79dff8e07e85c6d1c01d6adaaee2382576b459`, base distante `55cfbe4`.
  Checkout conservé pour préserver le travail local préexistant. `main` distant
  vérifié après fusion à `d7b7b73fd436db16b4cec277add35edc0e859c54`, avec pour
  parents `55cfbe4` et `bd79dff`. Arbres merge/HEAD recetté identiques :
  `2f5c0c6b5290df4959f44cf9f89af7aaf2a11e64`.
- **Réalisé :** R1 reproduite dans les tests avant correction (exception PHP et
  panic CLI Rust), puis corrigée dans les deux résolveurs. Requête exacte de la
  revue : `round_limit`, un round, allocations `[1,0,0,0]` dans les deux runtimes.
  Spécification §8.7 précisée ; anciens protocoles et hashes non réécrits.
- **Contrôles :** Composer validate/install ; suite principale 148 tests / 24 063
  assertions ; moteur 67 / 35 781 ; Rust 11 ; 11 harnais JS, HTTP réel et benchmark
  compris ; smoke 2 400 combats. 24 rapports historiques PHP/Rust identiques à
  la base PHP `55cfbe4`. Build release, format Rust et diff contrôlés. Composer
  a utilisé le lock inchangé ; accès Packagist/cache indisponible, audit réseau
  complet non attesté. Pas d'E2E navigateur, de T31/T33 ni de comparaison de finalistes.
- **Publication :** commit `bd79dff`, quatre fichiers : `RoundResolver.php`, `v2.rs`,
  nouveau `AddressedTargetingTest.php` et spec canonique. Push simple sur la branche
  de #17, index vide après commit. [CI PHP 8.2/8.4 démarrée](https://github.com/NazzTazz/waar-micro-combat/actions/runs/35751879034)
  sur ce SHA ; **succès PHP 8.2/8.4 vérifié lors de la recette finale**.
  HEAD/base distants revérifiés inchangés avant la fusion, sans conflit.
  Avis final publié et relu identique au texte préparé ; #17 fusionnée à
  16:32:12 UTC, #16 fermée, résultat confirmé par API et `ls-remote`.
  [CI après fusion sur `d7b7b73` également verte](https://github.com/NazzTazz/waar-micro-combat/actions/runs/35754760405),
  état `completed/success` vérifié après réconciliation de la PR.
- **Recette finale indépendante :** export isolé de `bd79dff`, 428 fichiers
  vérifiés identiques au commit hors conversions de fins de ligne Windows.
  Composer validate/install, suites atelier 148 / 24 063, moteur 67 / 35 781,
  Rust 11, 11 harnais JS/HTTP et smoke 2 400 combats passants. R1 exacte et
  24 rapports historiques rejoués ; sonde indépendante 1 380 contrôles sans
  échec. Sonde complémentaire : 108 combats, physique et allocations stables,
  18 écarts de restitution des poids extrêmes sur 504 contrôles. Diagnostic :
  uniquement poids JSON/hash ; mêmes écarts prouvés avec PHP et Rust de la base
  `55cfbe4`, compilée séparément pour attribution. Pas de nouvelle régression
  bloquante ; la dette de portabilité des poids extrêmes demeure explicite.
- **Local seulement :** ce registre reste non suivi. Rapport complété, sonde
  et résultats sous `reports/pr17-review/` ignoré ;
  binaires release locaux reconstruits lors de la correction, réutilisés pour
  la recette finale ; export `reports/pr17-review/final-head/`, logs et sondes
  de recette ignorés, binaire de base et smoke isolés. Toutes les modifications
  préexistantes (template PR, AGENTS, README, workflow/index, HTML/CSS, import,
  exploration/guide, spec feedback, archives moteur et corpus) sont conservées.
- **Reste et prochaine action :** aucun reste correctif ou d'intégration sur #17 ;
  avis publié et fusion vérifiée. Aucun déploiement effectué. Limite de rejeu inter-runtime
  des poids extrêmes documentée dans §8.7 et précisée dans le rapport final
  (arrondi des poids restitués, en plus du hash) ; ne pas la confondre avec
  une régression de R1. Aucun E2E navigateur selon le mandat courant, aucune
  acceptation PO implicite.

## Point de reprise historique — seuil de blessure, 21 septembre 2026

- **Suivi canonique :** [issue #14](https://github.com/NazzTazz/waar-micro-combat/issues/14),
  publiée à la demande du PO ; implémentation destinée à Sol.
- **Base vérifiée :** `main`, HEAD local et distant
  `52ac549cfae0c6dcaeb186362595cdb657e32067` ; #13 désormais fusionnée.
  Aucun déploiement ni acceptation produit déduit de cette fusion.
- **Réalisé :** lecture ciblée du code, de #12 et de la livraison/contre-recette
  #13 ; réemploi de `UnitCohort::state()`, `CombatArmy`, replay, projection/batch
  Rust, migrateur, contexte de mesure et contrôles Combat. Échec évité : aucun
  défaut 20 % appliqué aux profils historiques, aucune modification des dégâts.
  Écart traité : frontière stricte, profils/sauvegardes, provenance et libellés.
- **Vérifications ciblées de livraison (historiques) :** 12 tests moteur/replay/parité, 82 assertions ;
  3 tests profil/migration/sauvegarde, 24 assertions ; test Rust de frontière ;
  harnais UI et HTTP conséquences (118 combats Rust) passants. Build Rust release,
  Composer validate/install, format Rust et diff contrôlés. Aucun parcours navigateur
  E2E, recherche T31/T33, smoke général ou comparaison de finalistes exécuté.
- **Local seulement :** travail préexistant conservé, dont ce registre non suivi ;
  texte de publication sous `reports/wound-threshold-issue.md`. Validation indépendante
  et stockage isolé sous `reports/wound-threshold-validation/`, smoke sous `reports/smoke/`.
- **Validation complète demandée ensuite par le PO :** sur la même branche/HEAD et
  diff non commité, 49 tests moteur / 29 527 assertions, 8 Rust, 148 contrôles
  indépendants et smoke passants. Suite principale : 2 échecs sur 143 tests ;
  suite JS : benchmark en échec de parité de provenance. Geste Chrome à un round,
  seed 42 : 18 → 0 → 18 chevaliers blessés, physique et rejeu retour identiques.
  [Constats R1/R2/R3, preuves et limites](../reports/wound-threshold-validation/review.md).
  Code applicatif non corrigé pendant cette validation.
- **Publication de revue autorisée par le PO :** aucune PR distante pour cette
  branche ; [verdict publié sur #14](https://github.com/NazzTazz/waar-micro-combat/issues/14#issuecomment-5767229130)
  le 21 septembre 2026 à 20:42 UTC, avec modèle GPT-6 Astra / effort `high`.
  Repli explicitement autorisé après le refus initial de la revue automatique.
  Texte relu par API et vérifié identique au [commentaire préparé](../reports/wound-threshold-validation/github-comment.md).
- **Correction autorisée et livrée :** commit `7fd1475b69c10cd393599b98a4ca2eff5cb924ac`,
  [PR #15, suivi canonique courant](https://github.com/NazzTazz/waar-micro-combat/pull/15).
  R1 : provenance batch complète ; R2 : fixtures 0/20 % ; R3 : libellés brut/projeté.
  Contre-recette : 13 tests ciblés / 266 assertions, suite 146 / 24 038, moteur
  49 / 29 527, 8 Rust, 11 harnais JS et smoke 2 400 combats passants ; sonde
  indépendante 148 contrôles, batch complet identique. Chrome compression 0 % :
  brut/projeté distingués, cycle seuil 0→20→0 et rejeu complet exact, physique stable.
  Diff #14 committé, autres fichiers locaux conservés. CI 8.2/8.4 verte
  sur ce SHA (run 35654099601) ; PR #15 fusionnée à 20:57 UTC, `main` distant
  vérifié à `55cfbe423ec55b99e6f30d832332e227e0a0a5cd`, #14 fermée.
  [Verdict signé publié sur la PR](https://github.com/NazzTazz/waar-micro-combat/pull/15#issuecomment-5767405666).
  Aucun reste correctif/intégration ; checkout local conservé sur feature à `7fd1475`.
  Détails et limites dans la PR ; aucune acceptation produit ni déploiement.
  La dette R1 de #13 reste distincte. Reprise si nécessaire : GPT-6 Astra / high.

## Point de reprise historique — #13 avant fusion, 21 septembre 2026

> État historique, pas un blocage courant : #13 a été fusionnée en `52ac549c`.
> Ses mentions d'attente de CI/fusion ci-dessous ne s'appliquent plus au chantier
> courant. #15 est à son tour intégrée en `55cfbe4`, sans acceptation PO déduite.

- **Suivi canonique :** [PR #13](https://github.com/NazzTazz/waar-micro-combat/pull/13),
  liée à [#12](https://github.com/NazzTazz/waar-micro-combat/issues/12).
  Rebase demandé après refus de #11 et rollback nocturne, terminé et publié.
  HEAD `7ac5e6f0fb21fd397a89ba9bff50164f58234654`, base `main` à
  `3cbc8ab8734c67300b71fdd7aa3610b1d8f3323c`, SHA de production confirmé par le PO.
  Deux commits, 26 fichiers ; aucun commit de #11 dans l'ascendance. Aucun avis
  formel GitHub, acceptation PO, fermeture, fusion ou mise en production.
- **Checkout de livraison :** `reports/pr13-rebase-worktree`, branche locale
  `fix/issue-12-on-production`, suit `origin/fix/issue-12-probabilistic-consequences`.
  Arbre propre. `reports/issue-12-worktree` conserve l'ancienne livraison `36cf34d`
  pour référence ; sa branche locale n'est pas le HEAD distant actuel.
  Le checkout principal reste sur la branche de #11 à `b440b93` avec son travail local.
- **Réalisé :** politique /3 versionnée, flux indépendant, sampler binomial dédié,
  conservation /2 et replay, projection unique détail/batch, contexte/cache et
  provenance, comparaisons projetées incompatibles explicites, contrats et spec §9.
- **Preuves historiques sur `36cf34d` :** 462 vecteurs, loi/partition/grands effectifs, 20 cas RC-1, parité
  seeds imposées, trace et batch recomposé ; 415 combats Rust et 220 PHP au total.
  HTTP ciblé et JS passants ; Rust release construit une fois. CI finale PHP 8.2
  et 8.4 verte sur `36cf34d` (run GitHub 35591956259). Commandes et chiffres dans
  la PR. Aucun changement des artefacts gelés, des profils ou de la CI.
- **Preuves de contre-recette :** 28 803 assertions sampler PHP, 462 vecteurs Rust,
  142 assertions physiques et 12 de raccordement, trois harnais JS, sonde processus
  PHP/Rust et gestes Chrome K18L/S. Tests/sonde : 96 PHP, 92 Rust ; navigateur :
  606 Rust supplémentaires. Rapport, commandes, cas non rejoués en UI et budgets
  explicites dans `reports/pr13-review/review.md`. Code de `36cf34d` laissé intact
  pendant cette première revue ; preuves distinctes du nouveau rebase.
- **Preuves du rebase `7ac5e6f` :** 5 tests de raccordement / 44 assertions,
  sampler / 28 803, batch recomposé / 40 ; JS model/UI passants ; Composer,
  lint, liens et diff vérifiés. Chrome : profil RC-1 préparé et chargé par le menu,
  K18L/S, cartouche et deux détails Rust `/3` corrects. R2 traitée, R1 explicite.
  Budget : 29 PHP et au plus 456 Rust, dont 54 automatisés, 302 Chrome et 100
  conservatoirement comptés pour l'ouverture agent-browser interrompue.
  Aucun rebuild release/Docker : moteur identique, binaire vérifié et réutilisé.
  CI du nouveau SHA non attestée : workflow actif mais aucun run/check suite créé
  par GitHub après push et changement de base. La CI verte de `36cf34d` reste historique.
- **Local seulement :** registre actualisé ici (préexistant non suivi), rapports
  JSON sous le checkout de livraison, dépendances/binaires ignorés. Modifications
  préexistantes du template PR, AGENTS, README, docs/agent-workflow et index historique,
  import PowerShell, rapport RC-1, spec feedback, archives moteur/import et corpus
  partagés préservés, exclus du commit correctif.
  Ajouts locaux de revue sous `reports/pr13-review` (ignorés) : rapport, sonde,
  JSON de preuve, fixture reconstituée et stockage de profils isolé. Un export de
  test a également été téléchargé dans Downloads. Aucun profil partagé touché.
  Serveurs locaux :8096 et :8097 et onglets de recette arrêtés en fin de passe.
  Nouveau stockage RC-1, logs et description de PR préparée sous `reports/pr13-review`.
- **Suite :** résoudre l'absence de déclenchement CI sur le nouveau SHA avant
  fusion, puis recette/décision du PO. Aucune modification du workflow pour contourner ce point.
  R1 reste une dette non corrigée ; les limites de recette sont dans le rapport et
  la description actuelle de #13. Ancienne revue publiée sur `36cf34d` conservée ;
  description distante réécrite pour `7ac5e6f`, texte et cible vérifiés après publication.
