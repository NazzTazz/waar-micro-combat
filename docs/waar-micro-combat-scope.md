# Waar — micro-moteur et soufflerie de gameplay

8 septembre 2026. Cadrage issu des précisions de Tristan après T17. Ce document distingue les exigences exprimées des propositions techniques ; il ne livre ni n'active un nouveau moteur.

Voir les [frontières de projet](combat-project-boundaries.md) : Arbestra est un jeu indépendant et n'hérite pas des règles de Waar.

**Précision PO après T24 :** l'interface principale de la soufflerie devient un calque Legacy et des zones d'acceptabilité pour les extrémités des vecteurs, plutôt qu'un tableau de coefficients à régler. Les paramètres restent un détail expert. La [spec de livraison pour Sol](waar-micro-combat-acceptance-overlay-spec.md) définit le rendu professionnel, les conventions de comparaison et deux livraisons bornées : calque lisible, puis édition/sauvegarde des zones. Le solveur inverse reste une tranche ultérieure ; cette direction ne rétablit pas la fidélité au Legacy comme objectif.

## Direction retenue

**Précision PO après essai de T25B (8 septembre 2026) :** l'équilibrage comporte deux passes distinctes.

1. **Équilibrer et caractériser les quatre monotypes à coût égal.** Des armées composées d'un seul type d'unité s'affrontent avec des budgets égaux. Le PO exprime ses objectifs sur ces confrontations ; la recherche de candidats porte sur ces objectifs. L'égalité de coût ne signifie pas que toutes les confrontations doivent aboutir à 50 % de victoires : les rôles et contres restent à caractériser.
2. **Confronter les candidats au corpus T24 en visualisation seule.** Rejouer les compositions T24 avec les candidats issus de la première passe pour observer leurs effets, notamment dans les armées mixtes. Conserver les compositions et budgets T24, même inégaux ; ces observations ne sont ni des contraintes ni un score d'optimisation pour le solveur. Les résultats des deux camps restent distincts.

Cette précision remplace l'interprétation précédente de l'assistant qui excluait les monotypes du graphe d'équilibrage et demandait de rééquilibrer T24. Les artefacts historiques T24/T25 restent conservés ; les nouvelles évaluations des candidats sont produites séparément.

`waar-v3` est une expérimentation personnelle de modernisation. La contribution destinée à l'équipe Waar porte exclusivement sur le combat et son intégration dans `../waar-sf/`, sans migration générale de stack. Le moteur 3.1 poursuit une trajectoire distincte pour Arbestra ; son évolution n'a plus pour condition de reproduire les résultats ou les contraintes de Waar.

Cette direction remplace, pour la cible Waar, la proposition de bibliothèque unique du document `combat-engine-v3.1-waar-v2-integration-kit.md`. Le corpus T17 et `combat-v31-gameplay-research.md` restent des matériaux de recherche, pas un engagement à utiliser 3.1 pour le micro-moteur.

## Exigences exprimées

Précision PO du 9 septembre 2026 : Waar n'accepte aucun match nul. Les objectifs monotypes ont donc des taux de victoire complémentaires entre camps. Le départage T28 attribue au défenseur une égalité exacte ou une extinction mutuelle, car l'attaque a échoué ; il ne modifie pas les pertes. Les manifestes historiques conservent explicitement leur politique de nul, tandis que les nouvelles variantes de recherche déclarent `tieBreakPolicy = defender`. La [liaison des objectifs](waar-micro-combat-linked-targets.md) n'altère pas les observations historiques du prototype.

- Quatre unités : Soldat, Lancier, Archer, Chevalier.
- Caractéristiques explicites : attaque, défense (envisagée comme structure) et coût.
- Contres exploitables pour les choix de composition.
- Armées de moins de 500 000 unités au total ; cette échelle doit être couverte par les mesures de performance, sans inventer un rejet à 500 000 exactement.
- Intégration compatible avec la stack Legacy ; une réorganisation locale du flux combat est acceptable.
- Soufflerie destinée aux administrateurs : réglage des paramètres et graphe réactif avec X = taux de victoire, Y = grandeur restante sélectionnable, présentation soignée. Tristan confirme le choix de la grandeur Y dans l'interface.
- Contributions limitées au combat. Pas de chantier général économie, bâtiments, carte, frontend ou framework.

