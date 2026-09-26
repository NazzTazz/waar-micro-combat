# B1 — latence de la soufflerie et coût du batch Rust

## Contrôle d'histoire avant modification

Branche `bench/issue-25-b1` créée depuis `main` / `origin/main` à `05ebe6f3f2c29c9faf80e28a2558ed60c13e130f` ; les fichiers non suivis du checkout restent préservés. Mandat : issue #25, suite de #24. Attentes ATT-01/02/03/04/07/08/10/11/14. Sources relues : benchmark du 20 septembre et sa fenêtre de 20 secondes, guide `ops/benchmark`, contrats et code `DuelService`, `CohortRequestFactory`, `ProcessCohortRuntime`, CLI Rust et tests ; ancien comportement progressif 04b et sa contre-recette dans `../waar-v3/`. Réemploi : benchmark existant, batch à plages, CLI JSONL multirequête, requêtes réelles de la soufflerie. Échec antérieur à éviter : attribuer au seul RNG un écart entre deux protocoles et appeler progressif un simple spinner. Écart B1 : pas de mesure du geste et pas de ventilation fiable du transport et de la résolution. Résultat attendu : délai utile observé, compromis de lots et postes de coût établis, sans changement de gameplay ni de parcours utilisateur.

**Contre-mesure demandée après la PR #26 :** le rapport B1 situe 98,6 % du temps natif dans `resolve_fast`, mais ce bloc est trop large pour expliquer la lenteur. Sources relues : rapport et données B1, code `resolve_fast`, `resolve_action`, `allocate`, `impacts_needed`, `apply_impacts`, `AddressedRandom::binomial` et tests de parité. Réemploi : feature `b1-profile`, corpus figé et pilote existants ; échec à éviter : prendre un microbenchmark isolé pour une cause du combat complet. Écart : temps inclusifs par opération dans la même résolution et part non attribuée. Résultat attendu : classement mesuré de ces opérations sur les cas B1, sans modifier les résultats.

## Premier geste réel, avant instrumentation du code

Windows, PHP 8.2.33, serveur local `127.0.0.1:8098`, Rust release existant, stockage de profils isolé. Profil Test 2 chargé par défaut, soldat A/B de la démo, météo neutre, seed courante de l'interface. Sonde temporaire JavaScript dans l'onglet, sans changement du code de l'application ; horloge monotone `performance.now()` côté navigateur. Les scripts locaux sont dans `reports/issue25-b1/` (ignoré). Les temps comprennent réseau local et rendu DOM mais ne ventilent pas encore le serveur.

| Geste | Dernière saisie → départ HTTP | HTTP | Fin HTTP → DOM | Dernière saisie → DOM |
| --- | ---: | ---: | ---: | ---: |
| Attaque soldat 9 → 10, vraie saisie navigateur | 305,3 ms | 382,7 ms | 6,7 ms | 694,7 ms |
| Retour 10 → 9, vraie saisie navigateur | 307,2 ms | 528,5 ms | 9,5 ms | 845,2 ms |
| 10 puis 11/12/13 pendant calcul, événements rapprochés dans le vrai DOM | 615,8 ms depuis 13, dont attente de l'appel ancien | 286,2 ms pour 13 | 7,5 ms | 909,5 ms |

Dans le dernier cas, la requête pour 10 a commencé puis fini après les changements suivants, sans remplacement du DOM par sa réponse obsolète ; une seule requête pour la dernière valeur 13 a suivi. Le test rapide utilise des événements DOM synthétiques espacés de 40 ms ; les deux premiers gestes utilisent le champ du navigateur. Le prochain frame après le DOM de 13 a été observé 5,7 ms plus tard. Le cartouche ne publie qu'une réponse complète de 50 combats par sens : son premier nouveau résultat est son résultat final. Le détail ponctuel n'a pas été relancé par ces saisies.

Comptage B1 à ce stade : 100 combats au chargement, 100 pour 10, 100 pour le retour à 9, 100 pour l'appel devenu obsolète et 100 pour 13, soit **500 combats**. La sonde n'a lancé aucune simulation supplémentaire ; serveur et navigateur arrêtés avant le benchmark de processus. La mesure froide du chargement n'a pas été horodatée par cette sonde tardivement installée : elle reste à mesurer séparément.

