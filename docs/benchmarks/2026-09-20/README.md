# Première comparaison courte PHP / Rust — Test 2

Mesures du 20 septembre 2026, un passage par moteur et charge. Commande :

```sh
php bin/benchmark-cohort.php --combats=100000 --seconds=30 --batch=5
```

Résultats bruts : [PC Windows](local.json), [VPS Linux](vps.json).
Même empreinte de profil Test 2 et mêmes empreintes d'entrées sur les deux
machines. Budget 400 400 par camp, 16 monotypes et 16 confrontations de quatre
compositions mixtes, météo neutre, conséquences comprises. Lots de 80 combats.

| Machine / charge | PHP exécutés | PHP combats/s | Rust exécutés | Rust combats/s | Accélération sur les mêmes combats |
| --- | ---: | ---: | ---: | ---: | ---: |
| PC / monotypes | 2 880 | 95,1 | 49 760 | 1 657,2 | ×16,5 |
| PC / mixtes | 880 | 27,1 | 34 720 | 1 155,4 | ×37,9 |
| VPS / monotypes | 4 480 | 148,1 | 50 000 | 6 112,2 | ×42,9 |
| VPS / mixtes | 1 440 | 47,0 | 50 000 | 3 159,3 | ×68,5 |

Les accélérations utilisent les temps du préfixe strictement commun, pas le
quotient des débits de corpus de tailles différentes. Les résultats agrégés
entiers concordent sur chacun des lots communs : 3 760 combats sur le PC,
5 920 sur le VPS, plus les échauffements. Cela ne vérifie pas en PHP les combats
supplémentaires effectués seulement en Rust.

## Pour 100 000 combats (50 000 de chaque charge)

| Machine | Rust | PHP |
| --- | --- | --- |
| PC | **73,4 s estimées** ; 84 480 combats réellement mesurés en 60,1 s | **39,5 min estimées** ; 3 760 combats mesurés en 62,7 s |
| VPS | **24,0 s mesurées**, 100 000 combats terminés | **23,4 min estimées** ; 5 920 combats mesurés en 60,9 s |

Les passages PHP complets n'ont pas été lancés pour respecter la demande d'une
vérification courte. La borne de 30 secondes est contrôlée après chaque lot,
ce qui explique les légers dépassements. Les estimations sont des extrapolations
par charge, pas des mesures complètes ni des garanties.

## Environnement et interprétation

- PC : Windows, Intel Core i7-1065G7, 4 cœurs / 8 threads, PHP 8.2.33.
- VPS : Linux/KVM, deux vCPU exposés comme Intel Haswell, PHP 8.3.33 ; conteneur
  de benchmark distinct, plafonné à **1 CPU et 768 Mio**, sans réseau ni port.
- Rust release sur chaque système, moteur et lockfile du profil de démo
  `a4b0cc3`. Résolution séquentielle, aucun parallélisme de combats.
- Échauffement exclu. Les temps incluent les processus, JSON et conséquences,
  sans HTTP. Il ne s'agit pas d'un microbenchmark de la seule boucle Rust.
- Le coût de lancement sous Windows, le matériel, la version PHP, la fréquence
  CPU et la charge du VPS influencent la comparaison entre machines. Cette
  mesure ne permet pas de les isoler, ni de déclarer le CPU du VPS plus rapide.
- Un passage seulement ; les min/max par lot sont conservés, pas d'intervalle
  de confiance. Le pic mémoire du rapport concerne uniquement le pilote PHP.

Le [guide](../../../ops/benchmark/README.md) décrit le lancement et les limites.
La démonstration web n'a pas été modifiée ou redémarrée pour cette mesure.