Intentions des unités conservées : Soldat tampon rentable au début ; Lancier intéressant en défense et médiocre en attaque ; Archer offensif et très faible en défense ; Chevalier supérieur individuellement et coûteux. Aucun contre précis entre deux types n'est encore fixé.

## Proposition de moteur minimal

Bibliothèque PHP pure compatible avec PHP 8.2, sans dépendance Symfony/Doctrine. Le dépôt Legacy déclare PHP `^8.2` et Symfony 5.4 ; la version effectivement déployée reste à confirmer à l'intégration.

Entrée : effectifs par type et camp, règles versionnées, valeurs effectives préparées par le jeu, graine si la résolution est aléatoire. Sortie : vainqueur et raison, effectifs restants et pertes par type, données nécessaires au rapport. La décision du vainqueur et les pertes sont produites ensemble. Le moteur ne lit ni ne modifie la base de données.

Le coût sert aux budgets et aux indicateurs économiques. Son éventuelle utilisation dans une règle de victoire serait une décision de gameplay distincte, pas une conséquence automatique de sa présence.

Pour les contres, commencer par une matrice dirigée 4 × 4 de multiplicateurs de dégâts, neutres à 1. La formule d'application et les valeurs seront proposées puis testées. Aucun système de plugins ni catalogue extensible arbitraire n'est nécessaire à ce périmètre.

### Défense et résistance : distinction à décider

Si la défense est la structure individuelle, elle protège une unité dans les deux camps. Avec des échanges symétriques, attaque + structure + matrice de contres ne suffisent pas à exprimer à eux seuls une spécialisation liée au rôle attaquant/défenseur.

Recommandation : nommer la résistance `structure` et conserver un facteur d'efficacité en défense par type. Le Lancier peut alors être peu offensif et efficace lorsqu'il défend ; l'Archer peut avoir le profil inverse. Cette recommandation reprend un levier existant de 3.1, sans imposer le reste de son modèle. Autre possibilité à arbitrer : attaque/défense comme puissances propres aux deux rôles, avec une règle de pertes distincte de ces puissances.

Restent à spécifier avant implémentation : échanges et éventuels rounds, règle de victoire, source et amplitude de l'aléatoire, pertes entières et traitement des dommages partiels. Ne pas reprendre implicitement blessures persistantes, salves, ciblage configurable, reddition ou ensemble du contrat 3.1.

## Intégration Legacy proposée

```text
Flux d'attaque Waar
  → préparation des effectifs et bonus
  → micro-résolveur
  → traduction explicite des pertes pour Waar
  → application des conséquences et rapport
```

Le point d'entrée observé est `CombatService::attaquer()`. La plomberie remplace à la fois le calcul des pertes et la comparaison historique qui désigne le vainqueur. Elle réemploie les services hôtes pour météo, moral, butin, prisonniers, infirmerie, quotas et statistiques.

Le travail livré comprend les mappings, les tests d'intégration et l'application unique des conséquences dans le flux touché. Une petite interface d'appel ne dispense pas de vérifier les transactions et doubles soumissions. Aucune refonte générale des collaborateurs n'est présumée nécessaire.

Les attaques horaires supplémentaires liées aux chevaliers et les défenses liées aux lanciers restent des règles du jeu hôte. Elles ne deviennent pas des rounds internes du micro-moteur.

La projection vers l'infirmerie et le plafond de pertes précédemment discuté pour Waar doivent être explicités avant activation ; aucune politique ne doit être importée silencieusement depuis un prototype. Une structure partiellement endommagée ne définit pas à elle seule un soldat hospitalisé.

## Soufflerie : expérience visée

