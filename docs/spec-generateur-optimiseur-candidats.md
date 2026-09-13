# Générateur–optimiseur de candidats de la soufflerie

Actualisation métier : les mesures et le classement de la soufflerie utilisent
désormais `rawCasualtyRatio` = (blessés bruts + morts bruts) / effectif initial,
avant capture et compression. Les mentions de `rawLossRatio` ci-dessous décrivent
l'ancien critère et sont remplacées par cette définition. Les prisonniers restent
une observation séparée. Les objectifs portant l'ancien contexte sont obsolètes.

Spécification proposée — 13 septembre 2026.

Statut : spécification cible avec une première tranche fonctionnelle implémentée.
Les choix algorithmiques et budgets ci-dessous restent des préconisations techniques,
pas des arbitrages gameplay déjà approuvés. Ce document complète la spécification
moteur/soufflerie du 13 septembre 2026 pour la recherche uniquement. Aucun
changement de physique du combat.

### État de la première tranche

Livré dans cette tranche : endpoint séparé `/api/optimize`, 28 dimensions ouvertes,
relations implicites, génération déterministe, quatre générations de huit candidats,
mutations simples et conjointes, croisements, exploration globale, parents issus
des résultats mesurés, élitisme, diversité, adaptation du rayon, filiation,
couverture, historique affiché dans T27 et export JSON du candidat choisi.

Le calcul reste une requête synchrone bornée. Checkpoints, pause/reprise, progression
pendant le calcul, schémas JSON formels et validation indépendante décrits plus bas
restent à livrer. L'historique devient visible à la réception du rapport complet ;
les boutons de génération déplacent alors les vecteurs vers chaque meilleur mesuré.

## 1. Résultat attendu

À partir d'un profil, de paramètres autorisés et de 32 zones confirmées,
l'utilisateur lance une recherche bornée. Les résultats des générations passées
déterminent les candidats suivants. L'interface montre les déplacements réellement
mesurés des vecteurs, les améliorations, les compromis et les plateaux.

La recherche conserve son meilleur candidat, mais ne l'applique ni ne l'approuve.
Elle ne promet ni convergence ni existence d'un profil satisfaisant toutes les
zones. Un échec de recherche n'est jamais une preuve d'incompatibilité des objectifs.

## 2. Diagnostic et périmètre

L'actuel `BoundedProfileSearch` mesure la référence et sept perturbations isolées
aux bornes. Il ne combine pas les paramètres, n'utilise pas les résultats pour
générer la suite et n'utilise pas sa seed de recherche dans la génération.

Le nouvel optimiseur doit :

- explorer les paramètres déjà supportés par `EngineProfile` ;
- produire des descendants de candidats effectivement mesurés ;
- maintenir plusieurs parents, conserver le meilleur et diversifier la recherche ;
- fonctionner par petits lots interruptibles et reprenables ;
- expliquer chaque candidat et permettre le rejeu exact d'une recherche.

Hors périmètre : nouveau moteur, règles de combat supplémentaires, modification
des objectifs par l'algorithme, optimisation des compositions d'armée, intégration
d'un service externe, optimisation bayésienne ou infrastructure distribuée.
Le moteur Rust existant reste le calculateur ; PHP porte génération et classement.
JavaScript ne propose, ne sélectionne et ne classe aucun candidat.

## 3. Invariants

- Profils `waar-engine-profile/0.2`, règles `waar-cohort-v2`, précision fixe et
  sémantique des frappes inchangés ; pas de modification des contrats existants.
- Les références historiques, leurs seeds, ordres, budgets et empreintes restent
  inchangés. L'ancien endpoint de recherche reste compatible et reproductible.
- Les nouvelles recherches ont leur propre version d'algorithme et de contrat.
- Profil de départ, zones, contexte, domaines et budgets sont figés au lancement.
- Les objectifs sont validés contre le profil de départ UNE FOIS. Leurs coordonnées
  et leur provenance ne sont pas réécrites pour correspondre aux descendants.
- Les coûts du profil sont verrouillés. Pour le cas utilisateur : 10/70/70/550 ;
  ce n'est pas un changement des coûts par défaut du moteur.
