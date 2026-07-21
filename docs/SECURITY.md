# Security Policy

## Table of contents

- [Security considerations for integrators](#security-considerations-for-integrators)
- [Attack surface and threat model](#attack-surface-and-threat-model)
- [Bundle security measures](#bundle-security-measures)
- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

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
- When `send.request` is true, events and transactions include the current HTTP URL/method/query and a small allow-list of headers (Host, User-Agent, … — not cookies or Authorization). Do not put secrets into URLs. Disable `send.request` if URLs are sensitive.
- `send.user` is off by default. Enabling it may transmit personal data (user identifier / email) to your Beacon host; keep it aligned with your privacy policy and legal pages.

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
| **Release notes** | Security-relevant changes are reflected in `CHANGELOG.md` and `UPGRADING.md` when needed. |

Recommended commands:

```bash
composer audit
make release-check
```
