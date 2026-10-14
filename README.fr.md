# Phlogger

[English](README.md) · **Français**

Une API de logging fluide pour PHP.

Phlogger ajoute des instructions de log lisibles, des calculs différés et une limitation de fréquence par instruction à n'importe quel logger PSR-3. Il nécessite **PHP 8.3+** et n'a qu'une seule dépendance de production : **`psr/log`**. Fournissez votre backend existant ; Phlogger n'écrit pas de fichiers et ne configure pas de handlers.

```php
use Phlogger\FluentLogger;
use Phlogger\LazyValue;
use Phlogger\Level;

$logger = new FluentLogger($psrLogger, minimumLevel: Level::Info);

$logger->atWarning()
    ->withCause($exception)
    ->with('order_id', $orderId)
    ->atMostEvery(30)
    ->log('Payment failed for order {order_id}');

$logger->atDebug()
    ->with('summary', LazyValue::of(fn () => calculateSummary()))
    ->log('Diagnostic summary: {summary}');
```

La seconde instruction n'appelle pas `calculateSummary()` puisque le niveau minimum de Phlogger est `Info`.

## Installation

Installez le package depuis [Packagist](https://packagist.org/packages/roukmoute/phlogger) :

```sh
composer require roukmoute/phlogger
```

PHP 8.3 ou une version ultérieure est nécessaire. Composer installe une version étiquetée et `psr/log` ; aucune configuration de dépôt personnalisée n'est nécessaire. Monolog reste un backend facultatif choisi par votre application.

## Niveaux et compatibilité PSR-3

Utilisez `atDebug()`, `atInfo()`, `atNotice()`, `atWarning()`, `atError()`, `atCritical()`, `atAlert()` ou `atEmergency()`. Les niveaux dynamiques acceptent les cas de l'enum `Level` ou les noms PSR-3 exacts en minuscules :

```php
$logger->at(Level::Notice)->log('Import completed');
$logger->at('notice')->log('Import completed');
```

`FluentLogger` implémente également `Psr\Log\LoggerInterface` et peut donc être injecté dans les services existants :

```php
$logger->info('User {id} signed in', ['id' => 42]);
$logger->log('error', 'Request failed', ['exception' => $exception]);
```

Ces méthodes PSR-3 conservent les valeurs de contexte arbitraires, y compris les closures et les objets `LazyValue`. Les valeurs différées sont évaluées uniquement via l'API fluide. Un niveau inconnu déclenche `Psr\Log\InvalidArgumentException`, même lorsque les logs sont désactivés.

## Intégration avec Monolog

Phlogger ajoute une couche fluide à **n'importe quel logger PSR-3**. Monolog est un backend compatible : il gère les handlers, les destinations, la mise en forme et la mise en mémoire tampon, tandis que Phlogger fournit les instructions fluides et les calculs différés.

Si votre application choisit Monolog, installez-le dans cette application :

```sh
composer require "monolog/monolog:^3.0"
```

```php
$handler = new Monolog\Handler\StreamHandler('php://stdout', Monolog\Level::Info);
$handler->setFormatter(new Monolog\Formatter\JsonFormatter());
$backend = new Monolog\Logger('app', [$handler], [
    new Monolog\Processor\PsrLogMessageProcessor(),
]);

$logger = new Phlogger\FluentLogger(
    $backend,
    filter: static fn (Phlogger\Level $level): bool =>
        $backend->isHandling($level->value),
);

$logger->atWarning()
    ->withCause($exception)
    ->with('order_id', 42)
    ->atMostEvery(30)
    ->log('Payment failed for order {order_id}');
```

`PsrLogMessageProcessor` interpole les `{placeholders}` à partir du contexte. Sans lui, Phlogger transmet toujours le modèle d'origine et les valeurs structurées. `JsonFormatter` met en forme l'enregistrement pour la sortie, y compris les détails de l'exception.

Le filtre facultatif consulte l'ensemble de la chaîne de handlers de Monolog avant que Phlogger évalue les messages ou contextes différés. Il suit les changements ultérieurs de handlers et évite les calculs différés aux niveaux désactivés. Le `minimumLevel` propre à Phlogger peut restreindre davantage ces niveaux. `isHandling()` estime l'acceptation du niveau ; la mise en mémoire tampon, le routage et les autres politiques des handlers peuvent encore influer sur la livraison. Consultez la chaîne du backend plutôt que de recopier le seuil d'un handler de fichier final : `FingersCrossedHandler`, par exemple, peut conserver les enregistrements debug jusqu'à ce qu'une erreur déclenche leur émission.

Pour enregistrer le fichier et la ligne d'appel dans l'application, configurez l'`IntrospectionProcessor` de Monolog pour ignorer les frames des wrappers de Phlogger :

```php
$backend->pushProcessor(new Monolog\Processor\IntrospectionProcessor(
    skipClassesPartials: ['Phlogger\\', 'Psr\\Log\\'],
));
```

Ignorer aussi `Psr\Log\` prend en charge les méthodes héritées telles que `info()`. Attachez le processor au **logger**, avant que les handlers mettent les enregistrements en mémoire tampon. Un processor attaché à un handler derrière `FingersCrossedHandler` enregistre l'emplacement de l'émission du buffer plutôt que l'appel d'origine. L'[exemple d'introspection](examples/monolog-introspection.php) et les tests d'intégration couvrent plusieurs sites d'appel, y compris la livraison différée du buffer.

Dans ce dépôt, Monolog est **uniquement une dépendance de développement**, utilisée par l'[exemple Monolog exécutable](examples/monolog.php) et les [tests d'intégration](tests/Integration/MonologIntegrationTest.php). La dépendance de production de Phlogger reste `psr/log` seule. Pour exécuter l'exemple depuis un clone :

```sh
composer install
php examples/monolog.php
```

Il écrit trois enregistrements JSON sur stdout : une commande, un avertissement de paiement limité en fréquence et un message de fin. Les callbacks debug ne sont pas évalués. Les applications utilisant des workers de longue durée gèrent le cycle de vie `reset()`/`close()` de Monolog ; Phlogger expose séparément `resetRateLimits()`.

## Symfony et Laravel

Enregistrez `FluentLogger` comme service supplémentaire qui encapsule le logger existant du framework. Symfony peut injecter `@logger` ou un canal Monolog nommé, ou décorer `logger` pour que Phlogger devienne le logger PSR par défaut. Laravel peut utiliser une liaison scoped autour de son logger PSR ou d'un canal sélectionné, en conservant le contexte et les événements de log de Laravel.

Le [guide d'intégration des frameworks](docs/frameworks.fr.md) contient la configuration applicative, la sélection des canaux et les choix de cycle de vie des workers. Les exemples exécutables [Symfony](examples/symfony.php) et [Laravel](examples/laravel.php) utilisent de vrais composants et simulent des jobs séquentiels sans démarrer les kernels applicatifs complets.

L'autre [exemple de logger Symfony par défaut](examples/symfony-default-logger.php) fait résoudre `@logger`, `LoggerInterface` et `FluentLogger` vers une seule instance Phlogger. Il conserve le backend d'origine comme service interne afin d'éviter une résolution circulaire.

Choisissez délibérément la durée de vie des quotas : un logger partagé dans le worker conserve les compteurs entre les jobs ; une remise à zéro explicite ou une nouvelle instance scoped crée de nouveaux quotas. Les clones contextuels partagent l'état du limiteur de leur parent. Enregistrer séparément le service fluide préserve la liaison du logger PSR existant du framework.

## Contexte et exceptions

```php
$requestLogger = $logger->withContext(['request_id' => $requestId]);
$entry = $requestLogger->atInfo()->with('component', 'checkout');

$entry->with('order_id', 42)->log('Order {order_id} created');
$entry->with('order_id', 43)->log('Order {order_id} created');

$requestLogger->atError()
    ->withCause($exception)
    ->log('Checkout failed', ['order_id' => 42]);
```

Le contexte du logger et les modificateurs d'un enregistrement retournent de nouveaux objets. Réutiliser un enregistrement ne fait pas fuiter son contexte entre les instructions. La priorité est celle des valeurs par défaut du logger, puis du contexte de l'enregistrement, puis du contexte de l'appel terminal `log()`. Les valeurs ultérieures remplacent les précédentes sans fusion récursive des tableaux. `withCause()` écrit la clé PSR-3 `exception` ; `withCause(null)` lui attribue `null`.

`log()` conserve le modèle du message et le contexte structuré pour le backend. L'interpolation des paramètres et l'échappement appartiennent à ce backend. Les messages sous forme de chaînes ou de `Stringable` sont pris en charge.

## Calculs différés

```php
$logger->atDebug()->log(fn () => buildDiagnosticMessage());

$details = LazyValue::of(fn () => collectDiagnostics());
$logger->atDebug()
    ->with('details', $details)
    ->with('snapshot', $details)
    ->log('Diagnostics collected');

$logger->atDebug()->logf(
    'Cache size: %d',
    LazyValue::of(fn () => countCacheEntries()),
);
```

Les closures de message différé doivent retourner une chaîne ou un `Stringable`. `LazyValue` est évalué pour les valeurs de contexte fluide au premier niveau et les arguments de `logf()` ; les tableaux imbriqués et les closures ordinaires du contexte restent intacts. Un même objet `LazyValue` est évalué une seule fois par enregistrement émis, même s'il apparaît à la fois dans le contexte et les arguments de mise en forme. Le réutiliser dans un autre enregistrement l'évalue à nouveau.

`logf()` utilise le `vsprintf()` de PHP après le filtrage. Les arguments doivent se résoudre en valeurs scalaires, `null` ou `Stringable`. Les erreurs de mise en forme et les exceptions des callbacks différés sont propagées à l'appelant.

PHP évalue les arguments ordinaires des fonctions avant que l'API fluide les reçoive. Encapsulez les calculs coûteux dans une closure ou un `LazyValue` pour les différer.

## Filtrage et logs conditionnels

```php
$logger = new FluentLogger(
    $psrLogger,
    minimumLevel: Level::Info,
    filter: fn (Level $level): bool => $loggingEnabled,
);

$logger->atInfo()
    ->when(fn (): bool => $shouldReport)
    ->when($featureEnabled)
    ->log('Feature activated');
```

Le niveau minimum est vérifié avant le filtre facultatif. Les conditions s'exécutent dans l'ordre de la chaîne, après le filtrage par niveau et avant la limitation de fréquence. Toutes les conditions doivent réussir. Les messages différés, valeurs de contexte et arguments de mise en forme ne s'exécutent qu'une fois toutes ces étapes franchies.

PSR-3 n'expose pas les niveaux activés du backend. Phlogger ne peut pas découvrir si ce backend rejettera un enregistrement. Configurez le niveau minimum et le filtre de Phlogger en conséquence ; la garantie de calcul différé s'applique lorsque **Phlogger** rejette une instruction. `isEnabled()` sur le logger ou un enregistrement ne vérifie que le filtrage par niveau, sans évaluer les conditions ni consommer de quotas. Les filtres et les conditions ne doivent pas produire d'effets de bord.

## Limitation de fréquence

```php
// First call, then every hundredth additional eligible call: 1, 101, 201, ...
$logger->atInfo()->every(100)->log('Still processing');

// At most one admitted record per 30 seconds; the first is immediate.
$logger->atWarning()->atMostEvery(30)->log('Queue remains full');

// Only the first three admitted records.
$logger->atError()->first(3)->log('Connection failed');

// Explicitly group calls made through helpers or multiple call sites.
$logger->atWarning()
    ->withRateLimitKey('queue-full')
    ->every(10)
    ->atMostEvery(30)
    ->log('Queue remains full');
```

Les limites utilisent le fichier et la ligne de l'appel terminal `log()`/`logf()`, le niveau et la configuration du limiteur. Une clé explicite remplace le fichier et la ligne. Deux instructions sur la même ligne avec le même niveau et la même politique partagent un quota ; utilisez des clés explicites distinctes lorsque c'est nécessaire. Les niveaux ou politiques différents ont des quotas distincts. Configurer à nouveau un limiteur remplace sa valeur précédente.

Les limites combinées doivent toutes être prêtes. Les compteurs sont remis à zéro ensemble lorsqu'un appel est admis ; un intervalle écoulé peut donc émettre dès que le seuil du compteur est déjà atteint. Les niveaux et conditions rejetés ne font pas avancer les compteurs. L'admission consomme le quota avant les calculs différés et la livraison au backend ; une exception de callback ou de backend ne le rembourse pas.

`every()` nécessite un entier positif. `first(0)` supprime tous les appels. `atMostEvery()` accepte un nombre de secondes fini et non négatif, y compris fractionnaire ; zéro désactive la limite de temps. L'horloge par défaut utilise le `hrtime()` monotone. Le callback facultatif `clock` du constructeur peut fournir des secondes monotones pour des tests déterministes.

L'état est partagé par les clones contextuels d'un logger et reste local à cette famille de loggers dans le processus courant. Il n'est pas partagé entre les workers PHP-FPM ou les hôtes. Le stockage conserve au maximum 1 024 politiques par défaut ; `rateLimitCapacity` configure cette limite. Les entrées les moins récemment utilisées sont évincées, ce qui réinitialise leurs quotas. `resetRateLimits()` efface l'état du logger et de tous ses clones contextuels, notamment aux frontières du cycle de vie d'un worker.

## Site de documentation

Le site de documentation est disponible en [français](https://roukmoute.github.io/phlogger/fr/) et en [anglais](https://roukmoute.github.io/phlogger/en/). Ses sources statiques se trouvent dans `docs/`. Le sélecteur de langue conserve la section et les paramètres d'URL. Chaque document Markdown du dépôt propose également les deux langues via des liens réciproques.

Le workflow Pages déploie les changements poussés sur `master` et peut aussi être lancé manuellement. Pour configurer un fork, activez **Settings → Pages → Source → GitHub Actions**. Les dépôts privés nécessitent une offre GitHub compatible avec Pages ; le contenu Pages publié peut être public selon les paramètres du compte. Aucune dépendance de build n'est nécessaire pour servir le site.

## Benchmarks

Exécutez le benchmark autonome de l'API depuis un clone de développement :

```sh
composer benchmark -- --iterations=100000 --samples=5
```

Il compare les appels PSR directs, les appels filtrés, les instructions fluides acceptées ou rejetées, les valeurs différées et les limitations avec un backend `NullLogger`. L'échauffement et les mesures répétées séparent le coût de l'API des entrées/sorties fichier ou réseau. Le [guide des benchmarks](benchmarks/README.fr.md) explique les scénarios, la sortie exploitable par programme et les limites des mesures. Les temps sont des observations sur la machine courante, pas des seuils de performance pour la CI.

## Développement

```sh
composer install
composer validate --strict
composer check
php examples/basic.php
php examples/monolog.php
php examples/monolog-introspection.php
php examples/symfony.php
php examples/symfony-default-logger.php
php examples/laravel.php
```

Monolog, les composants des frameworks, PHPUnit et PHPStan sont uniquement des dépendances de développement. Consultez [CONTRIBUTING.fr.md](CONTRIBUTING.fr.md) pour le déroulement des tests et des revues. La CI exécute les tests, l'analyse statique, tous les exemples et un test d'exécution du benchmark sur PHP 8.3, 8.4 et 8.5, avec les combinaisons compatibles de Symfony DependencyInjection 7.4 / 8.x et Illuminate 12.x / 13.x. Les installations de production isolées contiennent exactement `psr/log`. L'analyse statique cible également le niveau de langage PHP 8.3.

## Licence

MIT ; consultez [LICENSE](LICENSE).
