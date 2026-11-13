# Security Policy

## Table of contents

- [Security considerations for integrators](#security-considerations-for-integrators)
- [Attack surface and threat model](#attack-surface-and-threat-model)
- [Bundle security measures](#bundle-security-measures)
- [Remote Messenger commands (1.16+)](#remote-messenger-commands-116)
- [Stored credentials and rotation (1.25+)](#stored-credentials-and-rotation-125)
- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Security considerations for integrators

`nowo-tech/beacon-bundle` sends application events to a Symfony Beacon server over HTTP(S). It is safe to install in production, but only when the following basics are respected:

- **Do not commit real DSNs**. A DSN contains a public key **and a required secret** (`https://PUBLIC:SECRET@host/project`). Treat the full DSN as sensitive configuration and keep it in env vars or your secret manager.
- **Keep `verify_peer: true` in production**. Disabling TLS verification is acceptable only for local development against self-signed certificates. The bundle passes `verify_peer` / `verify_host` explicitly on every request, so a host-wide `framework.http_client` `verify_peer: false` does not apply to Beacon traffic.
- **Review the exception listener scope**. With `register_error_listener: true`, uncaught HTTP exceptions are reported automatically. If a class should never be reported, add it to `ignore_exceptions`.
- **Use least-privilege Beacon projects**. Generate DSNs per environment or per application when possible so rotation and incident response stay simple.
- **Expect HTTP 429 under load**. Symfony Beacon rate-limits ingest per project (`Retry-After`). The bundle logs the limit and does not retry 4xx answers; only 5xx answers are retried (`transport.retry`, bounded, see [CONFIGURATION](CONFIGURATION.md#retries-on-beacon-server-errors)).
- **Put the final Beacon URL in the DSN**. Redirects are never followed (`max_redirects: 0`), so a DSN pointing at a redirecting URL (e.g. `http://` → `https://`) fails instead of sending the credentials elsewhere.

## Attack surface and threat model

| Asset / surface | What can go wrong | Main mitigation |
|-----------------|-------------------|-----------------|
| `BEACON_DSN` / `nowo_beacon.dsn` | DSN leaked in git, screenshots, logs, CI output, or copied into demo config | Keep DSNs in env vars, do not commit real values, rotate keys if exposed |
| DSN secret | Secret embedded in DSN copied into public docs or shell history | Prefer secret storage, avoid pasting secrets in shared terminals or examples |
| TLS to Beacon ingest | MITM or certificate spoofing if `verify_peer` is disabled in production | Leave `verify_peer: true` outside local self-signed dev setups |
| `kernel.exception` listener | Uncaught exceptions may include sensitive messages or request context if your app throws them | Review exception messages, use `ignore_exceptions` for known noisy/sensitive classes, disable listener if your app wants manual reporting only |
| Outbound HTTP call | Slow or unavailable Beacon server can delay the request path | Keep `timeout` low; use `transport.mode: async` or `messenger`. Retries (5xx only) are bounded: a synchronous send never waits for a `Retry-After` above `max_delay_ms`, and every wait counts against `timeout` |
| Redirect from the Beacon origin (proxy rule, migration, takeover) | Credentials (`X-Beacon-Auth`, the DSN in the Envelope header up to 1.20, the worker key) forwarded to another host | Redirects are never followed (`max_redirects: 0`) on ingest and remote-command requests since 1.19.2 |
| Captured data (exception messages, console input, URLs, Monolog context) | Secrets the application puts in messages or arguments stored in Beacon in clear text | `SensitiveValueRedactor` filters credential-shaped values (`key=value`, `'key' => 'value'`, `"key": "value"`, env-style names such as `DB_PASSWORD`, URL passwords, JWT / bearer / provider keys, `beacon_secret`, `bwk_…`, Luhn-valid card numbers) and sensitive keys (`password`, `pass`, `pwd`, `pin`, `otp`, `cvv`, `card`, `iban`, `cookie`, `session`, `signature`, …). It is best effort (URL-encoded or full-width `=` pairs and non-English key names are not recognised): still avoid secrets in exception messages |
| Ingest rate limit | Sustained spikes may receive HTTP 429 | Back off using `Retry-After`; fix noisy reporters; raise `BEACON_INGEST_RATE_LIMIT` only on the server if appropriate |
| `BEACON_WORKER_KEY` (remote Messenger commands, opt-in) | Leaked key lets an attacker claim (steal) this project's commands or post fake results; with a TLS interception position it could also forge commands (HMAC signing key is derived from the same key) | Keep it only on worker hosts, rotate / revoke in Beacon (10-minute overlap); `allowed_actions: [retry]` forbids remote discards; see [Remote Messenger commands](#remote-messenger-commands-116) |
| Self-test HTTP endpoint (opt-in, `self_test.http.enabled`) | Anyone reaching the path triggers real Beacon events (noise, quota) if the role check is skipped | Explicit CSRF token (fails closed without `security.csrf.token_manager`); `roles` must be non-empty while enabled (configuration error), an empty list denies, roles without SecurityBundle deny |
| Dependency supply chain | Vulnerable Composer dependency included in the release | Run `composer audit` before release and triage findings |

## Bundle security measures

The bundle already includes several defensive defaults:

- **Disabled by empty DSN**: if `dsn` is empty, the bundle injects `NullBeaconClient` and does not send anything.
- **Secret required**: incomplete DSNs (public key only) fail at parse time instead of sending unauthenticated envelopes that Beacon would reject with 401.
- **Secret only in a header**: the DSN secret travels only in `X-Beacon-Auth` (key + secret). Since 1.21 the Envelope body carries no DSN (until 1.20 the envelope header held the full DSN as a second credential).
- **Client-side scrubbing (1.21+)**: event and transaction payloads pass beacon-php's `PayloadScrubber` before `before_send` (sensitive keys in `extra` / `contexts` / `tags` / breadcrumb data, credential patterns and card numbers in strings, credential headers, cookies), on top of the bundle's message / exception / URL / frame-vars redaction.
- **TLS verification on by default and explicit**: `verify_peer` defaults to `true` and is sent with every ingest / remote-command request (with `verify_host`), so host-wide HttpClient defaults cannot weaken it.
- **No redirects**: ingest and remote-command requests never follow a redirect (`max_redirects: 0`); a 3xx is a failed send.
- **Secrets hidden from dumps and traces**: the DSN secret and the worker key are held in `SensitiveParameterValue`s (`BeaconDsn`, `WorkerKey`, `RemoteRetryCredentials`, `BeaconConnectionTester`, `BeaconSelfTest`), so `var_dump()`, `var_export()`, Symfony VarDumper / the profiler's dumps and exception trace arguments never show them, and those objects refuse `serialize()`. DSN parse errors never include the DSN. **Exception (debug only):** with the Symfony profiler enabled, `http_client` is a `TraceableHttpClient`, so the profiler's HttpClient panel (and the stored profile under `var/cache/*/profiler`, "copy as curl") records each ingest request's headers, `X-Beacon-Auth` (key + secret) included. The ingest clients wrap the host's `http_client` on purpose (proxies, scoped options); keep the profiler off outside development, or treat stored profiles like the DSN.
- **Short-circuit ignore list**: the exception listener skips classes listed in `ignore_exceptions`.
- **No DSN echo in API responses**: the demo/status guidance exposes only `has_dsn`, not the DSN value itself.
- **Bounded network call**: transport uses Symfony HttpClient with `timeout` and `max_duration`; retries only on 5xx, bounded as above.
- **Transport failures are not fatal**: rejected envelopes or TLS/connection errors are logged and return `false` from transport instead of crashing the app path.

Residual risk to remember:

- The client can generate a local event id even if delivery later fails. Treat the Beacon UI or server logs as the source of truth for accepted events.
- When `send.request` is true, events and transactions include the current HTTP URL/method/query and a small allow-list of headers (Host, User-Agent, … — not cookies or Authorization). Query parameters with secret-like names (`token`, `password`, `api_key`, `secret`, `auth`, …), URL credential parameters (`hash` from Symfony login links, `_hash`, `signature`, `sig`, OAuth `code`) and URL userinfo are replaced with `[Filtered]` in the URL, query string, Referer and `extra.request_uri`; other values (e.g. `?email=`, `?state=`) are sent as-is, so still avoid secrets in URLs or disable `send.request` if URLs are sensitive.
- `send.user` is off by default. Enabling it may transmit personal data (user identifier / email) to your Beacon host; keep it aligned with your privacy policy and legal pages.
- `send.stack_vars` is off by default. Enabling it attaches function arguments to stack frames (`vars`); treat that like other PII and keep redaction/`before_send` in mind.
- `send.stacktrace` (default on) attaches about five source lines around each frame. Those lines are not redacted: a secret hard-coded next to a throwing line would be sent.
- In `transport.mode: messenger` the Envelope is stored in the Messenger transport until the worker sends it, and in its failure transport after a final failure. Since 1.21 it no longer contains the DSN secret, but it does contain the (redacted) event data: protect those transports like your logs.
- Opt-in paths with less redaction: `messenger.report_retries` sends the root exception message unredacted; `instrumentation.http_client` puts the HttpClient error message (with the full URL) in span / breadcrumb data.

## Remote Messenger commands (1.16+)

`messenger.remote_retry` (off by default) lets a Beacon project admin retry or discard **one** failed Messenger message. Threat notes for the client side (Symfony Beacon spec 154 owns the server side):

| Threat | Client-side controls |
|---|---|
| Inbound exposure / SSRF | **Pull only**: workers poll Beacon over outbound HTTPS; the bundle registers no route and opens no port. Beacon never connects to the app. |
| Forged command (database-only Beacon compromise) | Every command is signed (`v1=` HMAC-SHA256 over id, project, action, transport, message id, issue and expiry times) with a subkey derived by HKDF from the worker key; checked with `hash_equals` before anything else and refused as `signature_invalid`. The project must match the DSN project. |
| Replay of a captured command | Commands expire 15 minutes after they are issued. Beacon enforces it, and since 1.19.2 the client does too: a command issued in the future, valid for longer than 15 minutes, or expiring more than 15 minutes ahead is refused as `expired` (60 s clock skew tolerated on each bound; timestamps must be in Beacon's exact UTC format), so any re-delivered command falls inside the replay window. Executed command ids are kept 24 h in `cache.app` and never executed twice (the stored result is re-posted). Beacon hands a claimed command out only once (*at most once*). |
| Full Beacon compromise (accepted residual risk) | Bounded to retry / discard of messages already in an allow-listed failure transport (`failure_transports`, Doctrine / Redis only) with an allow-listed action (`allowed_actions`). No payload edits, no arbitrary commands, no handler runs in the poller; one switch (`enabled: false`) turns it off. |
| Leaked worker key | Scope = the two command endpoints of one project (no issue data, no ingest). Results are tied to a per-claim token. Rotate / revoke in Beacon. A leaked ingest DSN does **not** grant this. HMAC means a leaked key **plus** a man-in-the-middle position could forge commands; Ed25519 signatures with a pinned Beacon public key are a recorded future improvement. **Residual (L4, needs a Beacon-side change):** the raw `bwk_…` key is the Bearer credential, so whoever sees that header (Beacon access logs, a TLS-terminating proxy, APM recording `Authorization`) can derive the signing subkey; sending the HKDF auth subkey instead requires a coordinated protocol change in Symfony Beacon. |
| Message content disclosure | Message bodies are never sent in either direction; results carry reason codes and the exception **class** only; logs carry ids and codes — never the key, claim tokens or envelopes. The key is held in a `SensitiveParameterValue` (hidden from `var_dump()`, `var_export()`, VarDumper and exception traces; `serialize()` is refused). |
| Abuse / DoS against the worker | One command per claim, 3-second HTTP timeout, no redirects, polling between messages only, exponential backoff, `Retry-After` (clamped 1 … 3600 s), one-hour pause on `401` / `403` / `404`. Every claim, result and pending-result failure, whatever its type (since 1.19.2 also non-HttpClient exceptions from decorated clients), becomes a poll status: nothing throws into the worker. |

## Stored credentials and rotation (1.25+)

Opt-in (`credentials_provider`, `instance_settings_bridge`). Client-side controls:

| Threat | Controls |
|---|---|
| Secret disclosure in logs, traces, dumps, profiler | DSN secrets and worker keys live in `SensitiveParameterValue`s (`BeaconDsn`, `WorkerKey`, `BeaconCredentials`, the resolver's configuration copy); `__debugInfo()` masks them; `serialize()` is refused; constructor and method parameters carrying them are `#[SensitiveParameter]`. Provider exceptions are logged by **class only** (their message may contain a connection string); invalid values by reason only; rotation warnings name the step and the project id, never a key. All these records carry the transport flag, so the Monolog handler never forwards them to Beacon. The rotate command reads new values as hidden input or from stdin (never arguments) and prints origin, project and an 8-character public-key prefix only. |
| Secrets at rest | Not stored by the bundle. With the InstanceSettings bridge, secret fields are encrypted by InstanceSettings / DoctrineEncrypt (key in the environment), never rendered back, exported only sealed; changes dispatch `SettingsChangedEvent` (field names + origin, logged as a notice). |
| Database tampering (write access to the record) | **Since 1.25.1 (owner decision 2026-11-12) a database writer can no longer redirect events outside the allow-list:** a provider DSN (current or previous) wins only when its origin is in `credentials_allowed_origins` or is the origin of `nowo_beacon.dsn` (exact `scheme://host[:port]` comparison); any other is ignored with one warning and the configured DSN is used. With no configured DSN and no list, every provider DSN is refused. Within the allowed origins a writer can still point events at another project of the same Beacon. **Never** can it obtain the configured worker key (audit NS-M1): `BEACON_WORKER_KEY` is used with a provider DSN only when that DSN has the same origin (scheme, host, port) as `nowo_beacon.dsn`; otherwise remote commands are off (one warning) until the record also holds the worker key. The same rule applies to the previous worker key (it may be a former configured key copied by the rotate command). The previous DSN, the only value used automatically after a 401, must target the **same origin and project** as the current one and is ignored without an expiry or once expired. Redirects are never followed. Keep `BEACON_DSN` on the same Beacon and project as the stored DSN. |
| A revoked key accepted forever | Previous values need an expiry and are ignored when it is more than 30 days ahead (e.g. a hand-written 2099), whoever wrote it (audit NS-L1); the rotate command caps `--grace` at 30 days and the bridge shows the expiry fields read-only. **For a compromised key rotate with `--grace=0`, then revoke the key in Beacon.** |
| Credentials copied between instances | The bridge fields are `notPortable()`: never exported nor imported (InstanceSettings >= 1.0, enforced by a composer `conflict`). |
| Provider outage / slow database | Read only after the kernel booted; failures fall back to `BEACON_DSN` (then disabled) and never throw into the request; TTL cache (default 60 s) bounds the reads; a forced re-read after a 401 happens at most every 5 s. |
| Recursion (provider logs → Monolog → Beacon → provider) | Re-entrancy guard: a nested call gets the last snapshot or the configuration. |
| Worker-mode leaks | The cache holds configuration / provider values only (no request or user data) and expires by time. |

## Supported Versions

| Version | Supported |
| ------- | --------- |
| 1.x | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security issue in BeaconBundle:

1. **Do not** open a public GitHub issue for security-sensitive reports.
2. Send details to **[hectorfranco@nowo.tech](mailto:hectorfranco@nowo.tech)**.
3. Include affected version, files, reproduction steps, impact, and a PoC if you have one.
4. We will acknowledge receipt, validate the report, and coordinate a fix and disclosure.

See also [.github/SECURITY.md](../.github/SECURITY.md).

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|-------|
| **`docs/SECURITY.md`** | This document is current and still matches the bundle behavior. |
| **No committed secrets** | No real `BEACON_DSN`, API keys, passwords, or private certificates in tracked files. |
| **Recipe / demo config** | Examples ship only placeholder DSNs; demo config does not embed real credentials. |
| **TLS defaults** | `verify_peer` remains enabled by default and docs clearly limit `false` to local dev. |
| **Exception reporting scope** | `register_error_listener` and `ignore_exceptions` docs match actual behavior. |
| **Logging** | No code path logs full DSNs, secrets, or unrelated request secrets. |
| **Dependencies** | `composer audit` has been run and findings triaged. |
| **Timeouts / DoS** | `timeout` stays documented and intentionally low; retries stay limited to 5xx, `max_retries` and the sync `Retry-After` bound. |
| **AI security audit (REQ-SEC-004)** | Latest independent audit 2026-11-12 (Appendix NS, 1.25.0 credentials from a database): **Ship**, one Medium (NS-M1) fixed before release, overall risk **Low**. The 2026-11-01 full audit (Appendix CH) is **Pass (good) / Low** since 1.19.2. Recorded in `BUNDLES_SECURITY_ANALYSIS.md` in the Nowo monorepo (see [AI security audit](#ai-security-audit)). |
| **Release notes** | Security-relevant changes are reflected in `CHANGELOG.md` and `UPGRADING.md` when needed. |

Recommended commands:

```bash
composer audit
make release-check
```

## AI security audit

| Field | Value |
| ----- | ----- |
| Latest audit date | 2026-11-12 (Appendix NS, 1.25.0 credentials from a database with rotation, audited at `14c07b9`, re-verified at `305bf72`) |
| Grade | **Pass (good)** / Low: Appendix CH (2026-11-01 / 02, full audit, 1.19.2) is the last full audit; deltas JQ (1.21.0, 2026-11-09), KM (1.22.0, 2026-11-10) and NS (1.25.0, 2026-11-12) found no open High or Medium |
| Risk | Low (v1.19.1 and earlier: Medium, F1 credential forwarding on cross-host redirect, fixed in 1.19.2) |
| Method | Independent read-only reviews of `src/`, docs and specs with PoCs (redactor, real HTTP redirects, rogue Beacon simulation, self-test kernel, 12 origin-bypass probes for the credentials provider) |
| Appendix CH (1.19.2) | L1 redactor coverage; L2 result path never throws into the worker; L3 client-side command lifetime; L5 secrets out of dumps / traces / serialization; L6 explicit TLS verification; I5 / I6 docs |
| Appendix NS (1.25.0) | NS-M1 (Medium) fixed: a configured worker key pairs with a database DSN only on the exact configured origin, otherwise remote commands are off. NS-L1 previous-key expiry capped at 30 days; NS-L2 `--grace=0` advice for compromised keys; NS-L3 bridge fields non-portable |
| 1.25.1 / 1.25.2 | 1.25.1 restricts a database DSN to `credentials_allowed_origins` (owner decision 2026-11-12, recorded as an addendum to Appendix NS); 1.25.2 fixes a service instantiation cycle (lazy provider). Neither adds attack surface; no separate audit appendix |
| Open residuals | None Critical/High. L4 (Bearer = root worker key, needs a Beacon-side change); I2 DSN in the Envelope header (protocol, persisted in Messenger transports); I3 unredacted source context; I4 opt-in paths with less redaction; I1 background `Retry-After` bounded by `timeout` only. App-owned: DSN secret hygiene; `send.request` URL/query sensitivity; `send.user` PII when enabled. |
