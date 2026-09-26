# Campagne cœur optimisée — préparation du 26 septembre 2026

Cette nouvelle campagne utilise le protocole Rust `sha256-splitmix-occupancy/1`.
Chaque duel donne exactement le même preset météo aux camps A et B. Les effets
des presets restent ceux du profil de référence ; aucun axe ne les règle. Le
maximum de rounds est 20. Le seuil de blessure et sa conséquence sur les
prisonniers restent dans le modèle actuel.

Les 79 plans sont dérivés des 159 plans historiques par `generate.py`. Les 80
plans `W-factor-*` sont retirés, les scénarios météo à un seul camp sont retirés,
et la valeur 30 est retirée de l'axe `combat.maxRounds`. L'inventaire conserve
les SHA-256 des plans sources et produits. `php preview.php` vérifie les plans
et donne **5 636 expériences, 11 272 directions, 22 544 000 combats prévus**,
avant déduplication des témoins entre plans. Aucun résultat historique ne peut
être repris : protocole, plans, image et dossier de résultats sont distincts.

La préparation locale a exécuté un seul lot D1 de 200 combats avec la version
optimisée ; elle ne constitue pas une qualification physique de la campagne.
La campagne complète a tourné sur le VPS de Strasbourg avec l'image dédiée
`ops/campaign/Dockerfile` et le lanceur `ops/campaign/run-optimized-vps.sh`.

**Exécution du 26 septembre, 15:58–18:23 UTC :** image
`sha256:a580e426c9fc2c10f5b8c9b722d92336e441f9052bfce43c45ea132ee0a16803`
construite sur le VPS ; précontrôle des 79 plans réussi ; deux shards,
chacun à 1 vCPU / 600 Mio. Le lot témoin D1 de 200 combats a été repris sans
doublon. Les deux shards ont terminé leurs 40 et 39 plans. Audit final par
`ops/campaign/audit-optimized-vps.py` : **22 544 000 combats, 79 manifestes
complets, 79 exports, aucune erreur**, même SHA binaire
`6474fe3428ba698f53fdf90ba26471f62385978c749b24daf599fbd3aecd0f1b`
et même SHA de diff suivi
`ba691ba042bcedd9a61a36f5969026bc95859dccdc7e47f24e6bce35673baf2f`.
Les exports, manifestes et logs restent sur le VPS sous
`/home/debian/waar-campaign-optimized`. Les conteneurs de calcul ont quitté
l'hôte et l'image dédiée a été supprimée après audit ; les services web
sont restés actifs.

Sur le VPS, le checkout revu est placé sous
`/home/debian/waar-campaign-optimized/context`. Depuis ce dossier :

```sh
sudo docker build -f ops/campaign/Dockerfile -t waar-campaign:optimized-2026-09-26 .
sudo docker image inspect --format '{{.Id}}' waar-campaign:optimized-2026-09-26
```

Fournir cet ID `sha256:…` au lanceur, d'abord avec `--check-only` pour les
shards `0` et `1`, puis sans cette option. Les deux shards peuvent tourner
concurremment seulement après vérification des quotas du VPS. Le lanceur
refuse de concurrencer un ancien worker de campagne actif. Ses écritures
restent sous `/home/debian/waar-campaign-optimized/results` et `logs`.
