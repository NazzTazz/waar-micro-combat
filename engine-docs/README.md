# Résultats exploratoires du moteur Waar

Ce dossier publie une sélection **figée** de mesures de la campagne de cartographie. Les analyses sont exploratoires : elles ne valent ni validation globale du moteur, ni choix de gameplay, ni manuel achevé. Le profil mesuré est `Nazz-Eq-20%-Rc2` (SHA-256 `4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`), et non un profil Astra-RC1.

- [Diagnostics et confirmations D/C](campaign/diagnostics/README.md) : constats et CSV corrigés des 11 plans cités.
- [Première coupe du 24 septembre](campaign/2026-09-24-initial/README.md) : 70 plans complets à la coupure, constats, trois figures SVG et données agrégées.
- [Coupe E/X du 24 septembre](campaign/2026-09-24-e-x/README.md) : 22 plans de compositions en météo neutre, deux figures SVG et données agrégées.

Chaque coupe conserve ses CSV de comptes et comparaisons, le manifeste des entrées, l'inventaire SHA-256 du snapshot source et ses plans exacts ([70 plans de la première coupe](campaign/2026-09-24-initial/plans/) ; [22 plans E/X](campaign/2026-09-24-e-x/plans/)). La [copie du profil](campaign/profile.json) a l'empreinte indiquée ci-dessus. Les témoins répétés sont tracés mais ne constituent pas des combats indépendants supplémentaires. Les coupes sont **des instantanés distincts** : ne pas additionner leurs comptes sans dédupliquer les configurations communes.

Les requêtes et réponses des lots (plusieurs centaines de Mo pour E/X) et configurations effectives générées restent dans les snapshots locaux sous `reports/campagne-coeur/analyse/snapshots/`, exclus de Git. Les fichiers publiés permettent de contrôler les agrégations et les constats chiffrés, mais **pas** de refaire l'audit intégral des lots depuis un clone seul. Les `plan.json` sont des copies exactes de ces snapshots : leurs chemins relatifs de profil et de sortie désignent l'environnement original et ne sont pas directement exécutables depuis `engine-docs/`. Aucun combat n'a été relancé pour cette publication.

Les Markdown et SVG se lisent directement sur GitHub. Un éventuel site statique `explore.waar-tools.fr` devra publier des HTML générés depuis ces sources ; ce dossier n'est pas encore un déploiement du site.
