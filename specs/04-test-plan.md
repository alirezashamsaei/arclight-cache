# Arclight Cache — 04 Test Plan

## Runner
`tests/run.php` — zero-dependency TAP-ish runner (no composer on build machine).
`php tests/run.php` exit 0 = green. Each file in `tests/` returns `[name, closure]` cases.

## Matrix
- Unit/Config: defaults applied; each invalid field yields exactly its error;
  unknown keys preserved; env JSON merge assoc-replace/list-replace; bad JSON ignored.
- Unit/KeyBuilder: `prefix:site:group:key`, max key sanitation, binary-safe, empty key rejected.
- Unit/Groups: ignored groups never reach client; non-persistent groups memory-only.
- Unit/AlloptionsSplit: designated group reads/writes single hash via hGet/hSet; normal groups use get/set.
- Integration/Fake: add/get/set/replace/delete/incr/decr/exists/get_multiple vs ArrayFakeClient — 100% pass, 0 skipped.
- Multisite: site prefix isolation; flushSite keeps other site; flushNetwork clears all.
- Fault-injection: dial timeout → fallback + 1 warning; backoff attempts = 1+retries; AUTH fail → ConnectionException; recovery on next ensure().
- Serialization guards: closure → refuse (return false + warning); resource → refuse; WP_Error-like object round-trips.
- Metrics/SQLite: record 100 ops → rollup hits/misses/p50; persist to sqlite in temp dir; unwritable path → drop + warn once.
- Doctor JSON: against fake with bad auth → status fail + checks[].name/ok/detail; `--show-secrets` gate hides password.
- Compat audits (run in CI, asserted by runner): no `fn(`, `?->`, `match(`, `enum `, typed props in src/stubs/api; namespace `ArclightCache\` only.

## Pass criteria
All suites green, 0 skipped, `php -l` clean on every php file, audits clean.
