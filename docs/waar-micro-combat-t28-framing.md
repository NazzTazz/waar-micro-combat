# Cadrage T28 — 9 septembre 2026

## Règle de départage confirmée par le PO

À la question « une attaque qui ne réussit pas à gagner est une victoire défensive ? », le PO répond : « tout à fait exact ».

- Une égalité exacte au terme du combat donne la victoire au défenseur.
- Une extinction mutuelle donne également la victoire au défenseur : l'attaque a échoué.
- Ce départage attribue uniquement le vainqueur. Il ne change ni les dégâts, ni les pertes, ni les survivants ; aucune unité n'est recréée.
- Les victoires déjà déterminées par les règles de résolution restent inchangées. Cette règle ne signifie pas qu'atteindre la limite de rounds suffit à faire gagner le défenseur : un attaquant vainqueur selon le critère existant conserve sa victoire.
- Conserver une raison explicite pour identifier les victoires défensives obtenues par départage. Tester séparément égalité à la limite et extinction mutuelle, ainsi que la conservation des pertes et des victoires hors égalité.
- Versionner le comportement du moteur et produire de nouveaux artefacts ; ne pas réécrire les rapports historiques. Les 32 objectifs humains restent inchangés.

Cette décision lève le point de gameplay laissé ouvert dans les relais T26/T27. Aucun changement de moteur effectué par Astra lors de sa consignation.

## Proposition technique présentée pour la recherche

Les points suivants ont été proposés par Astra dans le même échange ; la réponse explicite du PO portait sur la question de départage ci-dessus.

- Explorer attaque, structure et efficacité défensive des quatre unités, ainsi que les facteurs des trois contres existants. Première plage proposée : de 0,5 à 2 fois chaque valeur actuelle du candidat, élargissable explicitement ensuite.
- Coûts, compositions, nombre de rounds, dispersion aléatoire et paramètres du témoin fixes. La nouvelle règle de départage doit être identifiée dans les variantes utilisées pour la recherche et la comparaison.
- Même poids pour les 32 objectifs canoniques, sans contribution économique ou structure supplémentaire. Normaliser X et Y par les rayons de chaque ellipse ; pénalité nulle à l'intérieur et croissante à l'extérieur, sans attraction vers le centre une fois la zone atteinte.
- Publier le nombre d'objectifs atteints et le pire écart en plus de la distance agrégée. Une bonne moyenne ne valide pas les 32 contraintes strictes.
- La formule exacte d'agrégation et le traitement numérique de la frontière restent à expliciter dans le contrat de recherche ; ils ne sont pas spécifiés par la seule approbation de la règle de départage.

Entrée : `var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json`. Contrat des objectifs : `docs/waar-micro-combat-objective-contract-correction.md`. Prévol accepté : `docs/waar-micro-combat-t27b-astra-review.md`.
