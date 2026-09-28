# Bagaar — SDK Lua et populations

Bagaar fait tourner les politiques Lua dans un processus séparé. Chaque compte a son environnement, sa mémoire et ses paramètres. PHP valide les actions, leurs coûts et leurs portées ; Rust résout les combats. Le tutoriel exécutable `public/workshop/bagaar-tutoriel.lua` décrit le SDK avec des exemples français.

## Scripts et bibliothèque

La zone **Profils simulés** compose librement de 2 à 32 joueurs de départ à partir des six profils natifs ou de versions Lua publiées. Hacker et Balise ne sont pas des choix natifs. Une population sauvegardée est partagée par tous les visiteurs. Elle contient les comptes et le vivier additionnel.

Les arrivants sont tirés au prorata des comptes initiaux (poids 1 chacun) et des poids du vivier. Le tirage dépend du seed. Les modèles ne sont pas consommés ; chaque arrivant reçoit un compte et une mémoire neufs. Au-delà de 64 comptes Lua actifs, Bagaar choisit un modèle natif. Les abandonnés restent sur le plan comme cibles.

L'éditeur permet de vérifier et tester un brouillon dans une ère sans le publier. La publication exige cet en-tête dans les vingt premières lignes :

```lua
-- @profile Le Comptable
-- @author Astra
-- @version 1.2.0
```

Les versions publiées sont immuables et partagées sur le serveur. L'auteur est déclaratif ; les droits d'écriture seront traités plus tard. Le source exact et son SHA-256 sont conservés avec l'ère. Modifier une population, un preset ou un script ne modifie pas une ère déjà lancée.

## Paramètres et mémoire

`parameters()` retourne jusqu'à seize descriptions de contrôles. Bagaar l'exécute dans le bac à sable sans appeler `next` ou `after_combat` et sans état de jeu. Le code d'initialisation du script s'exécute nécessairement pour définir cette fonction.

```lua
function parameters()
  return {
    {name="AGGRESSIVITY", type=ParamType.slider, min=0, max=100,
     step=5, default=50, description="Prise de risque"},
    {name="RETALIATE", type=ParamType.toggle, default=true,
     description="Répliquer après une attaque"}
  }
end
```

Chaque joueur lit ses propres valeurs dans `observation.parameters`. Elles restent fixes pendant l'ère. Le script écrit ses apprentissages dans `observation.memory`, une table JSON de 128 Kio maximum par compte. Elle survit au reset et à la reprise de calcul ; une nouvelle ère commence avec une mémoire vide. Les globales Lua ne sont pas durables.

## Décisions et informations

`next(observation)` renvoie une action ou `nil` et est rappelé après chaque action. `after_combat(observation)` est facultatif et suit les mêmes quotas. `goal` et `method` apparaissent sur la fiche du joueur. Le quota d'attaque vient de Waar : une armée de chevaliers peut atteindre 24 attaques par tick.

L'observation comprend `tick`, `totalTicks`, `self`, `targets`, `ranking`, `rwaa`, `reports`, `events`, `attempts`, `spyResults`, `costs`, `parameters` et `memory`. Les autres joueurs exposent leur nom, Glwaare, rang, statut Rwaa et dernière présence simulée. Leur armée, Or et moral ne sont accessibles qu'après espionnage. Le ruleset caché n'est jamais communiqué.

Un espionnage individuel est `{type="spy", target="axel"}`. Un groupe est `{type="spy", targets={"axel", "bruno"}}`. Bagaar déduplique les identifiants dans le groupe, valide et facture chaque cible séparément et donne un résultat par cible dans `spyResults` et `attempts`. Les rapports réussis sont visibles au prochain `next`. Le plafond est de 64 tentatives de ciblage par compte et par tick, callbacks inclus.

Les autres actions sont `mine`, `hospital`, `recruit` avec `units`, `heal`, `attack` avec `target`, `surrender`, `autoSurrender` avec `enabled`, `reset` et `abandon`. Un compte Lua ne quitte le jeu que si son script le demande. Le reset suit la règle de Waar et conserve la mémoire Lua.

La source est bornée à 16 Kio. `table` expose `concat`, `insert`, `move`, `pack`, `remove`, `sort`, `unpack` ; `math` comprend notamment `exp` et `log`. Aucun accès aux fichiers, au réseau, à l'horloge réelle ou à `math.random`. Le processus a une limite de 256 Mio d'espace d'adressage et 32 Mio de tas Lua surveillé.
