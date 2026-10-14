# Benchmarks

[English](README.md) · **Français**

Ces microbenchmarks autonomes mesurent le coût de l'API de Phlogger sans disque, réseau, formatters ou processors. Tous les cas utilisent `Psr\Log\NullLogger` et le script n'a besoin d'aucun package de benchmark. PHP 8.3 ou une version ultérieure et l'autoloader Composer du projet sont nécessaires ; une installation avec les seules dépendances de production suffit.

Depuis la racine du dépôt :

```sh
composer install
php benchmarks/run.php
php benchmarks/run.php --iterations=200000 --samples=9 --warmup=20000
php benchmarks/run.php --iterations=200000 --samples=9 --warmup=20000 --json > benchmark.json
```

Utilisez `--help` pour les options. Chaque cas reçoit un échauffement suivi d'au moins trois mesures chronométrées. Le script fait tourner l'ordre des cas entre les mesures, utilise `hrtime(true)` pour le temps écoulé et crée de nouveaux loggers et états de quotas en dehors de chaque boucle mesurée. Il affiche la version de PHP, la plateforme et les paramètres OPcache/JIT. La sortie JSON contient chaque mesure brute pour permettre son inspection plutôt que de la réduire à un seul temps.

## Cas mesurés

| Groupe | Mesure |
| --- | --- |
| `psr-enabled`, `bridge-enabled`, `fluent-enabled`, `fluent-reused` | Le même message et contexte envoyés directement via PSR-3, via les méthodes PSR-3 de Phlogger, via un nouvel enregistrement fluide ou via un enregistrement réutilisable. |
| `fluent-chain-new`, `fluent-chain-reused` | Le même message admis, le même contexte et une condition toujours vraie, avec la chaîne de modificateurs construite à chaque tentative ou préparée une seule fois. Ce cas isole le coût de reconstruction des enregistrements immuables. |
| Cas désactivés | Les instructions debug sont rejetées par un seuil Info. La référence PSR-3 directe utilise une vérification explicite du niveau puisque PSR-3 n'a pas de méthode standard de disponibilité. Phlogger utilise son propre filtre de niveau. Le cas avec chaîne construit encore son enregistrement et ses modificateurs avant le rejet. |
| Cas différés activés | Chaque admission calcule un message et une valeur de contexte via deux fournisseurs préparés. Chaque fournisseur hache une chaîne de 64 octets avec SHA-256. Les appels PSR-3 directs calculent les valeurs avant la livraison ; les appels fluides les résolvent après l'admission. Les cas créant un nouvel enregistrement comprennent la création du wrapper `LazyValue` et des modificateurs ; les cas réutilisés les préparent une seule fois. |
| Cas différés désactivés | Les mêmes fournisseurs ne sont jamais évalués : la vérification PSR-3 manuelle et le filtre de niveau de Phlogger rejettent tous deux l'instruction. Le cas créant un nouvel enregistrement comprend la construction du wrapper et des modificateurs même lorsque la livraison est rejetée. |
| Cas Every-100 | Une vérification modulo manuelle comparée à `every(100)`, qui admet la première tentative puis une toutes les 100 tentatives. Les cas fluides couvrent les enregistrements reconstruits avec une clé explicite, un enregistrement réutilisable avec clé et la détection automatique du site d'appel. |
| Cas temporels | Une vérification de temps manuelle comparée à un enregistrement réutilisable `atMostEvery(60)` avec une clé explicite. Une horloge constante injectée les fait admettre exactement un enregistrement par mesure, sans attente ni nombre d'admissions dépendant du temps. Ce cas mesure la suppression répétée, pas une charge en temps réel. |

Les références directes de limitation gèrent un seul compteur ou horodatage local. Phlogger fournit aussi des clés de politique, un état partagé et un stockage LRU borné. Leurs motifs d'admission sont équivalents pour cette charge ; leurs capacités et la quantité de travail interne ne le sont pas. Un simple appel PSR-3 direct n'a ni filtre de niveau, ni traitement des valeurs différées, ni quotas : ne comparez pas toutes les lignes comme si leurs fonctionnalités étaient interchangeables.

## Lire la sortie

`Median ns`, `Min ns` et `Max ns` sont des nanosecondes par instruction **tentée**, boucle `for` comprise. `Admitted` est le nombre attendu d'enregistrements acceptés par mesure, pas un comptage observé dans le backend. Les cas désactivés n'en acceptent aucun ; les cas d'échantillonnage et de temps n'acceptent qu'une partie des tentatives. Le script n'instrumente pas le backend, car compter les enregistrements modifierait le coût mesuré.

`Peak KiB` est la médiane du pic temporaire de mémoire PHP au-dessus de la référence prise après la préparation de chaque opération. Il utilise `memory_get_usage(false)`, `memory_reset_peak_usage()` et `memory_get_peak_usage(false)`. Ce n'est **pas** le nombre total d'allocations, la mémoire résidente du processus ou une mesure de fuite. Les allocations répétées peuvent réutiliser la mémoire et les enregistrements préparés sont exclus de cette référence. Les paires de temps nouveau/réutilisé aident à évaluer le coût de construction des enregistrements et modificateurs ; le pic de mémoire seul ne peut pas le quantifier.

Exécutez le script sur une machine autrement inactive, utilisez le même binaire et la même configuration PHP pour les comparaisons, et répétez la commande. Inspectez la dispersion des mesures avant d'interpréter une différence. Les nombres d'itérations très faibles servent uniquement à vérifier l'exécution du script. Les débogueurs, profilers, la gestion de l'énergie du processeur, le JIT, la charge de l'hôte et les versions de PHP peuvent modifier le résultat.

Ces mesures ne prédisent pas le débit de logging d'une application : les vrais backends peuvent passer bien plus de temps dans les processors, la mise en forme et la livraison. Il n'y a ni assertion de temps ni promesse de performance. Utilisez les résultats pour identifier un coût à profiler dans une charge réelle avant de modifier la bibliothèque.
