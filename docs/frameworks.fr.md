# Intégration avec Symfony et Laravel

[English](frameworks.md) · **Français**

Phlogger encapsule le logger PSR-3 existant du framework. Installez `roukmoute/phlogger` dans votre application ; aucun bundle ou package de service provider Phlogger n'est nécessaire. Gardez les dépendances du framework dans l'application. Phlogger lui-même nécessite toujours uniquement `psr/log` comme dépendance de production.

Enregistrez `FluentLogger` comme service supplémentaire ou utilisez la décoration de services Symfony pour en faire le logger par défaut. La décoration doit référencer le service interne d'origine plutôt que le logger qu'elle remplace, ce qui créerait une dépendance circulaire. La recette Laravel ci-dessous enregistre un service scoped supplémentaire.

## Symfony

### Service fluide supplémentaire

Ajoutez cette définition sous `services` dans `config/services.yaml` :

```yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@logger'
```

Utilisez l'injection par constructeur dans un service applicatif :

```php
namespace App\Service;

use Phlogger\FluentLogger;

final class ImportService
{
    public function __construct(private readonly FluentLogger $logger)
    {
    }

    public function import(int $batchId): void
    {
        $this->logger->atInfo()
            ->with('batch_id', $batchId)
            ->log('Import started for batch {batch_id}');
    }
}
```

Avec la configuration habituelle d'autowiring Symfony, cela injecte le service fluide supplémentaire. Le `logger` d'origine et l'alias du logger PSR continuent de servir les autres consommateurs.

Pour un canal Monolog dédié, définissez ce canal dans `config/packages/monolog.yaml`, puis changez le backend du service fluide :

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['payments']

# config/services.yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@monolog.logger.payments'
```

Les handlers, le routage et les processors restent dans la configuration de MonologBundle. Cela sélectionne le canal du backend ; cela ne sélectionne pas automatiquement les niveaux activés de Phlogger. Définissez `$minimumLevel` avec un nom PSR-3 en minuscules ou utilisez une factory applicative avec la [recette de filtre Monolog](../README.fr.md#intégration-avec-monolog) pour éviter les calculs différés aux niveaux désactivés dans le backend.

### Faire de Phlogger le `logger` par défaut

Utilisez cette autre définition dans `config/services.yaml` pour que les consommateurs de `@logger` et de `Psr\Log\LoggerInterface` injecté automatiquement reçoivent Phlogger :

```yaml
services:
    Phlogger\FluentLogger:
        decorates: logger
        decoration_inner_name: phlogger.backend
        arguments:
            $backend: '@phlogger.backend'

    Psr\Log\LoggerInterface: '@logger'
```

Remplacez la définition du service supplémentaire ci-dessus par celle-ci. Symfony déplace le `logger` d'origine derrière `phlogger.backend` et fait résoudre `logger` vers le décorateur `FluentLogger`. L'alias PSR explicite fait suivre l'autowiring de l'interface vers ce même service. N'utilisez pas `$backend: '@logger'` dans le décorateur : cela injecterait Phlogger dans lui-même.

Les services existants typés `LoggerInterface` continuent d'utiliser les méthodes PSR-3 telles que `info()` et `error()`. Typez les nouveaux consommateurs fluides comme `FluentLogger` pour exposer `atInfo()` et les autres méthodes fluides à l'analyse statique ; les deux types résolvent la même instance. Les appels PSR ordinaires conservent leurs modèles de message et leur contexte et n'évaluent pas les objets `LazyValue`.

Monolog continue de traiter les enregistrements avec ses handlers, processors et son cycle de remise à zéro existants. La recette décore l'alias `logger` par défaut ; les services explicitement injectés tels que `@monolog.logger.payments` restent indépendants. Les consommateurs qui ont besoin d'API propres au backend peuvent injecter explicitement `@phlogger.backend`. Gardez le minimum debug par défaut de Phlogger pour transmettre tous les niveaux PSR ou configurez délibérément `$minimumLevel` si vous voulez filtrer l'ensemble de l'application.

L'[exemple exécutable de logger par défaut](../examples/symfony-default-logger.php) compile le conteneur, injecte automatiquement les consommateurs PSR et fluides et émet les enregistrements via le backend Monolog d'origine. Les tests d'intégration vérifient aussi l'isolation des canaux nommés et le remplacement de l'alias sans référence circulaire. Consultez la [documentation Symfony sur la décoration des services](https://symfony.com/doc/current/service_container/decoration.html).

### Quotas partagés dans le worker ou par message

Les services Symfony sont partagés par défaut. Réutiliser le même service dans un worker de longue durée conserve ses quotas entre les messages. Les clones contextuels partagent aussi cet état. C'est utile pour un avertissement qui doit être émis au maximum une fois par minute pendant toute la durée de vie d'un worker.

Pour de nouveaux quotas à chaque frontière de remise à zéro Symfony, activez ce comportement explicitement :

Pour la variante du service supplémentaire :

```yaml
services:
    Phlogger\FluentLogger:
        arguments:
            $backend: '@logger'
        tags:
            - { name: kernel.reset, method: resetRateLimits }