L'administrateur choisit ses compositions, règle les unités et les contres, puis voit immédiatement l'évolution des estimations. Une référence figée et un candidat peuvent être comparés par points et flèches, avec valeurs exactes au survol et tableau associé. Le graphe doit rester lisible lorsque les points se superposent ou que l'incertitude est forte.

Proposition : un point par scénario et camp explicitement choisi. X représente les victoires de ce camp divisées par tous les combats exécutés, nuls compris dans le dénominateur. Y représente la masse finale moyenne de ce même camp sur tous les combats, et non seulement ses victoires. Chaque estimation indique son nombre d'itérations ; un taux sur un scénario fixe suppose une source d'aléatoire définie.

**Axe Y sélectionnable, demandé par Tristan.** Première sélection proposée :

| Grandeur | Numérateur final | Dénominateur si affichage en pourcentage |
|---|---|---|
| Effectifs survivants | Nombre d'unités survivantes | Effectif initial du même camp |
| Structure restante | Structure totale effectivement restante | Structure totale initiale du même camp |
| Valeur économique restante | Somme des survivants par type × coût unitaire déclaré | Valeur économique initiale du même camp |

Proposition d'affichage par défaut : pourcentage de la grandeur initiale ; les valeurs absolues restent disponibles dans le détail. L'axe et l'infobulle portent le nom de la grandeur et son unité. Une unité endommagée conserve sa valeur nominale dans l'indicateur économique ci-dessus ; une éventuelle valorisation proportionnelle aux dommages constituerait un indicateur distinct. Un dénominateur nul produit une mesure non applicable.

Le résultat d'expérience conserve les agrégats nécessaires aux trois mesures. Changer l'axe Y est une opération de présentation et ne relance pas les combats. Les règles de calcul sont définies et testées côté moteur/évaluateur ; le frontend affiche les métriques correspondantes et recalcule seulement les coordonnées du graphe.

L'aperçu progressif est une proposition technique : petit batch pour le premier retour, puis raffinement tant que les paramètres restent stables ; annulation/remplacement du travail obsolète et aucun résultat ancien présenté comme celui des nouveaux paramètres. Les intervalles d'incertitude doivent distinguer un mouvement estimé du bruit de simulation.

Le calcul de référence reste celui du micro-moteur. Commencer par mesurer si un batch PHP derrière un endpoint de soufflerie répond au besoin. Ce point d'accès est justifié par le calcul interactif ; il n'impose pas d'API générale au jeu. Réutiliser l'interface de la soufflerie existante est envisageable, mais pas son moteur 3.1 comme substitut non équivalent. Un accélérateur navigateur ou natif ne serait retenu qu'après mesure, avec preuve de parité.

Objectifs de latence chiffrés à fixer sur une machine cible. Mesurer premier affichage, raffinement et annulation à différentes tailles jusqu'à 500 000 unités au total. Le coût d'un duel et celui de centaines de répétitions doivent être publiés séparément. Éviter une représentation avec un objet par unité ; retenir des calculs agrégés dont les approximations éventuelles sont explicites et validées.

## Livraison proposée

1. Fermer le petit contrat de calcul : sens de la défense, contres, victoire, pertes et aléatoire ; préciser les formules des métriques proposées pour l'axe Y sélectionnable. Fournir des exemples calculables à la main.
2. Livrer un résolveur PHP et un quadruplet explicable, testés sur les rôles, les contres, les petits effectifs et les grands totaux. Adapter le corpus T17 plutôt que relancer l'imitation Legacy.
3. Livrer la boucle interactive de la soufflerie et mesurer sa latence réelle. Les modifications des administrateurs restent expérimentales tant qu'elles ne sont pas publiées explicitement.
4. Livrer l'intégration combat Legacy et ses preuves sur un environnement représentatif, puis une activation distincte et versionnée.

Le choix du dépôt GitHub et l'automatisation Sol/revue sont des questions de livraison séparées. Ils ne doivent pas obliger les deux moteurs à partager leurs règles ou leur calendrier.
