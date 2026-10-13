# Benchmarks

These standalone microbenchmarks measure Phlogger's API overhead without disk, network, formatters or processors. Every case uses `Psr\Log\NullLogger`, and the runner needs no benchmark package. PHP 8.3 or later and the project's Composer autoloader are required; a production-only dependency installation is sufficient.

From the repository root:

```sh
composer install
php benchmarks/run.php
php benchmarks/run.php --iterations=200000 --samples=9 --warmup=20000
php benchmarks/run.php --iterations=200000 --samples=9 --warmup=20000 --json > benchmark.json
```

Use `--help` for options. Each case receives a warm-up run followed by at least three timed samples. The runner rotates case order between samples, uses `hrtime(true)` for elapsed time, and creates fresh loggers and quota state outside each measured loop. It prints the PHP version, platform and OPcache/JIT settings. JSON output includes each raw sample so results can be inspected rather than reduced to one timing.

## Cases

| Group | What it measures |
| --- | --- |
| `psr-enabled`, `bridge-enabled`, `fluent-enabled`, `fluent-reused` | The same message and context sent directly through PSR-3, through Phlogger's PSR-3 methods, through a new fluent entry, or through a reusable entry. |
| `fluent-chain-new`, `fluent-chain-reused` | The same admitted message, context and always-true condition, with the modifier chain constructed per attempt or prepared once. This isolates the cost of rebuilding immutable entries. |
| Disabled cases | Debug statements rejected by an Info threshold. The direct PSR-3 baseline uses an explicit level guard because PSR-3 has no standard readiness method. Phlogger uses its own level filter. The chained case still constructs its entry and modifiers before rejection. |
| Enabled lazy cases | Each admission computes a message and a context value through two prepared suppliers. Each supplier hashes a 64-byte string with SHA-256. Direct PSR-3 calls compute the values before delivery; fluent calls resolve them after admission. New-entry cases include creating a `LazyValue` wrapper and modifiers; reused-entry cases prepare them once. |
| Disabled lazy cases | The same suppliers are never evaluated: the manual PSR-3 guard and Phlogger's level filter both reject the statement. The new-entry case includes wrapper and modifier construction even when delivery is rejected. |
| Every-100 cases | A manual modulo guard versus `every(100)`, admitting the first attempt and then every 100 attempts. Fluent cases cover rebuilt entries with an explicit key, a reusable keyed entry, and automatic call-site detection. |
| Time cases | A manual time guard versus a reusable `atMostEvery(60)` entry with an explicit key. A constant injected clock makes both admit exactly one record per sample, without sleeps or time-dependent admission counts. This measures repeated suppression, not a real-time workload. |

The direct rate-limit baselines handle a single local counter or timestamp. Phlogger also provides policy keys, shared state and bounded LRU storage. Their admission patterns are equivalent for this workload; their capabilities and amount of internal work are not. A plain direct PSR-3 call has no level filter, deferred-value handling or quotas, so do not compare every row as if the features were interchangeable.

## Reading the output

`Median ns`, `Min ns` and `Max ns` are nanoseconds per **attempted** statement, including the `for` loop. `Admitted` is the expected number of records accepted per sample, not an observed backend count. Disabled cases accept zero; sampling and time cases accept only part of their attempts. The runner does not instrument the backend, because counting records would change the measured cost.

`Peak KiB` is the median peak temporary PHP memory above the baseline taken after preparing each operation. It uses `memory_get_usage(false)`, `memory_reset_peak_usage()` and `memory_get_peak_usage(false)`. It is **not** the total number of allocations, process resident memory, or a leak measurement. Repeated allocations can reuse memory, and prepared entries are excluded from this baseline. The new/reused timing pairs help assess entry and modifier construction costs; peak memory alone cannot quantify them.

Run on an otherwise idle machine, use the same PHP binary and configuration for comparisons, and repeat the command. Inspect sample spread before interpreting a difference. Very small iteration counts are useful only for checking that the runner works. Debuggers, profilers, CPU power management, JIT, host load and PHP versions can change the result.

These measurements do not predict an application's logging throughput: actual backends may spend far more time on processors, formatting and delivery. There are no timing assertions or performance promises. Use the results to identify a cost worth profiling in a real workload before changing the library.
