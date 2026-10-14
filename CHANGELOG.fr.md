# Historique des changements

[English](CHANGELOG.md) · **Français**

## 0.1.7 — 2026-10-14

- Publier des éditions françaises complètes du README, de l'historique, du guide de contribution, du guide des benchmarks et du guide d'intégration Symfony/Laravel, avec des liens réciproques vers l'anglais.
- Afficher un choix de langue explicite dans les deux éditions du site, conserver la section et les paramètres d'URL lors du changement de langue et relier les guides Markdown dans la langue de lecture.
- Ajouter les URL canoniques et les métadonnées de langue, et vérifier les ancres, les éditions associées et l'identité des exemples de code.

Le code d'exécution, l'API publique et les dépendances de production sont inchangés.

## 0.1.6 — 2026-10-13

- Ajouter une autre recette Symfony qui décore `logger`, pour que `@logger`, l'autowiring de l'interface PSR et l'injection concrète de `FluentLogger` résolvent la même instance Phlogger.
- Préserver le backend d'origine via un service interne explicite, en évitant les références circulaires et en conservant les handlers, processors et canaux nommés de Monolog.
- Ajouter un exemple exécutable de logger par défaut et des tests d'intégration pour la décoration, les deux types d'injection, le contexte PSR, les valeurs fluides différées et l'isolation des canaux.
- Documenter les deux variantes Symfony et leur configuration de remise à zéro des quotas dans le Markdown anglais et le site anglais/français ; exécuter le nouvel exemple dans la CI.

Le code d'exécution, l'API publique et les dépendances de production sont inchangés.

## 0.1.5 — 2026-10-13

- Documenter et tester les informations du site d'appel applicatif avec l'`IntrospectionProcessor` de Monolog, y compris les méthodes PSR héritées et la livraison depuis un buffer.
- Ajouter des benchmarks reproductibles de l'API pour les appels PSR directs, les instructions fluides activées ou désactivées, les valeurs différées, les enregistrements réutilisables et les limitations, avec des mesures répétées et une sortie JSON.
- Ajouter des exemples exécutables de composants Symfony et Laravel, un guide d'intégration anglais et des recettes bilingues sur le site pour l'injection dans le conteneur, les canaux et les choix de cycle de vie des quotas.
- Tester l'autowiring Symfony et le routage des canaux, le contexte et les événements Laravel, les quotas scoped, les quotas partagés dans le worker et les remises à zéro explicites avec des horloges déterministes.
- Étendre la CI de qualité aux combinaisons compatibles de Symfony DependencyInjection 7.4 / 8.x et Illuminate 12.x / 13.x sur PHP 8.3, 8.4 et 8.5 ; vérifier l'exécution de tous les exemples et du benchmark sans seuils temporels.

Les composants des frameworks et Monolog sont uniquement des dépendances de développement. Le code d'exécution et l'API publique sont inchangés ; `psr/log` reste la seule dépendance de production.

## 0.1.4 — 2026-10-13

- Abaisser la version PHP minimum prise en charge à 8.3 sans changer l'API publique ni le code source d'exécution.
- Exécuter les vérifications de qualité et d'installation de production seules sur PHP 8.3, 8.4 et 8.5.
- Cibler PHP 8.3 dans l'analyse statique et actualiser les exigences du Markdown anglais et du site bilingue.

`psr/log` reste la seule dépendance de production.

## 0.1.3 — 2026-10-13

- Publier `roukmoute/phlogger` sur Packagist avec des mises à jour GitHub automatiques.
- Documenter l'installation directe avec `composer require roukmoute/phlogger` dans le README et les deux langues du site.
- Ajouter les liens de documentation, de page d'accueil et de suivi des tickets aux métadonnées du package.

## 0.1.2 — 2026-10-13

- Présenter directement Phlogger comme une API de logging PSR-3 fluide dans le README, les métadonnées du package et les deux langues du site.
- Retirer les références et liens vers des inspirations externes de la documentation courante.

## 0.1.1 — 2026-10-13

- Ajouter un exemple exécutable Monolog 3 avec une sortie JSON, l'interpolation PSR-3, le contexte d'exception et des calculs différés filtrés via la méthode `isHandling()` du backend.
- Ajouter des tests d'intégration avec de vrais handlers et processors Monolog, le filtrage dynamique et la livraison depuis un buffer.
- Exécuter l'exemple Monolog dans la CI et vérifier que l'installation de production seule contient exactement `psr/log`.
- Documenter la frontière du backend PSR-3 et l'intégration Monolog dans le Markdown anglais et les deux langues du site.

Monolog est une dépendance de développement. L'ensemble des dépendances de production et l'API publique sont inchangés.

## 0.1.0 — 2026-10-13

Première version publique de l'API. Nécessite PHP 8.5+ et `psr/log` 3.x comme seule dépendance de production.

- Ajouter un `FluentLogger` compatible PSR-3 avec les huit méthodes de niveau fluides et une enum `Level`.
- Ajouter des enregistrements et contextes de logger immuables, les causes d'exception et les logs conditionnels.
- Ajouter des messages différés et des valeurs explicites de contexte/mise en forme `LazyValue`, évalués une fois par enregistrement admis.
- Ajouter la mise en forme `printf` différée via `logf()` tout en préservant les modèles PSR-3 via `log()`.
- Ajouter les limites de nombre et de temps, les clés par site d'appel ou explicites, l'état partagé borné et la prise en charge de la remise à zéro.
- Ajouter des tests de comportement, une analyse statique stricte, un exemple exécutable et la validation des installations de production seules dans la CI.
- Ajouter une documentation Markdown anglaise et un site GitHub Pages statique anglais/français.

Cette version précède la 1.0 : l'API publique peut changer dans les versions mineures suivantes. Les limites de fréquence sont locales au processus et ne coordonnent pas plusieurs workers.
