# Arclight Cache — 02 Architecture

Original design. Layered, interface-first.

```
Config array/env/filter
  -> Configuration (validated, immutable-ish value object)
  -> Connector (builds Client from Configuration)
  -> ClientInterface (PhpRedisClient | ArrayFakeClient | future RelayClient)
  -> ConnectionInterface (SingleConnection | future Replicated/Sentinel/Cluster)
  -> ArclightObjectCache (WP semantics) + ArrayObjectCache (fallback)
  -> WP_Object_Cache drop-in (stubs/object-cache.php)
```

## Interfaces (v1, all in `ArclightCache\` namespace)
- `Config\Configuration` — `fromArray(array): self`, `get($key,$default)`, `validate(): string[] errors`.
- `Client\ClientInterface` — `connect(): void`, `isConnected(): bool`, `call($cmd, array $args)`, `pipeline(array $calls): array`, `info($section=null)`, `close(): void`.
- `Connection\ConnectionInterface` — `client(): ClientInterface`, `ensure(): void` (dial+backoff), `isAvailable(): bool`.
- `Cache\ObjectCacheInterface` — WP methods subset + `prefetch`, `flushSite`, `flushNetwork`, `stats`.
- `Support\LoggerInterface` — `log($level,$msg,array $ctx=[])`.
- `Support\MetricsStoreInterface` — `record($op,$group,$hit,$latencyMs)`, `rollup(): array`, `persist(): void`.

## Request lifecycle
1. Drop-in loads, reads `ARCLIGHT_CACHE_CONFIG`, builds `Configuration`.
2. `SingleConnection->ensure()` dials with backoff+jitter (max 3 attempts).
3. On success: two-tier cache (memory + Redis). On failure: `ArrayObjectCache`,
   single warning via `arclight_log()`, `is_persistent=false`.
4. `get`: memory → Redis → miss. `set`: memory + Redis (fire-and-record latency).
5. End of request: `MetricsStore->persist()` to SQLite (if enabled), no Redis writes.

## Failure modes
- No extension → fallback, admin notice flag.
- Timeout/AUTH/SELECT fail → fallback + `ConnectionException`, counted for backoff.
- Serialization guard trip → store miss + warning, never fatal.
- SQLite unwritable → metrics dropped in-memory, warning once.