## Protocole et données

Mesure du 26 septembre 2026 sur le PC Windows du PO (i7-1065G7, 8 processeurs logiques, PHP 8.2.33, Node 24.19, Rust release). Le [corpus](corpus.json) est généré par `php bin/prepare-b1-corpus.php` depuis les profils Nazz et Test 2 : même seed 42, météo neutre, deux sens de confrontation et plages d'itérations disjointes. Il inclut archer 5 contre 5, lancier 171 contre chevalier 21, soldat 12 000 contre lancier 1 714, mélanges à budget 120 000 et 800 000 par camp, et matrice des 16 monotypes. Le grand mélange compte 52 154 unités au total. Le pilote `bin/benchmark-b1.mjs` compare le service PHP avec son processus Rust par appel et un processus Rust JSONL persistant ; un échauffement puis cinq répétitions sont demandés par cellule. Les sommes de contrôle portent sur le résultat complet canonisé. Chaque sortie refuse l'écrasement et conserve les erreurs et les cellules achevées.

[Mesures principales](measurements.json) : 62 cellules tentées, 14 paires PHP/Rust comparables avec résultats exactement identiques ; aucun écart de replay observé. Le watchdog de 2 s par appel tronque plusieurs grandes cellules. Le statut `partial-partition-error` provient du premier essai de partition 1 × 20 avec ce watchdog ; la [mesure dédiée](partition-20.json) avec 6 s par appel termine les trois partitions. Les valeurs ci-dessous sont les médianes des appels terminés, en millisecondes ; `> 2 000` signifie timeout, pas une durée mesurée.

| Charge et itérations par sens | PHP → processus Rust à chaque appel | Rust JSONL persistant |
| --- | ---: | ---: |
| Nazz, archer 5, 1 | 67,8 | 4,9 |
| Nazz, archer 5, 100 | 226,9 | 217,2 |
| Nazz, lancier 171 / chevalier 21, 20 | 170,5 | 81,4 |
| Nazz, soldat 12 000 / lancier 1 714, 5 | 398,3 | 336,3 |
| Nazz, mélange 120 000, 1 | 432,9 | 202,8 |
| Nazz, mélange 120 000, 5 | 1 358,8 (4 répétitions) | 708,2 |
| Nazz, mélange 800 000, 1 | > 2 000 | > 2 000 |
| Test 2, mélange 120 000, 1 | > 2 000 | 547,1 |
| Test 2, matrice 16, 1 | 366,8 | 210,1 |
| Test 2, matrice 16, 5 | > 2 000 | 1 162,8 |

Un duel, une matrice et la comparaison témoin/candidat sont donc des **charges distinctes** : le geste navigateur ci-dessous est un duel résumé à 50 combats par sens ; la matrice contient 16 scénarios et la comparaison de profils répète les scénarios pour chaque profil. Aucune durée de comparaison témoin/candidat complète n'a été mesurée dans cette campagne ; les chiffres de duel ou matrice ne doivent pas être présentés comme cette durée. Les cas à 20, 50 et 100 itérations du grand mélange sont censurés par le watchdog ; on ne peut pas extrapoler leur temps final à partir de ces cellules.

## Délai utile et répartition

Second [geste navigateur instrumenté](browser-server-events.json), serveur PHP local `127.0.0.1:8099`, diagnostic activé uniquement par `WAAR_B1_DIAGNOSTIC=1`, profil Test 2, attaque soldat 9 → 10. La dernière saisie précède l'appel HTTP de 311,9 ms ; HTTP prend 581,4 ms ; le DOM est à jour 6,9 ms après la réponse et le frame suivant 5,2 ms plus tard. **Saisie → effet visible : 900,2 ms** pour ce geste. `Server-Timing` donne parse 1,120 ms, attente du verrou 0,465 ms, service 569,826 ms, encodage 0,052 ms. Le diagnostic PHP pour le même `requestId=2` donne ouverture du processus 4,895 ms, attente et lecture 554,717 ms, décodage et contrôle 0,156 ms. Le premier chargement à froid (100 combats) a passé 2 502 ms dans lecture et attente du processus, sans chronométrage navigateur comparable. Ces mesures ne séparent pas la planification OS du calcul Rust et ne décrivent pas le VPS.