```

Pour la variante du logger par défaut, ajoutez le même tag `kernel.reset` à la définition du décorateur en conservant `$backend: '@phlogger.backend'`. Ne remplacez pas son backend par `@logger`.

Le tag est exécuté lorsque Symfony remet ses services à zéro, notamment lors de la remise à zéro habituelle de Messenger entre les messages. Désactiver la remise à zéro des services change ce comportement. Dans une boucle séquentielle personnalisée, appelez vous-même `resetRateLimits()` entre les jobs. Cela réinitialise chaque clone contextuel de cette famille de loggers ; ne l'appelez donc pas pendant qu'un autre job concurrent utilise cette famille.

Réinitialiser les quotas de Phlogger est distinct de la remise à zéro des buffers et processors d'un backend. Continuez d'utiliser le cycle de vie Monolog habituel du framework. Choisissez des limites par job ou par worker avant d'activer le tag : une remise à zéro par job fait admettre le premier avertissement de chaque job par une limite d'une minute.

Consultez les documentations officielles Symfony sur [les logs](https://symfony.com/doc/current/logging.html), [le tag de remise à zéro](https://symfony.com/doc/current/reference/dic_tags.html#kernel-reset) et [Messenger](https://symfony.com/doc/current/messenger.html).

## Laravel

Ajoutez une liaison scoped dans `App\Providers\AppServiceProvider::register()` :

```php
use Illuminate\Contracts\Foundation\Application;
use Phlogger\FluentLogger;
use Psr\Log\LoggerInterface;

public function register(): void
{
    $this->app->scoped(
        FluentLogger::class,
        static fn (Application $app): FluentLogger => new FluentLogger(
            $app->make(LoggerInterface::class),
        ),
    );
}
```

Injectez `FluentLogger` dans les contrôleurs, services ou jobs. Laravel résout le logger PSR existant via son gestionnaire de logs. Le wrapper conserve le contexte Laravel et les événements `MessageLogged` au lieu de les contourner avec l'objet Monolog sous-jacent.

Pour un canal configuré, changez uniquement le backend de la factory en `$app->make('log')->channel('payments')`. Pour une stack, utilisez `$app->make('log')->stack(['single', 'slack'])`. Les canaux et handlers restent configurés dans `config/logging.php`.

La factory ci-dessus laisse le minimum de Phlogger à `debug`. PSR-3 ne peut pas indiquer si le backend accepte un niveau. Définissez `minimumLevel` dans la factory selon la politique de votre application lorsque Phlogger doit rejeter les calculs différés tôt. Si vous ajoutez un filtre tenant compte du backend, continuez à écrire via le logger Laravel plutôt que de l'extraire pour la livraison.

### Durées de vie scoped et singleton

Laravel renouvelle les liaisons scoped à chaque requête ou job de queue, y compris avec Octane et les workers de queue de longue durée. Résoudre `FluentLogger` à nouveau dans le scope suivant crée de nouveaux quotas. Dans un scope, les résolutions répétées et les clones contextuels partagent les quotas.

Utilisez `singleton()` au lieu de `scoped()` uniquement lorsque vous voulez délibérément des quotas pour tout le worker. Cette instance et ses limites survivent aux changements de scope jusqu'à leur remise à zéro explicite ou la fin du processus. Évitez de conserver un logger scoped ou l'un de ses clones contextuels dans un consommateur singleton : ce consommateur conserverait l'état de l'ancien scope. Résolvez de nouveau les consommateurs scoped pour chaque job et gardez les identifiants de requête locaux à ce scope.

La remise à zéro de Phlogger n'efface ni le contexte partagé Laravel ni les buffers Monolog. L'application reste responsable de leur cycle de vie. Ne réinitialisez pas un logger partagé pendant qu'un travail concurrent dépend encore de ses quotas.

Consultez les documentations officielles Laravel sur [les logs](https://laravel.com/docs/13.x/logging), [les liaisons scoped](https://laravel.com/docs/13.x/container#binding-scoped-singletons) et [l'injection de dépendances avec Octane](https://laravel.com/docs/13.x/octane#dependency-injection-and-octane).

## Exemples exécutables et validation

Depuis un clone de développement :

```sh
composer install
php examples/symfony.php
php examples/symfony-default-logger.php
php examples/laravel.php
composer test
```

Les exemples utilisent de vrais composants des frameworks. L'exemple de worker Symfony supprime l'avertissement du deuxième job, puis admet un troisième job après une remise à zéro explicite ; son exemple de logger par défaut présente la décoration de service et les deux types d'injection. L'exemple Laravel émet une fois dans chacun des deux scopes renouvelés. Ils ne démarrent pas de kernel Symfony complet, d'application Laravel, de worker Messenger ou de serveur Octane. L'exemple Laravel efface explicitement les instances scoped pour présenter la frontière gérée par le framework dans une application.

Les tests d'intégration couvrent l'injection dans le conteneur, le routage des canaux, le contexte et les événements Laravel, les limites partagées dans le worker, les remises à zéro explicites et les quotas scoped renouvelés. La CI exerce Symfony DependencyInjection 7.4 et 8.x et Illuminate 12.x et 13.x sur les versions PHP compatibles. Ces composants sont uniquement des dépendances de développement ; les installations de production sont vérifiées séparément pour contenir exactement `psr/log`.
