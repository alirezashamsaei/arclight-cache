# Arclight Cache

Original Redis object cache for WordPress. Persistent two-tier caching (in-memory + Redis), one-command health report, unified admin dashboard, WP-CLI suite. MIT licensed.

## Requirements

- PHP 7.2+ (developed/tested on PHP 8.2–8.4)
- WordPress 6.x
- Redis server; [phpredis](https://github.com/phpredis/phpredis) extension for persistent mode (graceful in-memory fallback without it)

## Install

1. Copy this directory to `wp-content/plugins/arclight-cache`.
2. Configure (pick one):
   - Dashboard: Settings → Arclight Cache (stored in `wp_options`, no CLI needed), or
   - Constant in `wp-config.php`: `define('ARCLIGHT_CACHE_CONFIG', array('host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'arclight'));` (overrides dashboard)
3. Activate the plugin, then enable the drop-in:
   - `wp arclight enable` (runs config validation + self-test first), or
   - Dashboard → Test connection, then enable via CLI once (drop-in install still requires one CLI run or manual copy of `stubs/object-cache.php` to `wp-content/object-cache.php`)

## WP-CLI

```
wp arclight dry_run [--format=json]   # validate config
wp arclight enable                    # self-test + install drop-in
wp arclight disable                   # remove drop-in
wp arclight flush                     # flush memory + Redis DB
wp arclight info [--format=json]      # live stats
wp arclight doctor [--format=json]    # connect/AUTH/round-trip/memory/prefix report
wp arclight digest [--format=json]    # metrics rollup
```

## Dashboard

Settings → Arclight Cache: status, lifetime requests/hits/misses, 24h graph, connection test, flush button, Redis key browser (SCAN-based, grouped), connection settings form, live status. REST: `arclight/v1/stats|series|browse` (manage_options).

## Config keys

`host` (default 127.0.0.1) · `port` (6379) · `database` (0) · `username`/`password` (null) · `prefix` (arclight) · `timeout`/`read_timeout` (1.0s) · `retries` (2) · `backoff_ms` (100, jittered) · `ignored_groups` (memory-only groups) · `split_alloptions` (single-hash options group) · `metrics` · `sqlite_path` (metrics history; defaults to uploads/arclight-cache) · `tls`.

Precedence: constant > dashboard DB option > `ARCLIGHT_CONFIG` env JSON > defaults.

## Tests

Zero-dependency runners, no live Redis needed (fakes included):

```
php tests/run.php    # core: config, keys, cache ops, faults, doctor (60 tests)
php tests/run2.php   # connector, fallback, QM bridge, audits (20 tests)
php tests/run3.php   # metrics history, SCAN/inspect, sanitizer (23 tests)
```

## Layout

```
arclight-cache.php  plugin entry (version guard, boot, CLI registration)
bootstrap.php       autoloader, config resolution (constant>db>env>filter)
api.php             arclight_cache()/arclight_flush()/arclight_info() shims
src/Config          validated Configuration value object
src/Client          ClientInterface, PhpRedisClient, ArrayFakeClient
src/Connection      ConnectionInterface, SingleConnection (backoff+jitter), Connector
src/Cache           ArclightObjectCache (two-tier), ArrayObjectCache, KeyBuilder
src/Support         loggers, metrics stores, SQLite MetricsHistory
src/Tools/Doctor    single health report
src/Console         wp arclight commands
src/Bridge          Query Monitor collector
stubs/object-cache.php  drop-in (wp_cache_init contract, shutdown sampler)
server/*.lua        SCAN helpers
specs/              capabilities, architecture, config schema, test plan, deltas
```

## Notes

- `PhpRedisClient::scan()` uses `rawCommand('SCAN', …)` deliberately: native `Redis::scan()` misbehaves on some builds (observed: phpredis 6.3.0 returns false, iterator stuck).
- Metrics are sampled per request into SQLite on shutdown; the graph reads 5-minute buckets. Server `INFO stats` counters are captured when available.
- WooCommerce: catalog/product/order groups persist; verify `wc_session_id` isolation per site via unique prefix/database. HPOS (`orders_data`/`orders`) supported through generic group persistence; targeted invalidation hooks are on the roadmap.
