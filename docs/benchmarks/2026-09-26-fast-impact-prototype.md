# Prototype de répartition probabiliste des impacts — 26 septembre 2026

**Statut : expérimental, non sélectionné par la soufflerie.** Branche `experiment/fast-impact-physics`, issue du HEAD B1 `2beec7e`. Aucun objectif, artefact de référence ni ancien replay n'a été modifié.

## Contrôle d'histoire et choix

Attentes ATT-04/07/11/14 ; [registre](../product-expectations.md), [mesure B1](2026-09-26-b1/README.md), [issue #24 et commentaire de Darthmoule](https://github.com/NazzTazz/waar-micro-combat/issues/24#issuecomment-5838172612), [spécification des cohortes](../spec-moteur-cohortes-soufflerie-2026-09-13.md), code et tests `resolve_action`, `apply_impacts`, `AddressedRandom`. Le chemin actuel tire une répartition entre cohortes avec un arbre de binomiales adressées, puis répartit les impacts d'une cohorte par quotient/reste. B1 mesure 13 272 appels de ce sampler et 70,7–71,1 % de `resolve_fast` sur le mélange Nazz de dix combats. Le quotient/reste donne 100 blessés et aucun mort pour 120 impacts de 10 dégâts sur 100 unités de 30 PV, alors que des cibles individuelles indépendantes donnent en espérance environ 30 intacts, 58 blessés et 12 morts.

L'[implémentation XG-Proyect](https://github.com/XGProyect/XGProyect/tree/master/legacy/app/Libraries/BattleEngine) agrège le feu, les dégâts et des explosions estimées à partir de la vie moyenne. L'idée utile ici est de calculer sur des effectifs agrégés. Le prototype calcule une distribution du **nombre d'impacts par individu**, puis l'effectif de chaque catégorie ; il conserve les intacts, blessés et morts sans tableau de tous les individus. Aucune règle ni portion de code XG n'est reprise. La préférence explicite du PO est un moteur réaliste et rapide ; il ne demande pas de parité des anciens replays avant stabilisation de la physique.

## Implémentation et résultat

La feature Rust `fast-impact` accepte le protocole expérimental `sha256-splitmix-occupancy/1`. Chaque action démarre un flux SplitMix depuis le domaine adressé, tire les cibles et touches en agrégat, puis conditionne l'histogramme sur le nombre exact d'impacts. Le surplus de dégâts reste du surdommage ; la vague ne redistribue pas les coups d'une cible morte. L'ancien protocole `sha256-binomial-tree/1` conserve son calcul. La soufflerie PHP continue de demander l'ancien protocole.

Même corpus JSONL et cinq répétitions par cellule que B1, binaire Rust release en processus persistant, PC Windows du PO. Médianes pour cinq itérations par sens :

| Charge | B1 | Prototype | Débit prototype |
| --- | ---: | ---: | ---: |
| Nazz, mélange 120 000, 10 combats | 708,21 ms | 26,96 ms | 371 combats/s |
| Test 2, matrice des 16 monotypes, 80 combats | 1 162,81 ms | 84,16 ms | 951 combats/s |
| Nazz, mélange 800 000, 10 combats | > 2 000 ms, censuré | 46,48 ms | 215 combats/s |

Mesures locales : `reports/issue25-b1/fast-prototype-bulk.json` (ignoré par Git) ; le fichier `fast-prototype.json` précède la correction par catégories et est supersédé. Les binaires et résultats sont reproductibles pour une même entrée/seed. Le test de 1 000 seeds du cas 120 sur 100 vérifie les effectifs moyens attendus et, à chaque tirage, les 100 unités et 120 impacts. Les suites Rust avec et sans feature passent ; le CLI feature renvoie son nouvel identifiant, et le CLI normal refuse ce protocole.

## Limites et suite

Le tirage binomial des grands effectifs utilise une approximation normale. Le conditionnement de l'histogramme est également approché. Le cas discriminant et le débit sont vérifiés ; la loi complète des pertes sur plusieurs rounds et les effets des autres règles ne sont pas encore comparés à un oracle individuel. Aucune interaction de la soufflerie n'emploie ce moteur aujourd'hui ; il n'y a donc ni démonstration du geste utilisateur ni acceptation PO. Comparer ces distributions et les résultats de campagne, puis exposer un choix de protocole dans l'orchestration PHP et faire la recette du vrai geste avant toute sélection par défaut.
