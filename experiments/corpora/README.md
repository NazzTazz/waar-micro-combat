# Corpus d'armées-types Waar

## 21 profils humains + 4 fulls

[human-snapshot-21-plus-4-v1.json](human-snapshot-21-plus-4-v1.json) contient les 21 armées humaines non vides présentes dans la copie locale de production, extraites le 8 septembre 2026, et quatre profils mono-unité synthétiques.

La date exacte de la copie n'est pas vérifiée ; « le mois dernier » vient du PO. Il s'agit des armées détenues dans cet instantané, pas d'un historique des compositions engagées ni d'une sélection des seuls joueurs actifs.

### Extraction

Connexion obtenue depuis la configuration dev de `../waar-sf/`, limitée à une cible locale. Requête exécutée dans une transaction en lecture seule, avec délai maximal par requête. Aucun identifiant de joueur, pseudonyme, date de connexion, mot de passe ou autre donnée personnelle n'est exporté.

```sql
SELECT
    a.nb_soldats AS soldier,
    a.nb_lanciers AS spearman,
    a.nb_archers AS archer,
    a.nb_chevaliers AS knight
FROM joueur j
JOIN armee a ON a.id = j.armee_id
WHERE j.is_bot = 0
  AND a.nb_soldats + a.nb_lanciers + a.nb_archers + a.nb_chevaliers > 0
ORDER BY
    a.nb_soldats + a.nb_lanciers + a.nb_archers + a.nb_chevaliers,
    a.nb_soldats, a.nb_lanciers, a.nb_archers, a.nb_chevaliers;
```

Ne pas lire toute la table `armee` comme une population d'armées : elle contient également les pertes des rapports et les troupes d'infirmerie. Les 9 bots non vides sont exclus à la demande du PO. La base n'a pas été modifiée.

### Lecture du format

- Ordre des types : Soldats / Lanciers / Archers / Chevaliers.
- `human-01` à `human-21` : compositions exactes de l'instantané, triées par effectif total puis composition. Ces identifiants ne sont pas des identifiants de joueurs et ne permettent pas de suivre un joueur entre deux extractions.
- `composition` contient des poids entiers : pour les profils humains, ce sont les effectifs observés conservés tels quels. `observedTotal` est leur somme.
- `full-soldier`, `full-spearman`, `full-archer`, `full-knight` : poids 1 pour le type concerné et 0 pour les autres. Ce sont des proportions de 100 %, pas une consigne de tester une armée d'une seule unité. `observedTotal` vaut `null`.
- Les 21 profils sont tous conservés. Trois sont déjà mono-Soldat, à des tailles différentes ; aucun n'est fusionné avec le full synthétique.
- Les tailles observées vont de 14 à 85 027 unités. Les zéros et les effectifs irréguliers sont conservés, sans rendre artificiellement les compositions plus « jolies ».

Ce fichier décrit des **profils**, pas une expérience directement exécutable par `run-wind-tunnel.php`. Aucune taille cible ni mise à budget égal n'est choisie implicitement. Le futur évaluateur devra annoncer sa politique de taille/budget et ses arrondis, tout en conservant la composition source.

### Relais pour Sol : convention A/B du PO

La précision du PO postérieure à la première spec de calque est : **un segment représente une armée-type ; A représente sa performance en attaque et B sa performance en défense**. Ne pas interpréter ce nouveau segment comme témoin → candidat.

Le corpus ci-dessus fournit 25 profils pour ces segments. Les adversaires, leurs poids et la politique de taille/budget doivent être définis explicitement et appliqués de la même manière aux moteurs comparés. Les profils Legacy et micro pourront être superposés ; leurs zones A/B portent sur les rôles attaque/défense.

La première [spec de calque](../../docs/waar-micro-combat-acceptance-overlay-spec.md) précède ce changement de convention : ses mentions base = témoin et pointe = candidat ne doivent pas guider le branchement de ce nouveau corpus. L'adaptation de l'évaluateur et de la spec n'est pas réalisée par cette simple extraction.

Les compositions observées servent à l'exploration de gameplay locale. Ne pas les brancher comme fixtures dans la base Legacy ni lancer des tests applicatifs contre la copie de production. Aucun moteur, résultat T23/T24 ou ruleset n'est changé par cette livraison.
