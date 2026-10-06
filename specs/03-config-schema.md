# Arclight Cache — 03 Config Schema

`ARCLIGHT_CACHE_CONFIG` (PHP constant, array). Env `ARCLIGHT_CONFIG` (JSON) merges under it. Filter `arclight_cache_config` overrides last.

## Keys, defaults, validation
- `host` string default `127.0.0.1`. Non-empty string. `tcp://` prefix stripped.
- `port` int default `6379`. 1–65535.
- `database` int default `0`. 0–15 (warn if >15, allow to 31).
- `username` string|null default `null`. Sent as AUTH username when set.
- `password` string|null default `null`. Secret; never exposed in doctor JSON unless `--show-secrets`.
- `prefix` string default `arclight`. `[A-Za-z0-9_-]{1,32}`. Multisite appends `:site{ID}` automatically.
- `timeout` float default `1.0`. 0.05–10.0 seconds (connect).
- `read_timeout` float default `1.0`. 0.05–10.0 seconds.
- `retries` int default `2`. 0–5 extra attempts after first dial.
- `backoff_ms` int default `100`. 0–2000 base; exponential with full jitter.
- `ignored_groups` string[] default `['counts','plugins']`-ish minimal. Must be strings.
- `split_alloptions` bool default `true`. Stores designated hot group in one hash.
- `metrics` bool default `true`. In-process sampling on/off.
- `metrics_sample_rate` float default `1.0`. 0.0–1.0 (v1 honors 0/1, reserves fractional).
- `sqlite_path` string|null default `{uploads}/arclight-cache/metrics.sqlite`. Null disables persistence.
- `tls` bool default `false`. When true, requires `tls://` dial and verifies peer (fail closed).

## Validation semantics
- `Configuration::fromArray($arr)` normalizes + `validate(): string[]` returns field-level messages, empty = valid.
- `enable --dry-run` and drop-in boot both call `validate()`; drop-in refuses persistent mode on errors (falls back, logs errors once).
- Env JSON merge: assoc keys deep-replace, list keys replace wholesale. Invalid JSON → warning + ignore env.
- Unknown keys: kept, ignored, surfaced in `doctor` as `unknown_keys[]` warning (forward-compat, typo detection).
