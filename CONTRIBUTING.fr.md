# Contribuer

[English](CONTRIBUTING.md) · **Français**

Utilisez PHP 8.3 ou une version ultérieure. Installez les dépendances de développement avec `composer install`, puis exécutez :

```sh
composer validate --strict
composer check
php examples/basic.php
php examples/monolog.php
php examples/monolog-introspection.php
php examples/symfony.php
php examples/symfony-default-logger.php
php examples/laravel.php
```

Les tests doivent couvrir des comportements observables. Utilisez une horloge monotone injectée pour les tests temporels au lieu d'attendre. Vérifiez que les instructions rejetées évitent les calculs différés et que les modificateurs fluides ne peuvent pas contaminer les enregistrements réutilisables.

Gardez le code d'exécution compatible avec PHP 8.3. La CI exécute les tests, les exemples et les vérifications des installations de production seules sur PHP 8.3, 8.4 et 8.5. Les jobs de qualité sélectionnent Symfony DependencyInjection 7.4 / 8.x et Illuminate 12.x / 13.x sur les versions compatibles. PHPStan cible PHP 8.3 même s'il est exécuté avec une version plus récente.

Conservez `psr/log` comme seule dépendance de production. Les outils de développement appartiennent à `require-dev`. Préservez les modèles de message PSR-3 et les valeurs de contexte arbitraires lorsque vous utilisez les méthodes standard du logger.

Monolog est une dépendance de développement pour les tests d'intégration et l'exemple de backend. Gardez les imports Monolog dans les tests et les exemples, pas dans `src/`. Utilisez de vrais handlers et processors pour vérifier la frontière PSR-3, le filtrage différé et la livraison depuis un buffer. Le job de production de la CI vérifie que `composer update --no-dev` installe exactement `psr/log`.

Les composants Symfony et Illuminate sont des dépendances de développement pour les tests de conteneur, de canal et de cycle de vie des quotas. Gardez les imports des frameworks hors de `src/`. Les tests de composants exercent explicitement le renouvellement des scopes ou les frontières de remise à zéro ; ne les décrivez pas comme des tests de kernel complet, de worker de queue ou d'Octane. Documentez si les limites vivent pendant une requête/un job ou pendant le worker et testez l'état partagé comme l'état renouvelé sans attente.

Exécutez `composer benchmark -- --iterations=100000 --samples=5` lorsque vous étudiez le coût de l'API. Consultez [benchmarks/README.fr.md](benchmarks/README.fr.md) pour les charges comparables et les limites des mesures. La CI vérifie l'exécution du script sans assertion sur les temps.

Écrivez les commentaires du code source et les messages des commits en anglais. Maintenez chaque document Markdown du projet en anglais et en français, avec des liens de langue réciproques et des exemples de code identiques. L'anglais reste la langue par défaut. Mettez à jour les deux éditions HTML lorsque vous changez l'API publique et conservez les mêmes identifiants de section pour que le changement de langue garde le contexte de lecture. Le site est composé de HTML, CSS et JavaScript locaux dans `docs/` ; il ne nécessite ni gestionnaire de packages ni étape de build pour être servi.

Exécutez `python tools/check_documentation.py` et `node tests/documentation_navigation.cjs` pour vérifier les éditions associées, les exemples de code, les ancres, les métadonnées et la navigation de langue avant publication. Ces vérifications utilisent uniquement les bibliothèques standard de Python et Node.js et s'exécutent aussi dans la CI et avant le déploiement Pages.

Ouvrez une pull request avec une description concrète du changement de comportement et de sa validation. La licence MIT existante s'applique aux contributions.
