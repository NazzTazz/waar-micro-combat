# Profils partagés de démonstration

Le menu Profil permet de sauvegarder le ruleset courant sous un nom (convention conseillée : NomTesteur-Proposition-1), charger une sauvegarde et exporter le profil courant en JSON.

Les sauvegardes contiennent les réglages moteur, pas les armées de test ni la météo sélectionnée. Charger un profil conserve ces éléments de contexte. Le brouillon local continue à être sauvegardé dans le navigateur.

Les noms déjà utilisés, sans distinction de casse, sont refusés. Aucun écrasement ni suppression n’est exposé. La liste est rafraîchie à chaque ouverture du menu.

Sur le VPS, les données sont conservées dans le volume Docker nommé waar-engine-demo_profile-saves, monté dans /var/lib/waar-profiles, en dehors de la racine web. Ne pas supprimer ce volume pendant les déploiements ; le sauvegarder avec les données du VPS. Le compte Apache possède le répertoire. Caddy protège toutes les routes par l’authentification de la démonstration.

WAAR_PROFILE_DIRECTORY permet de choisir le répertoire ; par défaut en développement : reports/shared-profiles. Les tests HTTP utilisent leur propre répertoire temporaire et ne créent aucune sauvegarde de démonstration.

Écritures sérialisées par verrou puis remplacement atomique du fichier JSON ; profils validés par EngineProfile avant persistance. Les noms ne sont jamais utilisés comme chemins et sont affichés comme texte.
