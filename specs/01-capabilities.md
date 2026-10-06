# Arclight Cache — 01 Capabilities

Original spec. No reference code transcribed.

## 1. Purpose
Persistent Redis-backed WordPress object cache with graceful fallback,
operational tooling, and optional admin observability.

## 2. Functional capabilities (v1)
- `WP_Object_Cache` drop-in: `add/get/set/replace/delete/incr/decr/flush`,
  `get_multiple/set_multiple/delete_multiple`, non-persistent + ignored groups.
- Two-tier: in-memory per-request array + Redis. In-memory always on.
- Multisite: per-site + per-network key prefixes, `flush_site` vs `flush_network`.
- `alloptions`-style hot-key split: single-hash storage for designated group.
- Batched prefetch: `prefetch( $keys, $group )` pipelines N gets in one round trip.
- Graceful degradation: Redis down/timeout → non-persistent mode, one warning,
  auto-recover next request. Never fatal a page load.
- Serialization guards: refuse/reject closures, resources; round-trip `WP_Error`-like
  objects safely via `maybe_serialize` rules; self-test covers this on enable.
- Config: PHP constant array + env JSON merge + filter override. Validated at
  enable time with field-level errors.
- WP-CLI: `wp arclight enable|disable|flush|info|groups|doctor|digest`
  (`doctor` is the single health report; `--format=json` supported).
- Doctor checks: TCP connect, AUTH, SELECT db, round-trip latency p50,
  `INFO` memory/eviction (`maxmemory-policy`, fragmentation), TLS flag,
  extension version floor, prefix-collision probe.
- Admin (v1): Dashboard (status, hit/miss, latency, memory), Tools
  (flush, group browser, latency probe, slowlog tail), At-a-Glance widget,
  Site Health section, Query Monitor collector (hits/misses/commands).
- Analytics: hit/miss + latency sampled in-process, rolled up to SQLite
  (uploads dir), never stored in Redis itself.

## 3. Non-functional
- PHP 7.2+ compatible syntax. Tested on PHP 8.4 CLI here.
- No fatals when `Redis` extension missing (falls back + nags in admin).
- All Redis I/O behind `ClientInterface`; all topologies behind
  `ConnectionInterface`. Fakes for every test; no live Redis required for suite.
- Test-gated: unit + integration (fake) + fault-injection + doctor JSON
  must be green before any phase ships.

## 4. Out of scope (v1)
- Replica/sentinel/cluster topologies (interfaces reserved, fakes only).
- Relay extension client (interface reserved).
- OpenTelemetry exporter (no-op hook reserved).
