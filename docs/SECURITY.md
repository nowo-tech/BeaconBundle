# Security Policy

## Table of contents

- [Security considerations for integrators](#security-considerations-for-integrators)
- [Attack surface and threat model](#attack-surface-and-threat-model)
- [Bundle security measures](#bundle-security-measures)
- [Remote Messenger commands (1.16+)](#remote-messenger-commands-116)
- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Security considerations for integrators

`nowo-tech/beacon-bundle` sends application events to a Symfony Beacon server over HTTP(S). It is safe to install in production, but only when the following basics are respected:

- **Do not commit real DSNs**. A DSN contains a public key **and a required secret** (`https://PUBLIC:SECRET@host/project`). Treat the full DSN as sensitive configuration and keep it in env vars or your secret manager.
- **Keep `verify_peer: true` in production**. Disabling TLS verification is acceptable only for local development against self-signed certificates.
- **Review the exception listener scope**. With `register_error_listener: true`, uncaught HTTP exceptions are reported automatically. If a class should never be reported, add it to `ignore_exceptions`.
- **Use least-privilege Beacon projects**. Generate DSNs per environment or per application when possible so rotation and incident response stay simple.
- **Expect HTTP 429 under load**. Symfony Beacon rate-limits ingest per project (`Retry-After`). The bundle logs the limit and does not auto-retry.

## Attack surface and threat model

| Asset / surface | What can go wrong | Main mitigation |
|-----------------|-------------------|-----------------|
| `BEACON_DSN` / `nowo_beacon.dsn` | DSN leaked in git, screenshots, logs, CI output, or copied into demo config | Keep DSNs in env vars, do not commit real values, rotate keys if exposed |
| DSN secret | Secret embedded in DSN copied into public docs or shell history | Prefer secret storage, avoid pasting secrets in shared terminals or examples |
| TLS to Beacon ingest | MITM or certificate spoofing if `verify_peer` is disabled in production | Leave `verify_peer: true` outside local self-signed dev setups |
| `kernel.exception` listener | Uncaught exceptions may include sensitive messages or request context if your app throws them | Review exception messages, use `ignore_exceptions` for known noisy/sensitive classes, disable listener if your app wants manual reporting only |
| Outbound HTTP call | Slow or unavailable Beacon server can delay the request path | Keep `timeout` low; use `transport.mode: async` or `messenger`; the transport has no retry loop |
| Ingest rate limit | Sustained spikes may receive HTTP 429 | Back off using `Retry-After`; fix noisy reporters; raise `BEACON_INGEST_RATE_LIMIT` only on the server if appropriate |
| `BEACON_WORKER_KEY` (remote Messenger commands, opt-in) | Leaked key lets an attacker claim (steal) this project's commands or post fake results; with a TLS interception position it could also forge commands (HMAC signing key is derived from the same key) | Keep it only on worker hosts, rotate / revoke in Beacon (10-minute overlap); `allowed_actions: [retry]` forbids remote discards; see [Remote Messenger commands](#remote-messenger-commands-116) |
| Dependency supply chain | Vulnerable Composer dependency included in the release | Run `composer audit` before release and triage findings |

## Bundle security measures

The bundle already includes several defensive defaults:

- **Disabled by empty DSN**: if `dsn` is empty, the bundle injects `NullBeaconClient` and does not send anything.
- **Secret required**: incomplete DSNs (public key only) fail at parse time instead of sending unauthenticated envelopes that Beacon would reject with 403.
- **Dual auth on the wire**: `X-Beacon-Auth` (key + secret) plus the full DSN in the envelope header.
- **TLS verification on by default**: `verify_peer` defaults to `true`.
- **Short-circuit ignore list**: the exception listener skips classes listed in `ignore_exceptions`.
- **No DSN echo in API responses**: the demo/status guidance exposes only `has_dsn`, not the DSN value itself.
- **Bounded network call**: transport uses Symfony HttpClient with `timeout` and `max_duration`.
- **Transport failures are not fatal**: rejected envelopes or TLS/connection errors are logged and return `false` from transport instead of crashing the app path.

Residual risk to remember:

- The client can generate a local event id even if delivery later fails. Treat the Beacon UI or server logs as the source of truth for accepted events.
- When `send.request` is true, events and transactions include the current HTTP URL/method/query and a small allow-list of headers (Host, User-Agent, … — not cookies or Authorization). Query parameters with secret-like names (`token`, `password`, `api_key`, `secret`, `auth`, …), URL credential parameters (`hash` from Symfony login links, `_hash`, `signature`, `sig`, OAuth `code`) and URL userinfo are replaced with `[Filtered]` in the URL, query string, Referer and `extra.request_uri`; other values (e.g. `?email=`, `?state=`) are sent as-is, so still avoid secrets in URLs or disable `send.request` if URLs are sensitive.
- `send.user` is off by default. Enabling it may transmit personal data (user identifier / email) to your Beacon host; keep it aligned with your privacy policy and legal pages.
- `send.stack_vars` is off by default. Enabling it attaches function arguments to stack frames (`vars`); treat that like other PII and keep redaction/`before_send` in mind.

## Remote Messenger commands (1.16+)

`messenger.remote_retry` (off by default) lets a Beacon project admin retry or discard **one** failed Messenger message. Threat notes for the client side (Symfony Beacon spec 154 owns the server side):

| Threat | Client-side controls |
|---|---|
| Inbound exposure / SSRF | **Pull only**: workers poll Beacon over outbound HTTPS; the bundle registers no route and opens no port. Beacon never connects to the app. |
| Forged command (database-only Beacon compromise) | Every command is signed (`v1=` HMAC-SHA256 over id, project, action, transport, message id, issue and expiry times) with a subkey derived by HKDF from the worker key; checked with `hash_equals` before anything else and refused as `signature_invalid`. The project must match the DSN project. |
| Replay of a captured command | Commands expire after 15 minutes (60 s clock skew tolerated); executed command ids are kept 24 h in `cache.app` and never executed twice (the stored result is re-posted). Beacon hands a claimed command out only once (*at most once*). |
| Full Beacon compromise (accepted residual risk) | Bounded to retry / discard of messages already in an allow-listed failure transport (`failure_transports`, Doctrine / Redis only) with an allow-listed action (`allowed_actions`). No payload edits, no arbitrary commands, no handler runs in the poller; one switch (`enabled: false`) turns it off. |
| Leaked worker key | Scope = the two command endpoints of one project (no issue data, no ingest). Results are tied to a per-claim token. Rotate / revoke in Beacon. A leaked ingest DSN does **not** grant this. HMAC means a leaked key **plus** a man-in-the-middle position could forge commands; Ed25519 signatures with a pinned Beacon public key are a recorded future improvement. |
| Message content disclosure | Message bodies are never sent in either direction; results carry reason codes and the exception **class** only; logs carry ids and codes — never the key, claim tokens or envelopes. The key is redacted from `var_dump()` / exceptions (`#[SensitiveParameter]`). |
| Abuse / DoS against the worker | One command per claim, 3-second HTTP timeout, polling between messages only, exponential backoff, `Retry-After`, one-hour pause on `401` / `403` / `404`; nothing throws into the worker. |

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
| **Timeouts / DoS** | `timeout` stays documented and intentionally low; no hidden retry loop was introduced. |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (good)** / risk **Low** (2026-07-28). Recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |
| **Release notes** | Security-relevant changes are reflected in `CHANGELOG.md` and `UPGRADING.md` when needed. |

Recommended commands:

```bash
composer audit
make release-check
```

## AI security audit

| Field | Value |
| ----- | ----- |
| Date | 2026-07-28 |
| Grade | Pass (good) |
| Risk | Low |
| Method | Cursor security-review / campaign static pass (`src/`, recipe, demo, SECURITY docs) |
| Open residuals | None Critical/High. App-owned: DSN secret hygiene; `send.request` URL/query sensitivity; `send.user` PII when enabled. |