Sur cinq démarrages Rust indépendants, le cas archer 5 / une itération prend 184 à 399 ms du lancement à la réponse, contre 4,9 ms en processus persistant ; le mélange Nazz / une itération prend 603 à 816 ms à froid contre 202,8 ms persistant ([données](cold-rust.json)). La dispersion est forte ; le coût total à froid comprend initialisation et calcul, il ne se réduit pas au seul `spawn`.

Le [profil natif](native-profile.json) du mélange Nazz / cinq itérations attribue 98,6 % des 687,4 ms internes médians à `resolve_fast`, 1,3 % à la projection. Les [compteurs complémentaires](native-counters.json) relèvent 40 rounds pour 10 combats, environ 100 078 pas dans les arbres et 16 080 appels aléatoires adressés, 320 vagues d'allocation et aucune réallocation observée, au plus 44 cohortes par type. Les 16 appels RNG capturés puis rejoués dans le [microbenchmark](rng-micro.json) prennent 2,3–3,6 ms pour le domaine et 88,9–131,8 ms pour le binomial inclusif sur 100 replays ; on ne peut pas sommer ces temps ni les transposer en part du combat complet. Le test Rust isolé de fragmentation exécute 1 000 impacts en 2,79 ms pour l'état intact et 31,85 ms pour le même effectif fragmenté, avec somme de contrôle identique. L'instrumentation Rust a un coût visible sur les petits cas : ne pas comparer directement les temps profilés et les temps de production.

### Contre-mesure dans `resolve_fast`, après la PR initiale

La première livraison attribuait le coût à un bloc trop large. La [contre-mesure sur le même corpus](resolver-breakdown.json) ajoute des chronos **inclusifs** dans `resolve_action`, puis dans `apply_impacts` et l'appel au sampler. Cinq répétitions terminées, une chauffe, binaire release avec `b1-profile`, mêmes hashes complets que le binaire normal pour le mélange Nazz et la matrice Test 2. Les pourcentages ci-dessous sont calculés **au sein de chaque répétition**, et non à partir de médianes mélangées.

| Poste, mélange Nazz 120 000, 5 itérations par sens | Médiane instrumentée | Part de `resolve_fast` |
| --- | ---: | ---: |
| `resolve_fast` | 981,4 ms | 100 % |
| Répartition et application des impacts | 770,1 ms | 78,5 % |
| Dont répartition des impacts entre cohortes | 760,7 ms | 77,5 % |
| Dont `ConsequenceSampler::from_domain(...).binomial(n, 50)` dans l'arbre des impacts | 695,8 ms | **70,7–71,1 %** selon la répétition |
| Choix des types cibles | 94,4 ms | 9,6 % |
| Tirage des touches | 110,2 ms | 11,2 % |
| Mise à jour des cohortes après impacts | 5,2 ms | 0,5 % |

Sur ces dix combats, la répartition des impacts appelle le binomial adressé **13 272 fois** et parcourt **79 383 nœuds** de l'arbre. Chaque nœud construit un domaine distinct et exécute le sampler déterministe, dont le flux uniforme calcule SHA-256. C'est le **mécanisme mesuré qui prend le plus de temps** dans la résolution mixte, et non la mutation des cohortes. La matrice Test 2 confirme un poids important, mais différent : 29 314 nœuds d'impact ; le sampler d'impact représente 52,8–56,8 % de `resolve_fast`, tandis que le tirage des touches en représente 29,9–33,4 %. Les postes imbriqués du tableau ne s'additionnent pas. Le temps du sampler inclut création du domaine et tirage binomial ; cette mesure ne prétend pas isoler le seul SHA-256. Une optimisation reste à concevoir et à comparer avec parité exacte avant d'affirmer un gain.

Deux essais préliminaires de cette contre-mesure ont échoué au décodage du diagnostic : le pilote tronquait une ligne `B1_PROFILE` de plus de 4 Ko sur `stderr`. Sa capture est maintenant bornée à 256 Ko par appel ; les deux cellules du fichier publié ont chacune cinq répétitions complètes, zéro erreur et un hash constant. Les essais échoués sont comptés dans le budget, exclus des médianes.

## Lots et pistes classées

