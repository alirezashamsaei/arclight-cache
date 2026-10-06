# Arclight Cache — 05 Improvements (deltas vs typical commercial reference)

Original analysis. Each item ships test-gated.

1. **Enable-time validation** — field-level errors from `Configuration::validate()`
   surfaced by `enable --dry-run` and drop-in boot. Reference class of tools fails
   late on first cache read. Test: every invalid field yields its message.
2. **Single doctor report** — one `wp arclight doctor` merging connect/AUTH/SELECT,
   latency p50, INFO memory/eviction/fragmentation, TLS, extension floor,
   prefix-collision probe. Replaces scattered health/latency/slowlog surfaces.
   Test: JSON shape + fail case.
3. **SQLite analytics rollups** — measurements persist to SQLite in uploads dir,
   never to Redis (reference-likes pollute eviction working set). Test: 100 ops
   roll up + persist; unwritable → drop once-warn.
4. **Drop-in self-test on enable** — round-trip, closure/resource refusal,
   multisite prefix isolation, alloptions-split check. Test: self-test matrix.
5. **Fault-injecting fakes** — `ArrayFakeClient` modes: timeout, auth-fail,
   flaky-then-recover; backoff attempt counts asserted. Least-tested path,
   most-paged. Test: fault matrix.
6. **OTel-optional spans** — `MetricsStoreInterface` + no-op default; OTel
   exporter optional later per group. No bespoke chart pipeline in core.
7. **Adaptive prefetch + non-blocking flush** — `prefetch()` pipelines N gets;
   Tools UI reports hit-rate delta per batch size. Test: pipeline call counts.
