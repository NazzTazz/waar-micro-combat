# Débit mesuré pendant 20 secondes par moteur

```sh
php bin/benchmark-cohort.php --duration=20 --output=reports/benchmark-20s.json
```

Profil Test 2 inchangé, budget 400 400 par camp, météo neutre. Chaque lot contient
32 combats : les 16 monotypes et les 16 confrontations mixtes du benchmark,
une répétition chacun. Le mélange est donc exactement 50/50 sur chaque fenêtre,
quelle que soit la vitesse du moteur. Mêmes empreintes d'entrées local/VPS.

| Machine | Moteur | Combats terminés | Durée réelle | Combats / seconde |
| --- | --- | ---: | ---: | ---: |
| PC Windows | PHP 8.2.33 | 768 | 20,524 s | **37,4** |
| PC Windows | Rust release | 10 528 | 20,013 s | **526,1** |
| VPS Linux | PHP 8.3.33 | 1 376 | 20,420 s | **67,4** |
| VPS Linux | Rust release | 51 680 | 20,011 s | **2 582,6** |

Sources : [local.json](local.json), [vps.json](vps.json). Mesures réelles,
aucune extrapolation à 100 000 combats. On termine le dernier lot commencé
avant 20 secondes ; le débit utilise la durée effective, dépassement compris.
Un échauffement de 32 combats par moteur est exclu de la mesure.

Ces fichiers ont été produits au commit `a4b0cc3` et conservent ses empreintes
de provenance. La PR #9 a ensuite retiré l'identifiant et le libellé décoratifs
de l'empreinte sémantique du profil ; elle ne change pas les valeurs de gameplay
ni les résultats mesurés ici.

Résultats entiers identiques sur tous les lots communs : 768 combats sur le PC,
1 376 sur le VPS, plus les échauffements. Les autres combats exécutés seulement
en Rust ne sont pas vérifiés en PHP. Les seeds progressent à chaque lot.

Cette mesure inclut le lancement d'un processus par lot, JSON, résolution,
conséquences et gestion du pilote (dont les empreintes), sans HTTP. Ce n'est
pas le débit du seul calcul Rust. Les lots sont plus petits que lors de la
mesure précédente (32 au lieu de 80), donc le démarrage des processus pèse
davantage, particulièrement sous Windows. Ne pas comparer directement ces
débits aux anciens chiffres sans tenir compte de ce changement.

PC : i7-1065G7, 4 cœurs / 8 threads. VPS : deux vCPU Haswell virtualisés,
conteneur distinct limité à 1 CPU et 768 Mio, sans réseau ni port.
Moteurs exécutés séquentiellement par machine, aucune compilation ni suite
de tests simultanée. Un passage : fréquence CPU, autres applications et charge
du VPS peuvent faire varier le résultat. La démo web reste inchangée.