Pour 20 itérations par sens du mélange Nazz, [le contrôle exact](partition-20.json) donne le même hash final pour 1 × 20, 2 × 10 et 4 × 5. Le premier résultat arrive respectivement à 5 482, 4 059 et 2 406 ms ; le total est 5 486, 9 642 et 9 067 ms. **Recommandation mesurée pour cette charge :** garder un lot unique pour le total le plus court ; si une interface progressive est demandée, 4 × 5 avance le premier résultat mais reste loin de 500 ms et presque double le total. Le retour du premier lot devrait être présenté comme partiel, sans approbation ni objectif inféré. Les tailles ne sont pas une règle universelle pour les autres profils.

1. **Répartition aléatoire des impacts entre cohortes :** la contre-mesure attribue environ 71 % de `resolve_fast` du mélange Nazz aux appels du sampler dans l'arbre d'impacts. Comparer une modification ciblée de ce mécanisme sur le corpus figé, avec parité exacte des seeds et résultats ; risque élevé sur déterminisme, ordre des cohortes et règles. Aucune modification de gameplay ou d'algorithme n'est faite dans B1.
2. **Transport des petits appels :** étudier un processus Rust persistant avec protocole et gestion d'erreurs bornés. L'écart est net pour l'archer 5 à une itération ; pour les gros mélanges, le calcul demeure prépondérant. Risques : cycle de vie, isolation des requêtes, défaillance/reprise et mémoire résidente.
3. **Lots selon la charge et restitution progressive :** exposer des plages exactes et leur état incomplet seulement après décision produit. Le contrôle de partition confirme l'exactitude de la recomposition, mais le premier lot mixte mesuré reste à 2,4 s et le total augmente fortement. Le spinner existant n'est pas une restitution intermédiaire.

## Budget, limites et reprise

Le plafond de l'issue #25 est 250 000 combats **ou** 10 minutes, premier atteint. Le pilote principal réserve 18 846 combats tentés, dont 12 470 achevés, et termine en 178,5 s en incluant 4 020 combats et 25 s conservatoirement inscrits avant son départ. Les diagnostics séparés, la contre-mesure et deux passages navigateur portent le total conservateur de **la fenêtre de mesure** à environ **22 146 combats tentés** et 298 s réservées, sous les deux plafonds. Les suites de vérification ont aussi lancé des combats hors fenêtre chronométrée. Les fichiers de mesures séparés enregistrent leur `priorCombats` comme combats déjà tentés, parfois dans `completedCombats` ; ne pas additionner leurs champs `completedCombats` pour établir un total réussi. Pas de mesure RSS fiable, de profil échantillonné OS, de comparaison VPS ni de benchmark témoin/candidat complet. La valeur historique de 72 000/s n'a pas été retrouvée avec son protocole dans les sources locales consultées ; aucune comparaison de débit n'est inférée.

**Vérifications :** `cargo test --locked --offline` normal (10 tests de bibliothèque et un CLI), build release normal et avec `b1-profile`, test Rust de fragmentation avec feature, `composer validate --strict`, `composer install`, 161 tests PHP / 24 132 assertions, suite JavaScript incluant le test B1 de 1 408 combats, `composer smoke` (2 400 combats), lint PHP, `cargo fmt --check` et diff sans erreur. Le test historique `cohort-benchmark` a nécessité 60 s de plafond au lieu de 10 s sur ce PC pour achever ses 32 combats par moteur ; ses assertions restent identiques. Pas de comparaison des finalistes : aucun artefact de référence modifié.

**Reprise courante :** branche `bench/issue-25-b1`, base `main` / `origin/main` `05ebe6f3f2c29c9faf80e28a2558ed60c13e130f`, commit initial `7dc4e7f`, contre-mesure `8aae797` et [PR brouillon #26](https://github.com/NazzTazz/waar-micro-combat/pull/26). Les suites Rust, PHP, JS et smoke ont été rejouées après la contre-mesure et passent. B1 est une mesure et son outillage diagnostic, non une optimisation ni une acceptation PO. Relire la PR ; le gain d'une modification du sampler reste à démontrer avec parité exacte. Les fichiers non suivis préexistants du checkout et les scripts locaux sous `reports/issue25-b1/` restent hors de la livraison.