- Un changement de zones ou de contexte exige une nouvelle recherche explicite.
- Le meilleur trouvé ne doit jamais être remplacé par un résultat moins bien
  classé sur le même jeu d'évaluation. Cela ne garantit pas l'amélioration de
  chaque vecteur, ni de la mesure indépendante de validation.

## 4. Espace de recherche explicite

Chaque dimension possède `path`, `enabled`, `minimum`, `maximum`, `step`.
Les nombres décimaux sont des chaînes, calculées en entiers micro-unités.
Une dimension désactivée conserve exactement sa valeur initiale.

| Dimension par unité | Domaine admissible | Pas proposé | Ouverture proposée |
| --- | --- | --- | --- |
| `attack` | 0 à 1000 | 0,01 | oui |
| `structure` | 0,000001 à 1000 | 0,01 | oui |
| `baseAccuracy` | 0 à 1 | 0,01 | oui |
| `accuracySpread` | 0 à 1 | 0,01 | non, déverrouillable |
| `strikesPerAttack` | entiers 1 à 32 | 1 | non, déverrouillable |
| `defendingEfficiency` | 0 à 10 | 0,01 | oui |
| Relation dirigée hors diagonale | 0 à 10 | 0,01 | oui |

Soit 28 dimensions ouvertes par défaut, jusqu'à 36 en déverrouillant amplitude
et frappes. Coûts, capturabilité, météo, effets acquis, rounds, reddition, départage,
compression et pourcentage de capture restent figés dans cette première version.
Les quatre flèches et cinq frappes du candidat narré restent donc verrouillées.

### Relations absentes

Les 12 relations dirigées sont adressables même si absentes du JSON ; leur valeur
effective est alors 1. Les diagonales restent à 1. L'export peut omettre les facteurs
neutres, mais le vecteur interne a toujours ses 12 entrées. Ne pas modifier le
profil source ou son empreinte pour matérialiser ces valeurs.

### Bornes et granularité

- Les ±20 % deviennent un préréglage « local », pas une restriction de l'outil.
- Un préréglage « élargi » propose : attaque/structure `[v/4 ; 4v]`, coefficient
  défensif/facteur `[0 ; 10]`, précision `[0 ; 1]`. Tronquer au domaine admissible.
- Pour une attaque initialement nulle, le préréglage élargi propose `[0 ; 10]`.
  Le préréglage local signale explicitement une plage nulle ; jamais de blocage caché.
- Aucun préréglage n'est appliqué sans présentation et confirmation des domaines.
- La grille est `minimum + k × step`, dans les bornes ; minimum et maximum doivent
  être admissibles et leur différence divisible par le pas. Arrondir les bornes
  proposées vers l'extérieur, sans dépasser le domaine, puis les présenter.
- La valeur initiale, si hors grille mais dans les bornes, est admise comme point
  supplémentaire exact. Ne jamais arrondir silencieusement le profil de départ.
- Autoriser les pas de 0,000001 à 1 pour les décimaux, multiples de la micro-unité.
  Une grille réduite à un seul point est un verrou effectif, signalé à l'utilisateur.
- Valider tous les profils générés avec les validateurs existants. Les erreurs
  de configuration sont explicites ; elles ne deviennent pas un mauvais score.

## 5. Objectif et comparaison

Conserver la sémantique existante : pour chacune des 32 observations, mesurer
`winRate` et `rawLossRatio`, puis l'excès normalisé hors de son ellipse avec
`AcceptanceZoneEvaluator` et `NormalizedEllipseBoundaryPenalty`.

Score principal : somme des 32 excès, tous de poids 1. Zéro signifie que les
32 observations mesurées sont acceptées par l'évaluateur, tolérance incluse.
Il n'y a ni déplacement automatique des ellipses ni minimisation vers leur centre
une fois à l'intérieur. Les pertes compressées ne remplacent pas les pertes brutes.

Pour la nouvelle version uniquement, ordre total déterministe :

1. somme des excès croissante ;
2. nombre de zones satisfaites décroissant ;
3. pire excès croissant ;
4. empreinte du vecteur effectif, ordre lexical.

Les trois premières composantes définissent une amélioration objective ; le
dernier départage n'est pas affiché comme un progrès. À score principal plus faible,
un candidat peut satisfaire moins de zones : ce compromis doit être visible.

Les contraintes narratives supplémentaires (nombres de touches, tampon, captures,
absence d'anéantissement mutuel) ne deviennent pas des objectifs cachés. Le rapport
les rappelle comme non évaluées tant qu'un contrat d'objectif distinct n'est pas
spécifié et confirmé. Pas de pénalité implicite pour un coefficient « extrême ».

## 6. Algorithme proposé : recherche évolutive à petite population

Identifiant initial : `waar-profile-evolution/1`.

Il s'agit d'une boucle itérative avec filiation, pas d'une récursion de pile.
Chaque génération complète décide ses parents pour la suivante.

### Initialisation

1. Valider et figer le plan ; construire l'ordre canonique des dimensions :
   soldat, lancier, archer, chevalier, puis champs dans l'ordre du tableau ;
   relations dans cet ordre de source puis de cible, diagonales exclues.
2. Évaluer la référence, comptée dans le budget. Une mesure existante peut être
   réutilisée seulement si profil effectif ET contexte complet correspondent.
3. Constituer la première génération avec la référence et au plus sept propositions.
4. Conserver une archive de tous les candidats et un groupe de quatre parents :
   les deux meilleurs, puis deux autres sélectionnés pour leur diversité.

La diversité est la moyenne des distances absolues entre coordonnées normalisées
par leur plage. Choisir successivement le candidat maximisant sa distance minimale
aux parents déjà retenus ; départager par classement puis empreinte. Si moins de
quatre candidats existent, utiliser tous les candidats disponibles. Une même
empreinte ne peut occuper plusieurs places. Archive et parents restent bornés par
le budget total.

### Production d'une génération de huit propositions

Répéter les opérateurs dans cet ordre ; une génération partielle utilise le préfixe :

| Places | Opérateur |
| --- | --- |
| 1–2 | mutation d'une dimension, autour du meilleur parent |
| 3–4 | mutation conjointe de 2 à 4 dimensions, autour des parents suivants |
| 5–6 | croisement de deux parents distincts, puis mutation d'au moins une dimension |
| 7–8 | exploration globale dans les domaines confirmés |

Pour l'initialisation sans plusieurs parents, le croisement devient une mutation
conjointe de la référence. Aucun opérateur ne produit seulement des extrémités.

- Un sac mélangé déterministe des dimensions ouvertes alimente les mutations.
  Le sac est épuisé avant mélange suivant : aucun index fixe ne condamne une
  dimension à ne jamais être proposée. Compter propositions et mutations effectives
  par dimension ; signaler celles non couvertes lorsque le budget est trop petit.
- Mutation locale : tirer uniformément un autre point de grille à une distance
  maximale `max(step, radius × largeur)` du parent, bornes incluses. Si aucun autre
  point local n'existe, prendre le voisin admissible le plus proche.
- Mutation conjointe : tirer uniformément un nombre de dimensions entre 2 et 4,
  limité au nombre ouvert ; appliquer la mutation locale à chacune, sans doublon.
- Croisement : pour chaque dimension ouverte, choisir la valeur de l'un des deux
  parents à probabilité 1/2, puis muter. Le premier parent suit un cycle du groupe,
  le second est tiré parmi les autres. Les dimensions verrouillées ne croisent pas.
- Exploration globale : tirer 2 à 4 dimensions et échantillonner uniformément
  leurs grilles complètes, à partir d'un parent cyclique. Après stagnation, inclure
  une proposition réinitialisant toutes les dimensions ouvertes dans leurs grilles.
- Un changement de frappes ne compense pas implicitement l'attaque : les deux
  paramètres ont leur sémantique actuelle. Ils peuvent être mutés conjointement.

### Adaptation et mémoire

Rayon initial normalisé : 0,25. À chaque génération complète améliorant le meilleur
selon les trois composantes objectives, multiplier le rayon par 0,8, plancher 0,02.
Après trois générations consécutives sans amélioration, doubler le rayon, plafond
1 ; les places 5–8 de la prochaine génération deviennent exploration globale,
dont la dernière réinitialise toutes les dimensions ouvertes. Réinitialiser alors
le compteur de stagnation. Le meilleur et l'archive restent conservés.

Le groupe de parents est recalculé sur l'archive après chaque génération complète,
pas après chaque réponse native : l'ordre de fin des calculs ne doit pas influencer
les générations suivantes. En fin de budget, une génération partielle est finalisée.

Une proposition invalide ou déjà vue est tracée puis remplacée. Maximum 80 essais
de génération pour un lot de huit, proportionnel pour un lot partiel. Si aucun
nouveau candidat n'est obtenu, terminer avec `no_novel_proposals`, sans prétendre
avoir prouvé que tout l'espace est épuisé. Si quelques propositions sont disponibles,
mesurer et finaliser ce lot partiel, en conservant le compteur total de générations.

## 7. Budget explicite et arrêt

Huit candidats est une taille de lot, mais la compatibilité du smoke reste
**huit candidats au total**, référence comprise. Aucun agrandissement silencieux.

| Mode proposé | Candidats uniques maximum | Générations maximum | Validation indépendante |
| --- | ---: | ---: | ---: |
| Smoke / essai court | 8 | 1 | aucune |
| Optimisation interactive, confirmation explicite | 32 | 4 | 4 finalistes maximum |
| Avancé, saisie et confirmation explicites | 256 | 32 | 4 finalistes maximum |

Chaque profil reçoit 16 scénarios, avec le même nombre de répétitions (1 à 100,
100 proposé). Afficher avant lancement : `16 × répétitions × (candidats + finalistes)`.
Au plafond interactif : 57 600 combats, validation incluse ; ce nombre n'est pas
une promesse de durée. Les réutilisations de cache réduisent le travail réel,
pas le plafond de candidats uniques autorisés.

Arrêts : budget, générations, demande utilisateur, erreur runtime, absence de
nouvelles propositions ou 32 zones satisfaites sur l'apprentissage. Ce dernier
cas déclenche la validation prévue, pas une approbation. Si elle échoue, terminer
avec le désaccord visible ; ne pas réinjecter ces résultats dans l'optimisation.

Un délai opérationnel peut demander une pause ; il ne remplace pas les budgets
déterministes et n'influe pas sur la sélection. Une continuation au-delà des
plafonds constitue un nouveau plan explicitement autorisé, lié au précédent.

## 8. Mesure, bruit et reproductibilité

- Batch Rust existant pour les 16 scénarios de chaque candidat ; pas de simulation
  JavaScript et pas de réimplémentation des dégâts dans l'optimiseur.
- Même contexte de mesure pour tous les candidats : budget, météo, effets absents
  dans le périmètre monotype actuel, répétitions, seed et politique de conséquences.
- Seed d'apprentissage proposée : 42 ; seed de validation indépendante proposée :
  271828 ; seed de recherche proposée : 314159. Toutes explicites et immuables.
- Le flux du générateur est séparé des flux du moteur. Spécifier et tester un
  tirage uniforme entier sans biais modulo ; dériver ses sous-flux par SHA-256
  d'une sérialisation canonique versionnée de seed/génération/place/essai/opérateur.
  Les vecteurs de référence du PRNG sont requis avant intégration.
- L'état du sac de dimensions, le rayon, les compteurs et les parents sont sauvegardés.
- Même plan + mêmes versions = mêmes propositions, mesures et classement, hors
  identifiants techniques et horodatages. Pause/reprise doit produire la même suite.
- Identité du candidat : vecteur effectif canonique + paramètres figés, sans label
  ni ID de présentation. Une relation implicite à 1 équivaut à une relation explicite
  à 1. Garder aussi l'empreinte officielle du profil pour provenance et export.
- Cache : identité effective + modèle/build natif + contexte complet + plage de
  seeds/répétitions. Ne jamais mélanger validation et apprentissage ou deux budgets.
- Les finalistes sont les quatre premiers profils distincts du classement
  d'apprentissage, ou moins si indisponibles. Validation distincte, non fusionnée
  avec le score utilisé pour guider la recherche et sans reclassement caché.

Afficher victoires/nombre de répétitions et signaler que 100/100 n'est pas une
garantie probabiliste. Cette version n'introduit pas de tests de significativité
ou de réduction adaptative du nombre de répétitions.

## 9. Exécution incrémentale et sauvegarde

Pas de requête HTTP monolithique pendant toute une optimisation. Proposition
d'API nouvelle, sans changer `/api/search` :

- `POST /api/optimizer/runs` : valide le plan, crée un état initial, ne calcule pas ;
- `POST /api/optimizer/runs/{id}/advance` : au plus un profil mesuré, puis checkpoint ;
- `GET /api/optimizer/runs/{id}` : état et événements depuis un numéro de séquence ;
- `POST /api/optimizer/runs/{id}/pause` : empêche le prochain travail, laisse finir
  au plus la mesure en cours ;
- `POST /api/optimizer/runs/{id}/resume` : reprend le même plan et ses budgets ;
- export du plan, des événements, des profils et des mesures en fichiers JSON.

L'interface demande des avancées successives, mais le serveur choisit seul ce qui
sera calculé. Fermer l'onglet interrompt l'avancement après la mesure en cours ;
recharger retrouve le checkpoint, sans poursuite ni reprise automatique cachée.

États : `ready`, `running`, `paused`, `validating`, `completed`, `failed`.
`stopReason` distinct : budget, générations, objectifs mesurés satisfaits,
absence de propositions nouvelles, annulation explicite ou erreur. Une pause
n'est pas un arrêt définitif. Export et consultation restent possibles après arrêt.

Stockage local dans un nouveau sous-dossier de `reports/optimizer/`, sans base de
données. Écritures atomiques, verrou par run, identifiant généré côté serveur,
chemins non contrôlés par le client. Révision et clé d'idempotence obligatoires
pour `advance` : doubles clics, reconnexions et deux onglets ne doublent ni le
budget ni les observations. En cas de crash avant checkpoint, rejouer au plus
la même évaluation déterministe, sans créer un second candidat.

Le runner CLI réutilise la même machine à états. Runtime défaillant = erreur
explicite, jamais score artificiellement mauvais ou bascule PHP silencieuse.
Un run ne reprend pas sous un autre build ou contrat : diagnostic et nouveau plan.

## 10. Contrats minimaux à livrer

Nouveaux schémas, sans réécriture des anciens :

- `waar-optimizer-plan/1` : profil et empreinte source, zones et empreinte,
  contexte, domaines, graines, algorithme/version, budgets, configuration validation ;
- `waar-optimizer-state/1` : planHash, révision, état, génération/place,
  parents, meilleur, archive, rayon, sac et compteurs, évaluations en attente ;
- `waar-optimizer-event/1` : séquence, type, candidateId, génération, parentIds,
  opérateur, mutations avant/après, métriques et compteurs pertinents ;
- `waar-optimizer-report/1` : plan, versions effectives, candidats/profils,
  observations apprentissage/validation séparées, historique, couverture des
  dimensions, cache, erreurs et raison d'arrêt, `selectionPerformed: false`.

La lignée d'un candidat et les valeurs réellement modifiées doivent être
consultables. Un rapport ne contenant que le meilleur profil n'est pas suffisant.

## 11. Expérience utilisateur

Avant lancement : tableau des dimensions ouvertes/verrouillées, domaines et pas,
profil de départ, 32 zones, seeds, mode et plafond de calcul. Coûts bien visibles.
Le bouton annonce « Optimiser — jusqu'à 32 candidats » après choix explicite,
pas « résoudre » ou « équilibrer automatiquement ».

Pendant le run :

- conserver la référence et les ellipses immobiles ; afficher les 32 vecteurs du
  meilleur candidat courant, d'une seule génération cohérente à la fois ;
- publier un meilleur provisoire après chaque mesure, mais ne renouveler les
  parents qu'à la barrière de génération ;
- proposer une trace discrète des meilleurs précédents et un curseur d'historique ;
- afficher génération, évaluations/plafond, meilleur score, zones satisfaites,
  pire écart, paramètres modifiés, stagnation et couverture des dimensions ;
- distinguer clairement candidat en cours, meilleur trouvé, finaliste validé
  sur une autre seed et profil effectivement chargé dans l'éditeur ;
- ne jamais interpoler des résultats fictifs ou déplacer un point non remesuré.
  Les transitions visuelles éventuelles n'ont aucune valeur de mesure ;
- proposer pause/reprise et export. Une réponse tardive d'un autre plan ne peut
  écraser les résultats du plan courant.

Après arrêt : explication lisible, comparaison référence/meilleur, validation
indépendante, limites et bouton « Charger ce candidat pour essai » nécessitant
une action explicite. Charger un profil invalide les mesures de l'éditeur ; les
zones ne sont réassociées qu'après consentement, comme aujourd'hui. L'historique
du run reste intact et ne constitue pas une approbation gameplay.

## 12. Découpage d'implémentation

Une évolution cohérente et une démonstration de bout en bout ; pas de refonte
du moteur ou de l'interface générale. Responsabilités séparées et testables :

1. plan/domaines/canonicalisation, générateur pur et lignée ;
2. score existant, archive/parents, adaptation et budgets ;
3. évaluateur monotype existant, cache et validation indépendante ;
4. machine à états/checkpoints/API/CLI ;
5. progression dans T27, historique et export ;
6. recette reproductible ci-dessous, livrée avec les fonctionnalités.

Ne pas laisser un générateur « temporaire » sans filiation servir de recherche
livrée. Un lot initial puis une vraie seconde génération doivent être démontrés.

## 13. Recette obligatoire

### Génération et contrats

- Relations implicites accessibles, diagonales et verrous préservés ; coûts exacts.
- Grilles décimales : zéro, 0,01, 0,333333, bornes physiques, entiers et domaines
  unitaires ; profil source non arrondi ; zéro peut évoluer si son domaine l'autorise.
- Une génération comporte des mutations conjointes et des points intérieurs.
- Toutes les dimensions sont proposées lorsque le budget couvre un cycle du sac ;
  budget insuffisant signalé, pas de fausse promesse de couverture en huit candidats.
- Des scores différents conduisent à des parents puis descendants différents.
- Même seed : même suite ; plusieurs seeds de test : diversité réellement observée.
- Cache, équivalence des relations neutres, déduplication et plafonds stricts.

### Optimisation

- Sur une fonction synthétique déterministe à optimum connu, deux paramètres
  doivent évoluer conjointement ; la deuxième génération descend des parents
  sélectionnés, et le meilleur final améliore strictement la référence.
- Meilleur monotone selon le classement, conservation de l'élite lors des relances,
  diversification après stagnation, terminaison avec domaines fermés.
- Une fonction synthétique impossible ne doit jamais produire « incompatibilité
  prouvée » ; seulement la raison d'arrêt et le meilleur trouvé.
- Ces tests utilisent un évaluateur factice, pas des objectifs historiques modifiés.

### Intégration native

- Petit corpus non figé, versionné avec le nouveau composant : à partir d'un
  profil témoin cible, générer ses zones, puis partir d'un profil perturbé. Les
  zones du test sont liées au profil de départ avec provenance du témoin explicite.
- Pour une seed et un budget documentés, obtenir au moins une amélioration stricte
  après la génération initiale, sans modifier les zones. Exporter l'intégralité
  du plan et du parcours, pas seulement une capture d'écran favorable.
- Ne pas exiger la réussite universelle du profil narré : ses contraintes et
  ses compromis doivent être mesurés, pas transformés en résultat garanti.
- L'évaluation directe d'un candidat et celle de l'optimiseur sont identiques.
- Validation indépendante séparée, budget compté, échec visible sans fuite dans
  la boucle d'apprentissage.

### Interface et reprise

- Démonstration réelle de deux générations : vecteurs issus des rapports natifs,
  historique consultable, amélioration et plateau lisibles ; T27 drag/Annuler conservés.
- Pause en cours, rechargement, reprise, double avance concurrente, crash et
  réponse tardive : aucun doublon, dépassement de budget ou résultat d'un autre plan.
- Chargement de candidat explicite ; aucun objectif ni profil actif modifié à
  l'insu de l'utilisateur ; tous les fichiers exportés relisibles.

### Non-régression

Exécuter les vérifications AGENTS.md : Composer strict/install/test, JS, smoke
toujours borné à huit candidats, et rendu des références si dépendance affectée.
Tests PHP/Rust et parité requis pour tout changement de frontière native ; aucun
changement de gameplay ne doit être nécessaire. Vérifier les empreintes gelées.
Documenter durée réelle, nombre de combats, appels natifs, mémoire et limites.

## 14. Définition de terminé

L'utilisateur lance un plan borné, voit une deuxième génération issue des
résultats de la première, observe ses vecteurs mesurés, peut interrompre/reprendre
et exporter un meilleur candidat traçable. Les bornes, paramètres explorés et
budgets sont honnêtes ; le moteur, les objectifs et les références restent intacts.

« Huit profils calculés, tests verts » n'est plus un critère de livraison suffisant.
