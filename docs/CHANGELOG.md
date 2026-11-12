# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.25.0] - 2026-11-12](#1250---2026-11-12)
- [[1.24.1] - 2026-11-12](#1241---2026-11-12)
- [[1.24.0] - 2026-11-11](#1240---2026-11-11)
- [[1.23.0] - 2026-11-11](#1230---2026-11-11)
- [[1.22.0] - 2026-11-10](#1220---2026-11-10)
- [[1.21.0] - 2026-11-09](#1210---2026-11-09)
- [[1.20.0] - 2026-11-09](#1200---2026-11-09)
- [[1.19.2] - 2026-11-02](#1192---2026-11-02)
- [[1.19.1] - 2026-10-28](#1191---2026-10-28)
- [[1.19.0] - 2026-10-28](#1190---2026-10-28)
- [[1.18.2] - 2026-10-28](#1182---2026-10-28)
- [[1.18.1] - 2026-10-28](#1181---2026-10-28)
- [[1.18.0] - 2026-10-26](#1180---2026-10-26)
- [[1.17.2] - 2026-10-24](#1172---2026-10-24)
- [[1.17.1] - 2026-10-22](#1171---2026-10-22)
- [[1.17.0] - 2026-10-18](#1170---2026-10-18)
- [[1.16.0] - 2026-10-18](#1160---2026-10-18)
- [[1.15.0] - 2026-10-18](#1150---2026-10-18)
- [[1.14.0] - 2026-10-14](#1140---2026-10-14)
- [[1.13.1] - 2026-10-13](#1131---2026-10-13)
- [[1.13.0] - 2026-10-13](#1130---2026-10-13)
- [[1.12.0] - 2026-10-12](#1120---2026-10-12)
- [[1.11.1] - 2026-10-11](#1111---2026-10-11)
- [[1.11.0] - 2026-10-11](#1110---2026-10-11)
- [[1.10.1] - 2026-10-09](#1101---2026-10-09)
- [[1.10.0] - 2026-10-02](#1100---2026-10-02)
- [[1.9.1] - 2026-09-30](#191---2026-09-30)
- [[1.9.0] - 2026-09-30](#190---2026-09-30)
- [[1.8.3] - 2026-09-27](#183---2026-09-27)
- [[1.8.2] - 2026-09-24](#182---2026-09-24)
- [[1.8.1] - 2026-09-03](#181---2026-09-03)
- [[1.8.0] - 2026-08-26](#180---2026-08-26)
- [[1.7.8] - 2026-08-24](#178---2026-08-24)
- [[1.7.7] - 2026-08-20](#177---2026-08-20)
- [[1.7.6] - 2026-08-20](#176---2026-08-20)
- [[1.7.5] - 2026-08-19](#175---2026-08-19)
- [[1.7.4] - 2026-08-18](#174---2026-08-18)
- [[1.7.3] - 2026-08-16](#173---2026-08-16)
- [[1.7.2] - 2026-08-16](#172---2026-08-16)
- [[1.7.0] - 2026-08-12](#170---2026-08-12)
- [[1.6.11] - 2026-07-31](#1611---2026-07-31)
- [[1.6.10] - 2026-07-31](#1610---2026-07-31)
- [[1.6.9] - 2026-07-30](#169---2026-07-30)
- [[1.6.8] - 2026-07-30](#168---2026-07-30)
- [[1.6.7] - 2026-07-29](#167---2026-07-29)
- [[1.6.6] - 2026-07-28](#166---2026-07-28)
- [[1.6.5] - 2026-07-27](#165---2026-07-27)
- [[1.6.4] - 2026-07-27](#164---2026-07-27)
- [[1.6.3] - 2026-07-23](#163---2026-07-23)
- [[1.6.2] - 2026-07-22](#162---2026-07-22)
- [[1.6.1] - 2026-07-21](#161---2026-07-21)
- [[1.6.0] - 2026-07-21](#160---2026-07-21)
- [[1.5.1] - 2026-07-21](#151---2026-07-21)
- [[1.5.0] - 2026-07-21](#150---2026-07-21)
- [[1.4.3] - 2026-07-20](#143---2026-07-20)
- [[1.4.2] - 2026-07-20](#142---2026-07-20)
- [[1.4.1] - 2026-07-20](#141---2026-07-20)
- [[1.4.0] - 2026-07-20](#140---2026-07-20)
- [[1.3.1] - 2026-07-20](#131---2026-07-20)
- [[1.3.0] - 2026-07-20](#130---2026-07-20)
- [[1.2.0] - 2026-07-20](#120---2026-07-20)
- [[1.1.1] - 2026-07-20](#111---2026-07-20)
- [[1.1.0] - 2026-07-20](#110---2026-07-20)
- [[1.0.6] - 2026-07-20](#106---2026-07-20)
- [[1.0.5] - 2026-07-20](#105---2026-07-20)
- [[1.0.4] - 2026-07-20](#104---2026-07-20)
- [[1.0.3] - 2026-07-20](#103---2026-07-20)
- [[1.0.2] - 2026-07-20](#102---2026-07-20)
- [[1.0.1] - 2026-07-20](#101---2026-07-20)
- [[1.0.0] - 2026-07-20](#100---2026-07-20)

## [Unreleased]

## [1.25.0] - 2026-11-12

Next minor: 1.25.0.

### Added

- **Credentials from a database, with rotation** (owner decision 2026-11-10; Beacon request D-20261112-14: "apart from
  .env, if there is a DB record read it, to ease rotation"). Opt-in; without it nothing changes (wiring identical to
  1.24.1, `DefaultWiringEquivalenceTest`).
  - Port `Credentials\BeaconCredentialsProviderInterface::current(): ?BeaconCredentials` and writable
    `BeaconCredentialsStoreInterface`; `BeaconCredentials` is a framework-free value object (DSN, worker key, previous
    DSN / worker key with expiry; secrets in `SensitiveParameterValue`, masked dumps, serialization refused).
  - Options `credentials_provider` (service id, checked at compile time), `credentials_cache_ttl` (default 60 s) and
    `instance_settings_bridge` (the `beacon` InstanceSettings section: secret fields encrypted at rest, write-only
    form, `SettingsChangedEvent` audit notice with field names only; no hard dependency, the types are only used when
    enabled).
  - `Credentials\BeaconCredentialsResolver`: provider value wins over `nowo_beacon.dsn` / `worker_key`; invalid or
    unreadable → configuration → disabled; read only after the first request / command / worker start (boot-time
    errors use the configuration); re-entrant calls never recurse; one warning per failure streak (exception class
    only); TTL cache, expired by `kernel.reset` and `SettingsChangedEvent`, correct without any reset (worker mode).
  - Rotation: on HTTP 401 an Envelope is re-sent once with the previous DSN (same origin and project, not expired),
    else after one re-read of the provider (5 s backoff) when the DSN changed — sync, async (at flush) and Messenger
    handler; remote commands: signatures of the previous worker key accepted until expiry, a 401 claim / result
    retried once with the previous (or re-read) key.
  - Client, transport, async queue and Messenger handler transport are rebuilt when the credentials change
    (`Client\ResolvingBeaconClient`, `Credentials\RotatingEnvelopeTransport`, `Credentials\ResolvingEnvelopeTransport`,
    all `@internal`); `nowo:beacon:test` and the self-test use the effective DSN.
  - Command `nowo:beacon:credentials:rotate` (`--dsn`, `--worker-key`, `--grace`): hidden input or stdin, keeps the
    replaced value as previous, reads it back and sends a test Envelope, restores the old record on failure, never
    prints a secret.

### Security (audit NS on #46, before release)

- **NS-M1 (Medium):** the configured worker key could be sent to a provider DSN on another host (a database writer
  could then sign retry / discard commands). It is now used with a provider DSN only on the configured DSN's origin
  (scheme, host, port), otherwise remote commands are off with one warning; same rule for the previous worker key.
- **NS-L1:** previous values expiring more than 30 days ahead are ignored (the rotate command maximum); the bridge shows
  the expiry fields read-only.
- **NS-L3:** the six bridge fields are `notPortable()` (never exported or imported); composer `conflict` with
  `nowo-tech/instance-settings-bundle` < 1.0.
- **NS-I3:** once the previous DSN is rejected too, it is not tried again until the credentials change.
- Docs (NS-L2, NS-I1, NS-I2): `--grace=0` then revoke for a compromised key; a stored project change re-targets queued
  Messenger Envelopes and 401 re-sends; keep `BEACON_DSN` on the same project.

### Fixed

- `NowoBeaconBundle::boot()` / `shutdown()` accessed an uninitialised container on bundle instances re-created by
  `lint:container` (non-debug kernel), which made `Kernel::shutdown()` fail afterwards.

### Tests

- Resolver (provider null / value / null record / throwing / invalid values, TTL, reset, generation, backoff, previous
  values, re-entrancy, no secret in logs, dumps or serialization), 401 fallbacks in sync, async and Messenger modes,
  remote-command key rotation, rotate command (success, stdin, rollback, failures), InstanceSettings bridge (dev stubs
  of the InstanceSettings types under `tests/Stubs`), compiler pass, wiring.
- `DefaultWiringEquivalenceTest`: container description without provider identical to the one generated from the
  1.24.1 sources.
- `CredentialsProviderIntegrationTest`: DDD host (own plain-PHP adapter) and standard host (InstanceSettings bridge)
  kernels, `lint:container`, and REQ-RUNTIME-002: one kept-alive kernel, no resetter, 1,200 requests alternating two
  projects with a DSN rotation mid-run (401 → previous DSN while Beacon lags), memory flat.

## [1.24.1] - 2026-11-12

Next patch: 1.24.1.

### Fixed

- **Console boundary counter could leak or underflow in long-lived console processes (REQ-RUNTIME-002, audit NM,
  Low).** `BeaconRequestScopeResetListener` counted commands with `++` on `console.command` (4096) and `--` on
  `console.terminate` (-4096). A `console.terminate` listener in between that threw or stopped propagation (or a
  throwing `console.error` listener, so Symfony skipped `console.terminate`) left the depth above zero for the rest of
  the process, so the outermost command never cleared the scope (breadcrumbs, spans, tags, trace id) again; a
  `console.command` listener above 4096 that threw or stopped propagation skipped the `++` while the `--` still ran, so
  a nested command cleared the outer command's scope mid-run. The units of work are now tracked by the new internal
  `Worker\UnitOfWorkFrames`, fed at `PHP_INT_MAX` (audits NM, NL2-v and NL2-v2, same design as
  PageBuilderKitBundle): one frame per console run keyed by the Command AND its input (a command that runs itself
  in-process is two frames), saving the outer Messenger state and restoring it when the frame is removed; a terminate
  removes its own frame and the nested frames above it, a terminate without a frame never removes another run's frame;
  `console.error` marks the frame as failing and a frame still failing (its error listener threw) is dropped at the
  next main request, message, command or worker stop, before the handler writes its own flag. The clears keep their
  priorities. HTTP and Messenger clears were already unconditional. `WorkerMessageSkipEvent` (only dispatched by
  `messenger:failed:retry`) also ends the message in flight. New `reset()` for the bookkeeping only; the service is no
  longer autoconfigured (explicit tags), so it is not tagged `kernel.reset`.

### Tests

- `WorkerScopeIsolationTest`: real console `Application` runs in the kept-alive kernel (throwing terminate listener of
  a nested command, unknown nested command, nested `console.command` stopped above 4096, self-recursive command with a
  throwing error listener, error listener at `PHP_INT_MIN`, throwing `WorkerMessageReceivedEvent` listener inside a
  command, failing command inside a message then worker stop); unit tests for `UnitOfWorkFrames`, skipped console
  events and `reset()`.

## [1.24.0] - 2026-11-11

Next minor: 1.24.0 (the constructor of the internal `BeaconRequestScopeResetListener` changes).

### Security

- **Breadcrumbs, scope tags, spans and the trace id carried over between Messenger messages and long-running
  console commands when the services resetter does not run (REQ-RUNTIME-002).** `BeaconRequestScopeResetListener`
  cleared them only at the main `kernel.request`; the Messenger boundary relied on `kernel.reset`. With the stock
  Symfony kernel and `messenger:consume` without `--no-reset`, the services resetter runs before every request and
  after every message, so a standard host was not affected. Where it does not run (a host that removes, replaces or
  bypasses `services_resetter` or drives `http_kernel` directly, Messenger consumers started with `--no-reset`, and
  long-running console commands), an error captured for message B could carry the breadcrumbs (normalized SQL,
  outbound hosts, application data), tags (for example a user or tenant id) and trace id of message A or of the last
  HTTP request; the data went to the operator's own Beacon project, never to another end user. The listener now also
  clears them at `WorkerMessageReceivedEvent` (priority 4096) and at `WorkerMessageHandledEvent` /
  `WorkerMessageFailedEvent` / `console.terminate` (priority -4096: after the bundle's failure capture (-150),
  Scheduler check-in (-512), message transaction (-1024) and pending flush (-2048), so the event of a failed message
  still carries that message's scope). `console.terminate` clears only when the outermost command ends and no message
  is in flight (depth counted on `console.command` / `console.terminate`), so a command run in-process from another
  command or from a message handler (`Application::doRun()`) never clears the outer unit's scope.
  `ResetInterface` / `kernel.reset` stay. Affected: 1.1.0 – 1.23.0 for any event captured in a `--no-reset` consumer
  or a long-running command (automatic failure capture since 1.4.0).

### Changed

- `BeaconRequestScopeResetListener` (internal, registered by the bundle) takes lazy iterators of
  `IGNORE_ON_UNINITIALIZED_REFERENCE` references to the four scope services and the pending-transport registry: a
  boundary only clears instances that already exist and never builds one. See [UPGRADING](UPGRADING.md#to-1240).
- Tests: `WorkerScopeIsolationTest` boots one kernel and never calls the services resetter: 1,000 iterations of an
  HTTP request (`http_kernel` directly), a succeeding and a failing Messenger message (a `Worker` without
  `ResetServicesListener`, i.e. `--no-reset`), two users, every captured event checked for its own breadcrumbs, tags and
  a fresh trace id, memory margin 4 MiB; plus `console.terminate` and a direct `services_resetter->reset()` test.

## [1.23.0] - 2026-11-11

Versioned ingest paths (Beacon 1.147) for envelopes, OTLP and remote commands, with a safe fallback. See [UPGRADING](UPGRADING.md#to-1230). Audit: Appendix LO and Delta LO, clean.

### Added

- **Versioned ingest paths** (Beacon 1.147, D-20261110-04): Envelopes go to `/api/ingest/v1/{projectId}/envelope/` and
  remote Messenger commands to `/api/ingest/v1/{projectId}/messenger/commands/claim` and `…/{id}/result`.
- **`ingest_paths`** (`auto` | `versioned` | `legacy`, default `auto`). With `auto` a 404 / 405 on the versioned path
  sends the same request once more to the legacy unversioned path (permanent alias on every Beacon) and the answer is
  remembered per Beacon target (origin + project id, same key as beacon-php and beacon-js), so the bundle keeps
  working against Beacon servers older than 1.147. Only a 2xx or a Beacon 400 / 401 / 403 / 413 / 415 / 422 proves a
  route; 408, 429, 5xx, other statuses and network failures teach nothing and are never re-sent by this logic (audit
  LO-M1). A target known as versioned that answers 404 / 405 is forgotten and the request re-sent once to the legacy
  path (self-heal; also covers a remote-command result `not_found` on both paths, LO-L1). Newest answer wins.
- `Ingest\IngestPathResolver` (shared, bounded to 32 targets via `MAX_ENTRIES`; `MAX_ORIGINS` is a deprecated alias).
  Not tagged `kernel.reset` (LO-L4): it holds no request state, and Messenger resets services after each message. Keyed by origin + project id, so a DSN with an unknown project (404 on both paths) never pins the
  versioned path for another project or tenant on the same older Beacon.
- `Ingest\IngestPaths` (URL builder, `provesRoute()`) and `EnvelopeTransport::getEnvelopeUrl()` (the URL the next
  send starts with).
- `AsyncEnvelopeTransport` keeps at most 100 pending requests (`DEFAULT_MAX_PENDING`, as beacon-php): starting one more
  completes the oldest first (LO-L3).

### Changed

- `EnvelopeTransport` builds its request itself instead of through beacon-php's `SymfonyHttpClientTransport` (same
  headers, timeouts, redirect and TLS options; the beacon-php requirement stays `^0.1`).
- `nowo:beacon:test` shows the envelope URL the transport settled on (versioned, or legacy after the fallback).
- Log records of the Envelope transport carry the URL actually used in `url` (versioned or legacy; LO-I2).

## [1.22.0] - 2026-11-10

Target: **1.22.0** (new minor). Additive; the defaults keep today's behaviour. See [UPGRADING](UPGRADING.md#to-1220).

### Added

- **`messenger.trace_middleware`** (DDD P2): `enabled` (default `true`) and `buses` (default `['messenger.bus.default']`). With `enabled: false` the bundle no longer prepends `BeaconTraceMiddleware` to any bus, so messages leave without a `BeaconTraceStamp`. `buses` lists the buses that get the middleware, for hosts with their own command / query / event buses. The middleware service stays registered whenever Messenger is installed, so it can still be listed by hand in a bus `middleware`.

### Documentation

- USAGE "Trace correlation": the `BeaconTraceStamp` wire shape, how to pick the buses or switch the middleware off; the DDD section's known limitations no longer list the hard-wired bus.

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.21.0] - 2026-11-09

Target: **1.21.0** (new minor). The bundle now builds on the framework-free SDK [`nowo-tech/beacon-php`](https://github.com/nowo-tech/beacon-php) `^0.1`. Three owner decisions of 2026-11-09 are intended behaviour changes. What the builder produces is byte-identical to 1.20.0 apart from the header `dsn` (golden envelopes); what is finally sent also differs where the new scrubber or the `user.ip_address` rule below applies. See [UPGRADING](UPGRADING.md#to-1210).

### Changed (intended behaviour changes)

- **The DSN secret is no longer sent in the Envelope body.** The envelope header line is now `{"event_id": …, "sent_at": …}` (beacon-php's default `envelope_dsn: none`); the secret travels only in the `X-Beacon-Auth` header, which Symfony Beacon has always read first (`EnvelopeAuthParser` falls back to the header `dsn` only when the header carries no key). Envelopes queued with `transport.mode: messenger` (and kept in its failure transport) therefore no longer contain the secret either. Before, every envelope carried `"dsn": "https://PUBLIC:SECRET@host/project"`.
- **Client-side scrubbing before `before_send`.** Event and transaction payloads go through beacon-php's `PayloadScrubber` after the builder's own redaction and before `before_send`: sensitive keys (`password`, `token`, `secret`, `auth*`, `session*`, `cookie`, `csrf`, `card*`, `cvv`, `iban`, `ssn`, …) become `[Filtered]` in `extra`, `contexts`, `tags`, breadcrumb `data` and `request.data`; string values have credential patterns (URL userinfo, JWTs, bearer tokens, provider keys, `password=…` pairs) and Luhn-valid card numbers replaced, and integers that are card numbers are filtered; credential request headers and `request.cookies` are filtered; structures deeper than 16 levels become `[Filtered]`. Object values (in `extra`, `contexts`, tags, breadcrumb data) are summarised before the hook: a `Stringable` becomes its string, any other object its class name, so a `DateTimeImmutable`, a `JsonSerializable`, an `ArrayObject` or a public-property object that used to be sent as JSON (`{"date":…,"timezone":…}`, its fields) is now sent as `"DateTimeImmutable"` etc.; pass arrays or strings if you need the data. The string patterns of this pass are beacon-php 0.1's (the 1.19.1 rule set): env-style names (`DB_PASSWORD=…`), camelCase names, URL passwords with an empty user and the other 1.19.2 additions are **not** caught in `extra` / tag / breadcrumb strings (they were not redacted there in 1.20 either). `before_send` never sees what was removed. The bundle's existing redaction (message, exception values, request URL / query string / referer, frame `vars`, console input, Monolog context) is unchanged and still applies first. Deploys, check-ins, schedule catalogs and queue snapshots carry metadata only and are not scrubbed (nor passed to `before_send`), as before.
- **`user.ip_address` is removed unless `send.client` is on.** After `before_send` (as beacon-php's `IpStripper` does for the user), whoever set it: the inherited `Scope::setUser()`, a custom `UserContextProviderInterface` or the hook. The bundle's own providers never set it.
- **PHP-warning fingerprints are redacted.** `BeaconPhpWarningHandler` puts the redacted message (`SensitiveValueRedactor::redactMessage()`) in the `php-warning` fingerprint, because Beacon stores fingerprints as is. Warnings whose message contained a secret now group under a new issue (the old fingerprint held the secret); every other warning keeps its fingerprint.
- **Redirects are never followed** (owner decision, already in place since 1.19.2 and kept through beacon-php's transport, `max_redirects: 0`): a 3xx from the ingest URL is a failed send and `X-Beacon-Auth` never reaches the redirect target. The log message for a 3xx is now `Beacon ingest answered with a redirect, which is not followed. Point BEACON_DSN at the final Beacon URL.` (was the generic `Beacon ingest rejected envelope.`); `nowo:beacon:test` output is unchanged (`HTTP 3xx` rejection).

### Changed (internal, no change on the wire)

- `Envelope\EnvelopeBuilder` delegates to beacon-php's `EnvelopeBuilder` (request through a new internal `SymfonyRequestContextProvider`, `symfony` framework context, Messenger `HandlerFailedException` root cause for the culprit, bundle classes and `src/` hidden from message stack traces). Message, exception values, request URL / query string / referer and frame `vars` are still redacted with the bundle's 1.19.2 rules (`Support\SensitiveValueRedactor`, `Support\FrameVarsSerializer`), which are stricter than beacon-php 0.1's; invalid UTF-8 still throws `RuntimeException`. Constructor and `build*Envelope()` signatures are unchanged.
- `Envelope\EnvelopeTransport` sends through beacon-php's `SymfonyHttpClientTransport` (bundle `User-Agent`, `timeout` / `max_duration`, `verify_peer` / `verify_host` always explicit via the internal `ExplicitTlsHttpClient`) and logs through its `IngestResponseHandler` (same messages and context keys; `LOG_CONTEXT_FLAG` keeps the value `nowo_beacon_transport`). `send()`, `sendDetailed()`, `startRequest()` / `finalizeResponse()` / `finalizeResponseDetailed()` keep their signatures. Two small differences: any exception thrown by the HTTP client (not only `TransportExceptionInterface`) is now logged as `Beacon ingest transport failed.` instead of propagating, and transport error texts (log `exception` context, `TransportResult::getErrorMessage()`) have the DSN secret / auth header value and credential-shaped substrings replaced by `[Filtered]`.
- `Dsn\BeaconDsn` extends `Nowo\Beacon\Dsn\Dsn` (gains `toPublicString()`); the secret stays in a `SensitiveParameterValue`, so dumps, `var_export()` and `serialize()` behave as in 1.19.2. `Dsn\InvalidBeaconDsnException` extends `Nowo\Beacon\Dsn\InvalidDsnException` (still an `InvalidArgumentException`). `BeaconDsnParser` keeps its own rules and messages.
- `Scope\Scope`, `Breadcrumb\BreadcrumbBuffer`, `Trace\TraceIdProvider` and `Instrumentation\SpanBuffer` extend their beacon-php classes and keep Symfony's `ResetInterface` (same rules and limits; `BreadcrumbBuffer::MAX_ITEMS` and `SpanBuffer::MAX_ITEMS` are now public constants). `Scope` inherits `setUser()` / `getUser()` / `setExtra()` / `getExtra()` (a user set there is sent as the event `user` even when `send.user` is off; nothing in the bundle calls them). `Context\UserContextProviderInterface` extends beacon-php's interface; `Context\DatabaseExceptionContext` forwards to beacon-php's (identical rules).
- `Client\BeaconClient` keeps its own pipeline (builder → scrubber → `before_send` → transport, exceptions from the builder still propagate) instead of delegating to beacon-php's `Client`: beacon-php 0.1's client is tied to its own builder and redaction rules, which predate 1.19.2.

### Added

- Dependency `nowo-tech/beacon-php: ^0.1` (pulls `psr/http-client`, `psr/http-factory`, `psr/http-message`, `symfony/polyfill-mbstring`).
- Golden envelope tests: beacon-php's 13 recordings of BeaconBundle 1.19.1 (`tests/Fixtures/Golden/bundle-1.19/`) and 18 recordings of 1.20.0 (`bundle-1.20/`, adding cases for the 1.19.2 redaction rules) must match the new builder byte for byte except the header `dsn`; live parity tests compare environment-dependent envelopes (real source context, runtime / OS / framework contexts, frame vars, current stack, Messenger culprit, array controllers, integer query keys) with a frozen copy of the 1.20.0 builder.

### Documentation

- Ingest status codes corrected: Beacon answers **401** for an unknown or invalid key (including a wrong secret or a public-key-only DSN) and **403** when the project's ingestion is suspended. `GETTING_STARTED.md`, `USAGE.md` (troubleshooting table, new suspended-ingestion row), `SECURITY.md`, `UPGRADING.md` (1.5.0 note) and the Symfony 8 demo said an unknown key gets `403`.

## [1.20.0] - 2026-11-09

Target: **1.20.0** (new options).

### Fixed

- **Monolog handler honours the ignore rules (BB-1).** `BeaconMonologHandler` (`monolog_handler`) captured `context.exception` from every record without applying `nowo_beacon.ignore_exceptions`. Symfony's `ErrorListener` logs every uncaught 4xx at ERROR, so `NotFoundHttpException` and `AccessDeniedHttpException` became Beacon issues even when the exception listener skipped them. The handler now uses the same matcher as the listeners (`Support\IgnoredException`): the main `ignore_exceptions` list always applies, and by default any `HttpExceptionInterface` with a status code below 500 is skipped. 5xx `HttpException`s and every other exception are still sent; records without an exception are unchanged; skipped records still bubble to the other Monolog handlers.

### Added

- `monolog_handler.ignore_http_client_errors` (default `true`): skip `HttpExceptionInterface` with status < 500 in the Monolog handler.
- `monolog_handler.ignore_exceptions` (default `[]`): extra FQCNs skipped by the Monolog handler only, on top of the main `ignore_exceptions`.
- `ignore_http_client_errors` (default `false`): the same 4xx rule for the HTTP exception listener (`BeaconExceptionListener`). Off by default, so the listener keeps its current behaviour.
- `Support\IgnoredException`: the single ignore matcher now shared by the HTTP, console and Messenger listeners and the Monolog handler (`MessengerContext::isIgnored()` delegates to it).

## [1.19.2] - 2026-11-02

Security hardening: fail-closed redaction with bounded cost (camelCase names, quoted JSON, URL passwords, cards), strict remote-command lifetime, explicit TLS verification, secret holders never dumped or serialized. Rotate the DSN secret if your Beacon URL ever redirected. See [UPGRADING](https://github.com/nowo-tech/BeaconBundle/blob/v1.19.2/docs/UPGRADING.md#to-1192).

### Security

Fixes from the independent REQ-SEC-004 audit (Appendix CH, 2026-11-01; Pass (conditional) / Low on `db1ba30`). No High or Critical finding; nothing here changes the ingest, self-test or remote-command contracts with Symfony Beacon.

- **Redactor coverage (L1).** Exception messages, console input, Monolog context and request URLs / query strings now also filter env-style names (`DB_PASSWORD=…`, `AWS_SECRET_ACCESS_KEY=…`), PHP array and JSON pairs (`'password' => '…'`, `"password": "value with spaces"`), `pass` / `passphrase` / `pwd` / `pin` / `otp` / `cvv`, URL passwords containing `/` or `@`, the bundle's own `beacon_secret=…` and `bwk_…` worker keys, camelCase names (`clientSecret`, `dbPassword`, `apiToken`), JSON values with escaped quotes, URL passwords with an empty user (`redis://:…@`), and Luhn-valid card numbers (including 15-digit Amex). Patterns are possessive (no catastrophic backtracking on huge values), and a PCRE failure fails closed: the text becomes `[Filtered: redaction failed]` and the key counts as sensitive, never the original. Name suffixes are bounded (linear cost on repeated names), and free text longer than 64 KB is cut on a UTF-8 boundary before redaction (` [truncated]` appended, the tail dropped). Map keys `pass`, `pwd`, `pin`, `otp`, `cvv`, `card*` (also camelCase: `cardNumber`, `userPin`, `dbPass`), `iban`, `cookie`, `session*`, `signature`, `private*` and query parameters `key`, `reset`, `sid` are filtered too. Names are matched on non-alphanumeric boundaries, so `passenger` or `shipping` are kept.
- **The remote-command poller never throws into the worker (L2).** A non-HttpClient exception on the result POST or on the pending-results flush (a decorated `http_client`, a JSON error) keeps the result pending and reports the poll as unavailable.
- **Client-side command lifetime (L3).** Besides the expiry check, a command issued more than 60 s in the future, valid for more than 15 minutes (+ 60 s), or expiring more than 15 minutes (+ 60 s) ahead is refused as `expired`, as is one issued after it expires or whose timestamps are not in Beacon's exact `Y-m-d\TH:i:s\Z` UTC format (relative strings such as `now` are refused). Symfony Beacon issues commands valid for exactly 900 s, so its commands are unaffected; the 24 h replay cache is unchanged.
- **Secrets out of dumps, traces and serialization (L5).** `BeaconDsn`, `WorkerKey`, `RemoteRetryCredentials` and `BeaconConnectionTester` hold the DSN secret / worker key in `SensitiveParameterValue`s (hidden from `var_dump()`, `var_export()`, VarDumper / profiler, exception trace arguments) and refuse `serialize()`; `BeaconDsnParser::parse()`, `BeaconClientFactory::create()` / `createSyncTransport()` take the DSN as `#[SensitiveParameter]`.
- **TLS verification is explicit (L6).** Ingest and remote-command requests always pass `verify_peer` / `verify_host`, so a host-wide `framework.http_client` `verify_peer: false` no longer weakens Beacon traffic.
- **Docs (I1, I5, I6).** `SECURITY.md` records the audit and corrects the claims about retries, the 15-minute lifetime, "never throws", dump redaction and TLS defaults; CONFIGURATION corrects the background `Retry-After` and worst-case lines; the docblock DSN example is an obvious placeholder. Residual L4 (the raw worker key is the Bearer credential) needs a Beacon-side protocol change and is documented.

### Fixed

- **Container compiles when the bundle is disabled and Messenger is installed.** With `enabled: false`, a literal empty DSN, no DSN, or a `when@test` override that disables the bundle, the build failed with `Invalid middleware: service "Nowo\BeaconBundle\Messenger\BeaconTraceMiddleware" not found`: the middleware was always prepended to `messenger.bus.default`, but its service was only registered when the bundle was enabled. The service is now registered whenever Messenger is installed (the same condition as the prepend), as a pass-through while the bundle is disabled. `%env()%` DSNs are unchanged.

### Security

- **Outbound requests never follow redirects** (`max_redirects: 0`) for Envelope ingest (`EnvelopeTransport`, all transport modes and `nowo:beacon:test`) and remote Messenger commands (`RemoteCommandClient`). Before, a 3xx from the Beacon origin was followed up to 20 times, and Symfony HttpClient only drops `Authorization` / `Cookie` on a cross-host redirect, so `X-Beacon-Auth` (DSN key and secret) and the Envelope body could reach another host. A 3xx is now a failed send (logged; not retried) / an unavailable poll (backoff).

### Fixed

- **Synchronous sends no longer stall on a long `Retry-After`** (REQ-RUNTIME-001): with `transport.mode: sync`, a 5xx answer whose `Retry-After` is above `transport.retry.max_delay_ms` (default 5000 ms) is not retried — the send returns at once and the rejection is logged, instead of holding the request or worker thread for the whole `Retry-After` per retry. A `Retry-After` within the bound is still honoured, and `max_delay_ms: 0` keeps the previous behaviour. `async` and `messenger` sends keep honouring the full `Retry-After`. New private service `nowo.beacon.ingest_http_client.sync`; no new configuration key. See [CONFIGURATION](CONFIGURATION.md#retries-on-beacon-server-errors).

### Changed

- **Self-test routes are `#[Route]` attributes** on `SelfTestController` (REQ-SF-004). They stay opt-in: `BeaconRouteLoader` (`type: nowo_beacon`) imports them only when `self_test.http.enabled` is true and applies `self_test.http.path`; the controller is never autoconfigured, so the host's `routing.controllers` import does not expose them. Route names, paths, methods and the `kind` requirement are unchanged.
- Documentation: Spec Kit manual rewritten, spec-driven development document with scope / non-goals and a `REQ-*` table, baseline spec success criteria and acceptance scenarios, code inventory refreshed (97/97), Cursor Agent settings in CONTRIBUTING, canonical issue templates, worker audit table of contents.
- Demo: PHPUnit 13.4.5 in `demo/symfony8` (CVE-2026-119193, development only).

## [1.19.1] - 2026-10-28

Dependency update

### Changed

- **Dependencies updated** within their constraints: Symfony 7.4 patch releases in the development lock (PHP 8.2 baseline), PHPStan and Rector. No change in the bundle's behaviour or configuration.

## [1.19.0] - 2026-10-28

Self-test endpoint: an empty role list denies (behaviour change). See [UPGRADING](UPGRADING.md#to-1190).

### Security

- **Self-test endpoint: an empty role list denies.** `SelfTestController` skipped the role check when
  `self_test.http.roles` was `[]` (documented as "rely on your firewall"), so anyone reaching the path could trigger
  real Beacon events once they fetched a CSRF token from the GET endpoint. An empty list now denies, and the
  configuration rejects `roles: []` while `self_test.http.enabled` is `true`. To keep firewall-only access, list
  `IS_AUTHENTICATED` (or `PUBLIC_ACCESS`) explicitly.

## [1.18.2] - 2026-10-28

### Security

- **Credentials in exception and log messages are hidden before sending**: the event message and every exception
  value in the chain, and the strings of Monolog context, console input and frame vars, have credential-shaped
  substrings replaced with `[Filtered]` — JWTs, `Bearer` / `Basic` tokens, Stripe / AWS / GitHub / Slack / Google /
  LLM API keys, PEM private keys, URL passwords (`scheme://user:[Filtered]@host`) and `password=…` /
  `"api_key": "…"` pairs. The rest of the message is kept, so the issue title stays readable. Until now only
  secret-looking keys and URLs were filtered, and a token inside an exception message reached Beacon as written.
  New public helper: `SensitiveValueRedactor::redactMessage()`.

## [1.18.1] - 2026-10-28

### Fixed

- **Outbound HTTP tracing broke async decorators** (`instrumentation.http_client`): `TraceableBeaconHttpClient` read
  the status code inside `request()`, so a client wrapping it — `NoPrivateNetworkHttpClient`, `RetryableHttpClient`,
  any `AsyncDecoratorTrait` decorator — failed every request with *"Instance of CurlResponse is already consumed and
  cannot be managed by AsyncResponse. A decorated client should not call any of the response's methods in its
  request() method"*. It is now an async decorator itself: the span and breadcrumb are recorded when the first chunk
  (with the status) or the transport error arrives, and requests are no longer made synchronous by the tracing.
  Found in Symfony Beacon, whose uptime checks (NowoUptimeMonitorBundle behind `NoPrivateNetworkHttpClient`) all
  failed in production.

## [1.18.0] - 2026-10-26

Added: Envelope sends retried with exponential backoff when Beacon answers 5xx (transport.retry) See [UPGRADING](UPGRADING.md#to-1180).

### Added

- **Retries with backoff on Beacon server errors** (`transport.retry`): an Envelope POST answered with 500 / 502 / 503 / 504 is sent again up to `max_retries` times (default 2), waiting `delay_ms` (500) × `multiplier` (2.0)ⁿ between attempts, bounded by `max_delay_ms` (5000) with `jitter` (0.1); `Retry-After` is honoured. 4xx and transport errors are not retried. Applies to the `sync`, `async` and `messenger` transport modes (Symfony `RetryableHttpClient` + `GenericRetryStrategy`, service `nowo.beacon.ingest_http_client`). See [CONFIGURATION](CONFIGURATION.md#retries-on-beacon-server-errors).

## [1.17.2] - 2026-10-24

Fix: no container kept per kernel boot — one process-wide fatal / warning hook, released by the bundle's shutdown(). See [UPGRADING](UPGRADING.md#to-1172).

### Fixed

- A process that boots many kernels (PHPUnit functional tests, any long-running script) no longer keeps every kernel's container alive: the fatal-error handler and the PHP-warning handler each install **one** process-wide hook (`register_shutdown_function` / `set_error_handler` with a static closure) that forwards to the handler of the kernel booted last, and `NowoBeaconBundle::shutdown()` releases it. Before, each boot registered `$this` again (≈65 MB per functional test in a Symfony app). FrankenPHP workers boot once and were not affected.

## [1.17.1] - 2026-10-22

Wave-2 REQ compliance: pinned CI matrix with PHPStan and Igor, full release-check chain, demo login CSRF through the form type. See [UPGRADING](UPGRADING.md#to-1171).

### Changed

- CI: Symfony matrix 7.4 / 8.1 with every `symfony/*` package pinned to the cell minor (7.0 and 8.0 are end of maintenance and their releases carry security advisories; `composer.json` still allows them); the code-style job runs PHPStan and Igor on every push and pull request (REQ-SF-002, REQ-CS-005/006/008).
- `release-check` starts with `ensure-up` and runs the new `check-no-raw-html-form` gate; Rector capped at 2 parallel workers (REQ-MAKE-002).
- Demo login renders its CSRF token through `LoginFormType` (`_csrf_token` / `authenticate`) with the child-row loop instead of a raw hidden `<input>` (REQ-TWIG-005).
- Tests use `#[DataProvider]` instead of a `@dataProvider` docblock (REQ-TEST-010); the inline `@codeCoverageIgnore` markers are justified in the README (REQ-TEST-003).
- Docs: demo troubleshooting (REQ-DEMO-002); why the self-test routes come from the `nowo_beacon` loader (REQ-SF-004).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).

## [1.17.0] - 2026-10-18

### Fixed

- `monolog_handler` no longer forwards the bundle transport's own logs ("Beacon ingest transport failed." / "Beacon ingest rate limited …" / "… authentication rejected" / "… rejected envelope"). While the receiver was down every failure log was captured, queued a new send to the same unreachable receiver, failed and logged again: one endless chain per failure (worst with `transport.mode: messenger`). `EnvelopeTransport` now sets the context flag `EnvelopeTransport::LOG_CONTEXT_FLAG` (`nowo_beacon_transport: true`) on every record it writes and `BeaconMonologHandler` skips flagged records; the other Monolog handlers still receive them. Found in Symfony Beacon, which had to drop these messages in a `before_send` hook.

### Added

- `http_transaction_sample_rate` (0.0–1.0, default `1.0`): fraction of main HTTP requests that send an `auto_http_transaction` transaction, decided at request start.
- `ignore_routes` (default `[]`): route-name prefixes whose `auto_http_transaction` transactions are never sent (e.g. ingest, polling or health routes), in addition to the path-based `ignore_paths`.

## [1.16.0] - 2026-10-18

### Added

- Retry or discard one failed Messenger message from the Beacon UI (Beacon spec 154, bundle spec `specs/008-messenger-remote-retry`), off by default: `nowo_beacon.messenger.remote_retry.{enabled,worker_key,allowed_actions,failure_transports,poll_interval,poll_in_workers}`. Workers poll Beacon over outbound HTTPS (`POST /api/{project}/messenger/commands/claim` and `…/{id}/result`, `Authorization: Bearer bwk_…`, 3 s timeout) between messages; the app exposes no endpoint. Commands are HMAC-SHA256 signed with an HKDF subkey of the worker key, expire after 15 minutes and are executed at most once (24 h executed-ids cache). Retry re-sends the envelope to its original transport without `SentToFailureTransportStamp` / `RedeliveryStamp` and then rejects it from the failure transport; discard rejects it; handlers never run in the poller. Results carry a reason code and the exception class only. Backoff 30 → 600 s, `Retry-After`, one-hour pause on `401` / `403` / `404`.
- `nowo:beacon:messenger-commands [--once] [--time-limit=…]` to poll from cron or one dedicated process (`poll_in_workers: false`).
- Final Messenger failure events carry `extra.messenger.failure_transport` and `extra.messenger.failure_message_id` (the id in the failure transport) when the failure transport can find messages by id (Doctrine, Redis): `RecordingFailureSenderPass` wraps the senders of Symfony's `SendFailedMessageToFailureTransportListener`.
- Every `schedule_catalog` entry carries `description` (string|null, plain text, one line, ≤ 500 chars): `#[BeaconCheckIn(description: …)]`, then `scheduler.check_ins.descriptions` (FQCN or command name), then the console command description (`RunCommandMessage`, lazy commands are not instantiated) or the target method's docblock summary (`ServiceCallMessage`), then the message class docblock summary. Never arguments or payload values. Golden `schedule_catalog.ndjson` updated.
- Contract fixtures `tests/Contract/fixtures/messenger_commands/` (signing vectors, claim / result bodies) shared with Symfony Beacon.
- Demo: Doctrine (MySQL) `async` / `failed` transports, a failing message and `make worker` / `make messenger-commands` for the remote retry flow.

### Changed

- `BeaconMessengerFailedListener` runs at priority **-150** (was 0): after Symfony's failure-transport listener (-100), so the failure-transport id is known; still before the Scheduler check-in (-512) and transaction (-1024) listeners.
- `instrumentation.http_client` also skips Beacon's remote Messenger command calls.

## [1.15.0] - 2026-10-18

### Added

- Symfony Scheduler check-ins (Beacon spec 153, bundle spec `specs/007-scheduler-check-ins`), off by default: `nowo_beacon.scheduler.check_ins.enabled`. A Scheduler worker (`messenger:consume scheduler_<name>`) sends a `schedule_catalog` Envelope item on start (and every `catalog_refresh_hours`) with each recurring task (recurring id, message class, command name for `RunCommandMessage`, trigger kind / expression / time zone / interval / description, next run, max runtime), and one `check_in` item per run (`in_progress`, `ok`, `error`) with a deterministic `run_id`, timestamps, duration, next run and `failure_event_id`. Each item travels in its own Envelope; message payloads and command arguments are never sent. Works with `sync`, `async` (the catalog and `in_progress` are flushed immediately) and `messenger` transport modes.
- `#[BeaconCheckIn(maxRuntime: …, enabled: …)]` attribute and `scheduler.check_ins.max_runtime` / `ignore` / `catalog_refresh_hours` options (the attribute wins over the config).
- `nowo:beacon:scheduler-catalog [--schedule=…] [--dry-run]` command.
- `CheckInCapableInterface` (`captureScheduleCatalog()`, `captureCheckIn()`) on `BeaconClient` / `NullBeaconClient`; custom clients stay compatible.
- `extra.scheduler.run_id` on Messenger failure events of scheduled messages.
- Golden Envelope fixtures `schedule_catalog.ndjson`, `check_in_ok.ndjson`, `check_in_error.ndjson`.
- Demo: `default` schedule with a fast, a slow (`maxRuntime`), a failing and a console-command task (`make scheduler`).

## [1.14.0] - 2026-10-14

### Fixed

- Messenger listeners (final failure, retry / recovered, `messenger.transactions`) no longer report on Beacon's own `SendBeaconEnvelopeMessage`. With `transport.mode: messenger` and `messenger.transactions: true`, each handled send produced a transaction that queued another send, an endless loop; a failed send under `report_retries` did the same. Found in Symfony Beacon (audit SCALE-SELF-INGEST-LOOP), which had to drop these events with a `before_send` hook.

### Added

- `messenger.ignore_message_classes`: message FQCNs (subclasses / interfaces match) the Messenger listeners never report.
- `PERFORMANCE.md`: routing recipe for `transport.mode: messenger` (own transport, `max_retries: 0`, consumed last, `sync://` in tests).

## [1.13.1] - 2026-10-13

### Security

- `send.request`: URL credential parameters are now also redacted in `request.url`, `request.query_string`, the `Referer` header and `extra.request_uri`: `hash` (Symfony login links `?user=…&expires=…&hash=…`), `_hash`, `signature` (incl. `X-Amz-Signature`), `sig` and `code` (OAuth/OIDC authorization callbacks), in addition to the `token`/`password`/`api_key`/… rules. 1.13.0 did not match these names, so login-link hashes and OAuth codes were still sent verbatim. The extra names apply to URLs only; Monolog context, console input and stack vars keep the existing key rules.

## [1.13.0] - 2026-10-13

### Security

- `send.request`: `request.url`, `request.query_string`, the `Referer` header and `extra.request_uri` now redact secret-like query parameters (`token`, `password`, `api_key`, `secret`, `auth`, … — same rules as `SensitiveValueRedactor::redactMap()`) to `[Filtered]`, plus `user:pass@` userinfo. Previously reset/magic-link tokens passed as `?token=` reached the Beacon server verbatim (Symfony login-link `hash` and OAuth `code` parameters were not covered; fixed in Unreleased).
- SQL descriptions (`instrumentation.doctrine` spans/breadcrumbs, `contexts.db`): `SqlNormalizer` now also scrubs numeric literals (`= 123456` → `= ?`, hex included) and double-quoted strings (`"…"` → `"?"`), not only single-quoted strings. Placeholders (`?`, `$1`) and digits inside identifiers are kept.

### Added

- `SensitiveValueRedactor::redactQueryString()` and `SensitiveValueRedactor::redactUrl()`.

## [1.12.0] - 2026-10-12

### Added

- **`nowo:beacon:self-test`**: produces real failures captured by the bundle's own listeners (no hand-built Envelopes) — `uncaught-exception`, `php-error` (uncaught `DivisionByZeroError`), `php-warning`, `captured-exception`, `captured-message`, `messenger-failure`, or `all` returning kinds (`--type` alias, `--run` token). Validates enabled flag / DSN / client first; flushes async transports at the end. Events are tagged `beacon_selftest` / `beacon_selftest_kind`.
- **`Nowo\BeaconBundle\SelfTest\BeaconSelfTest`** public service (`status()`, `assertReady()`, `run()`, `flush()`, kind constants) with `SelfTestResult`, `SelfTestUnavailableException` and `BeaconSelfTestException`.
- **`BeaconSelfTestFailMessage`** + handler (registered only with `symfony/messenger`): route it to an async transport so a worker exercises the Messenger failure listener; synchronous dispatches are captured inline.
- **Optional JSON self-test endpoint** (`self_test.http.enabled`, default `false`; `path` `/_beacon/self-test`; `roles` `['ROLE_ADMIN']`): `GET` status + CSRF token, `POST {path}/{kind}` with CSRF; routes `nowo_beacon_self_test` / `nowo_beacon_self_test_trigger` via the new `nowo_beacon` route loader.
- Spec `006-self-test`.

### Changed

- Dev dependency `symfony/security-csrf`; PHP warning handler tests cover every level label and the fail-soft path.

## [1.11.1] - 2026-10-11

### Fixed

- **Tests:** `EnvelopeBuilderTest::testHandlerFailedCulpritPointsAtRootCause` no longer relies on PHP 8.4+ closure frame names (`{closure:…}`), so it passes on PHP 8.2 / 8.3. No bundle code change.

## [1.11.0] - 2026-10-11

### Added

- **Messenger transactions** (`messenger.transactions`, opt-in): one performance transaction per handled message (`extra.messenger.op = queue.process`, `status` `ok` / `failed` / `retry`, handler, transport, retry count) with the Doctrine / HttpClient spans recorded while handling. Optional `messenger.transaction_sample_rate`.
- **Retry visibility** (`messenger.report_retries` / `messenger.report_recovered`, opt-in): `warning` event for failures Messenger will retry (`retry_state: will_retry`, own fingerprint) and `info` event when a message succeeds after retries (`retry_state: recovered`). Optional `messenger.retry_sample_rate`.
- **Worker memory warning** (`messenger.worker_memory_warning_ratio`, opt-in): one `warning` per worker process when memory reaches the configured fraction of `memory_limit`.
- **`nowo:beacon:messenger-report`**: sends a `queue_snapshot` Envelope item with the message count per Messenger transport and the failure transports (`messenger.failure_transports`, default `['failed']`; `--failure-transport`, `--dry-run`). New `QueueSnapshotCapableInterface` implemented by `BeaconClient` / `NullBeaconClient`.
- **`nowo:beacon:deploy`**: sends a `deploy` Envelope item (release — defaults to `nowo_beacon.release` —, environment, `--commit`, `--url`, `--name`) so Symfony Beacon can show deploy markers and flag issues first seen right after a deploy. New `DeployCapableInterface` implemented by `BeaconClient` / `NullBeaconClient`.
- Golden Envelope fixtures `transaction_messenger.ndjson`, `queue_snapshot.ndjson` and `deploy.ndjson`.
- Specs `004-messenger-observability` and `005-deploy-markers`; baseline spec / code inventory re-audited against 1.11.0.

### Changed

- **Messenger final failures** add `extra.messenger.retry_state` (`exhausted` after retries, otherwise `failed`) and `handler_classes` when more than one handler failed.
- **Culprit** for `HandlerFailedException` is taken from the root cause (application code) instead of Messenger middleware frames.

### Fixed

- **`transport.mode: async` in Messenger workers:** pending envelopes were only flushed on `kernel.terminate` / `console.terminate`, which a long-running `messenger:consume` never reaches until it stops — worker failures (and any event captured while handling a message) stayed queued until the worker restarted. `FlushPendingTransportsListener` now also flushes on `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` and `WorkerStoppedEvent` (after the Beacon listeners).

## [1.10.1] - 2026-10-09

### Fixed

- **CI:** PHPUnit config sets `zend.exception_ignore_args=0` (and the stack-vars test enforces it) so `send.stack_vars` tests pass on GitHub-hosted PHP where exception args are ignored by default.

### Dependencies

- `igor-php/igor-php` require-dev constraint bumped to `^0.10.0`; lock refreshed (Symfony 8.1.8 dev lock, PHPStan 2.3.1, phpstan-phpunit 2.1.1, phpstan-symfony 2.1.0, Rector 2.7.0, `nowo-tech/phpstan-frankenphp` 1.2.3).
- Demo `symfony8`: Symfony 8.1.8, Twig 3.30.0, `symfony/monolog-bundle` 4.1.0, PHPUnit 13.4.1, `nowo-tech/password-toggle-bundle` 2.2.3, `nowo-tech/hot-reload-bundle` 1.5.4.

## [1.10.0] - 2026-10-02

### Added

- **`send.stack_vars`**: opt-in Sentry-compatible `frame.vars` from PHP call arguments (reflection parameter names, size caps, `SensitiveValueRedactor`). Off by default (PII). Requires `send.stacktrace`.

## [1.9.1] - 2026-09-30

### Fixed

- **PHP 8.2 parse error:** drop typed class constant (`const int DEFAULT_LEVELS`) on `BeaconPhpWarningHandler` — typed constants require PHP 8.3+ and broke CI on PHP 8.2.

## [1.9.0] - 2026-09-30

### Added

- **PHP warning interceptor:** `register_php_warning_handler` (default `true`) + `php_warning_handler` options capture PHP warnings/notices as Beacon events (`level: warning` / `info`) **without** converting them to exceptions, so the request keeps running (e.g. undefined array key). Opt out with `register_php_warning_handler: false`. Deprecations are off by default; enable via `php_warning_handler.levels`.

## [1.8.3] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.8.3]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.3

## [1.8.2] - 2026-09-24

### Fixed

- **FrankenPHP worker mode (kernel not reset between requests)** – see [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md):
  - New `BeaconRequestScopeResetListener` resets breadcrumbs, spans, scope tags and the trace id at the start of every main request (and flushes leftover async pending POSTs); `kernel.reset` tags are kept.
  - `BeaconRequestTransactionListener` clears stored Request/timing on every main request (including ignored paths) so timing cannot leak across users.
  - `FlushPendingTransportsListener` terminate priority is `-2048` so auto HTTP transactions enqueue before async flush in the same terminate cycle.
  - `EnvelopeBuilder` source-line cache is bounded (LRU, 64 files).
  - `BeaconFatalErrorHandler` is public and registered from `NowoBeaconBundle::boot()` (`register_fatal_handler` previously had no effect).

### Added

- Audit document `docs/FRANKENPHP-WORKER-AUDIT.md` and Spec Kit requirements FR-WK-001..005.

## [1.8.1] - 2026-09-03

### Fixed

- **CI / PHP 8.2:** regenerate `composer.lock` for PHP 8.2-compatible Symfony 7.4 resolution so Code Style Check can `composer install` on PHP 8.2.
- PHP 8.2-compatible constant usage where needed for CS/install jobs.

### Changed

- **Deps (dev):** refresh Composer lockfiles (includes newer php-cs-fixer).

[1.8.1]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.1
[1.8.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.8.1...v1.8.2

## [1.8.0] - 2026-08-26

### Added

- **`contexts.db`** on captured database exceptions: SQLSTATE, vendor code, driver hint, `sql_mode`, and SQL (quoted literals scrubbed, max 8 KiB). Built from `PDOException::$errorInfo`, duck-typed Doctrine `getSQLState()` / `getQuery()`, and Laravel-style `(SQL: …)` messages. Does **not** require `instrumentation.doctrine`. Symfony Beacon **107** renders this as the Query panel.

### Notes

- Additive Envelope field. Older Beacon servers ignore unknown `contexts` keys.
- No YAML changes.

[1.8.0]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.0

## [1.7.8] - 2026-08-24

### Changed

- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.7.8]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.8

## [1.7.7] - 2026-08-20

### Changed

- **Tests:** extra unit coverage for messenger, fatal errors, snapshots, and redaction (REQ-TEST-003).
- **Internal:** `BeaconFatalErrorHandler::captureFatalError()` is testable without `trigger_error(E_USER_ERROR)` (deprecated in PHP 8.4).

[1.7.7]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.7

## [1.7.6] - 2026-08-20

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.7.5] - 2026-08-19

Improve test coverage for trace, fatal, and console code paths (REQ-TEST-003).

### Changed

- **Tests:** additional unit coverage for Beacon trace/fatal/console handlers (~95% line coverage).

[1.7.5]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.5

## [1.7.4] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.7.4]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.4

## [1.7.3] - 2026-08-16

### Added

- Console command `nowo:beacon:test` probes Symfony Beacon with the configured DSN (sync Envelope POST). Supports `--check-only` (parse/display without sending) and `--message=`.
- `BeaconConnectionTester` + `EnvelopeTransport::sendDetailed()` for structured ingest diagnostics (HTTP status / transport errors without exposing secrets).

### Fixed

- `BeaconDsnParser` no longer uses a typed class constant (`const string`), restoring PHP **8.2** parse compatibility for CI and consumers still on 8.2.

## [1.7.2] - 2026-08-16

### Added

- Console errors include redacted `extra.console.arguments` / `options` / `interactive` (secret-like keys and credential URIs filtered; raw argv still never sent).
- Console extras also include `command_class`, `verbosity`, `cwd`, and `missing_arguments`.
- Messenger failures include `retry_count`, optional `bus` / `transport_message_id`, plus `handler_class`, `transport`, and `first_failure_at`; restores `BeaconTraceStamp` into the active trace.
- Monolog handler nests `extra.monolog.{channel,level}` and redacts `extra.context` keys/values.
- HTTP extras: nested `extra.http` with `route`, `controller`, `status_code`, `query_keys`; optional `client` IP/UA via `send.client` (default false).
- Correlation: `TraceIdProvider` + `extra.trace_id` / tag `trace_id` on every event; `X-Beacon-Trace-Id` request header; Messenger `BeaconTraceMiddleware`.
- Fatal PHP errors: `register_fatal_handler` (default true) shutdown capture with `extra.fatal`.

### Security

- Console/Monolog extras use `SensitiveValueRedactor` so passwords, tokens, DSNs, and private keys are not stored in Beacon.
- Raw argv and Messenger message bodies remain omitted.

## [1.7.0] - 2026-08-12

### Added

- DSN path may be a **canonical project UUID** (e.g. `…/019fea2d-507b-7890-8b33-ca488db6f696`) in addition to legacy positive numeric ids. Envelope URL becomes `/api/{uuid}/envelope/`.

### Changed

- `BeaconDsn::getProjectId()` now returns `string` (numeric ids are returned as digit strings such as `"1"`). Constructor accepts `string|int` for the project id.

## [1.6.11] - 2026-07-31

### Fixed

- `composer.lock`: keep `symfony/scheduler` on **7.4.x** so `composer install` works on PHP 8.2 (CI code-style / matrix). Symfony 8 apps still resolve `^8` via `composer update` on PHP ≥8.4.1.
- `make demo-smoke` / `demo/symfony8` `make up`: install Composer deps via ephemeral `compose run` **before** starting FrankenPHP worker (fresh CI clones have no `vendor/`, so the long-lived `php` service was exiting before `exec composer install`).

## [1.6.10] - 2026-07-31

### Added

- **`include_scheduler_context`** (default `true`): when a final Messenger failure envelope carries Symfony Scheduler `ScheduledStamp`, attach `extra.scheduler` (`schedule_name`, `recurring_id`, `triggered_at`, `trigger`). Message bodies are never attached. Requires `symfony/scheduler` at runtime for the stamp class; without it the flag is a no-op.

### Changed

- Console failure extras are nested under `extra.console` (`command`, `exit_code`, `php_sapi`) instead of flat `extra.console: true` + `extra.command` (see UPGRADING).
- `symfony/scheduler` added as `require-dev` for unit coverage of scheduled-task context.

### Security

- Console listener does **not** send argv / input arguments (credential leakage via cron wrappers).
- Scheduler context omits the scheduled message object payload.

## [1.6.9] - 2026-07-30

### Changed

- Spec Kit baseline (`specs/001-baseline/spec.md`, `code-inventory.md`) aligned with **v1.6.8** `ignore_paths` / FR-LI-005.

## [1.6.8] - 2026-07-30

### Added

- **`ignore_paths`** config for the HTTP exception listener and auto HTTP transactions (`IgnoredRequestPath`). Defaults reuse Beacon host infra exclusions (`/_profiler`, `/_wdt`, `/build`, `/assets`, `/health`) plus Chrome DevTools Appspecific probe `/.well-known/appspecific/com.chrome.devtools.json` (set `ignore_paths: []` to disable).

### Changed

- `auto_http_transaction` skip list is driven by `ignore_paths` (same defaults as before for profiler / WDT / health / build, plus `/assets` and the Chrome DevTools probe).
- README / recipe note `ignore_paths` alongside `ignore_exceptions`.

## [1.6.7] - 2026-07-29

### Changed

- Makefiles prefer `docker compose` (V2) with fallback to `docker-compose` (REQ-MAKE-010).
- Demo Makefile forces compose through the shell (`:;`) so a local `docker/` directory does not break Make’s direct-exec path.
- Optional `-include` of monorepo `update-deps` helpers so standalone CI checkouts do not fail (REQ-MAKE-009).

## [1.6.6] - 2026-07-28

### Added

- **`Psr\Clock\ClockInterface`** on `EnvelopeBuilder` / `BeaconClientFactory` (REQ-DI-001); runtime dep `psr/clock`.
- **`#[AsMessageHandler]`** on `SendBeaconEnvelopeMessageHandler` (REQ-MSG-001).
- **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).

### Changed

- PHPStan: `ignoreErrors: []` (REQ-CS-006).
- `docs/CONFIGURATION.md`: timeout hierarchy section (REQ-RUNTIME-001).
- **REQ-SEC-004**: AI security audit grade **Pass (good)** / risk **Low** recorded in `docs/SECURITY.md` and monorepo analysis (2026-07-28).
- `check-open-prs`: resolve `owner/repo` from `origin` and pass `-R` to `gh` (SSH remotes).

## [1.6.5] - 2026-07-27

### Fixed

- `composer.lock` content-hash refreshed so `composer validate --strict` succeeds after `make composer-sync`
- `make composer-sync` unsets `platform.php` before `composer update --lock` (Composer embeds platform in the lock hash while it is set)

## [1.6.4] - 2026-07-27

### Added

- `make check-open-prs` (REQ-REL-003) wired into `release-check` via `.scripts/check-open-prs.sh`
- Long-doc tables of contents across `docs/` (REQ-DOCS-005)
- Demo / docs: FrankenPHP PHP version policy and outbound HTTP timeout hierarchy (REQ-DEMO-010, REQ-RUNTIME-001)

### Changed

- Symfony 8 demo base image: `dunglas/frankenphp:1-php8.5-alpine` (newest Hub PHP allowed by demo constraints)
- PHPUnit + CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005)
- GitHub About: plain-text description, Packagist website, discoverability topics (REQ-DOCS-018)
- `docs/PERFORMANCE.md`, `docs/DEMO-FRANKENPHP.md`, `docs/RELEASE.md`, `docs/UPGRADING.md` aligned with the above

## [1.6.3] - 2026-07-23

### Added

- FrankenPHP Friendly Worker Mode banner + claim in root README (`docs/images/frankenphp-friendly.png`) after PHPStan FrankenPHP rules (`nowo-tech/phpstan-frankenphp`)
- Maintainer targets: `make down-dev`, `make test-coverage-100` / `make coverage-check` (fail unless PHP coverage is 100%)
- Unit/integration tests covering async/Messenger transports, flush listener, Doctrine SQL middleware, and remaining client/extension branches

### Changed

- PHP line coverage raised to **100%** (README badge + Tests and coverage section)
- Root README: standard badge line (Symfony **7.4 | 8.0 | 8.1+**), canonical `## Documentation` order + `### Additional documentation`, highlighted *Found this useful?* CTA
- Spec Kit baseline (`specs/001-baseline/`): inventory and FRs aligned with **v1.6.x** (`src/` complete)

### Fixed

- Minor internal hardening / import cleanups for coverage and static analysis (no public API or config changes)

## [1.6.2] - 2026-07-22

### Added

- Demo Symfony 8: `FRANKENPHP_MODE` (`worker` default / `classic`) via Compose + `docker/entrypoint.sh` (selects worker Caddyfile vs `Caddyfile.dev` without rebuilding the image)

### Changed

- Docs (`DEMO-FRANKENPHP.md`, demo/README, root README): document classic vs worker switching; recreate containers after changing `.env`

## [1.6.1] - 2026-07-21

### Added

- Golden Envelope **contract fixtures** + tests (`tests/Contract/`) aligned with Symfony Beacon ingest (Phase 3.6)
- `make check-envelope-goldens` to diff fixtures against sibling `symfony-beacon` checkout
- PHPUnit **Contract** testsuite in `phpunit.xml.dist`

## [1.6.0] - 2026-07-21

### Added

- Public tags API on `BeaconClientInterface` (`setTag` / `setTags` / `getTags` / `clearTags`) via request-scoped `Scope` (merged into event/transaction `tags`)
- `before_send` config: service id of an invokable that mutates or drops (`null`) the payload before transport; exceptions fail soft (event dropped)
- Opt-in `instrumentation.doctrine` (Doctrine DBAL middleware) and `instrumentation.http_client` (HttpClient decorator) for SQL / HTTP spans + breadcrumbs
- `SpanBuffer` drains into `captureTransaction()` (works with `auto_http_transaction`)
- `transport.mode`: `sync` (default), `async` (HTTP starts immediately; status finalized on `kernel.terminate` / console terminate), or `messenger` (queue via Symfony Messenger + `SendBeaconEnvelopeMessage`)
- Versioned `User-Agent` from Composer (`beacon-bundle/{pretty-version}` via `ClientUserAgent`)

### Changed

- `BeaconClient` depends on `EnvelopeTransportInterface` (sync / async / messenger implementations)
- Composer `branch-alias` / `root-version` aligned to **1.6.x**

## [1.5.1] - 2026-07-21

### Added

- Demo Symfony 8: `/full-context` (dense breadcrumbs + nested exception + fingerprint + checkout extra), `/messenger-fail`, `/auto-http`, and `app:demo-console-boom`
- Demo depends on `symfony/messenger`; sample `messenger.yaml` + `DemoFailMessage`
- Demo `nowo_beacon.yaml`: `auto_http_transaction`, explicit messenger/console listeners, `server_name`, comments for required DSN secret / `X-Beacon-Auth`

### Changed

- Demo routes send richer `extra` (client auth metadata, HTTP path, PHP runtime, nested checkout samples)
- Demo home / README / USAGE scenario matrix / DEMO-FRANKENPHP aligned with Symfony Beacon ≥ 0.9 and bundle ≥ 1.5 (secret required; `/boom` not `/listener-exception`)
- `/status` reports `has_secret_in_dsn`, `release`, and auth hint

### Fixed

- Restored Composer Symfony constraints to `^7.0 || ^8.0` (a CS Fixer CI job had accidentally committed `^7.4`-only requires, breaking the Symfony 8 demo / `make update-deps`)
- Code-style CI commits only `*.php` changes and uses `composer install` from the Symfony 7.4 lock again (avoids rewriting `composer.json`)

## [1.5.0] - 2026-07-21

### Changed

- **Breaking:** DSN secret is **required**. Format is now `https://PUBLIC:SECRET@host[:port]/projectId`. Public-key-only DSNs are rejected at parse time (Symfony Beacon returns HTTP 403 when the API key has a secret, which all generated keys do).
- Envelope transport sends `X-Beacon-Auth: Beacon beacon_key=…, beacon_secret=…` in addition to embedding the full DSN in the envelope header.
- HTTP 429 responses log `retry_after` when Beacon sends `Retry-After` (default server value: 60 seconds). Auth failures (401/403) get a dedicated warning.
- User-Agent updated to `beacon-bundle/1.5`.
- Composer `branch-alias` / `root-version` aligned to **1.5.x**.

### Security

- Aligns client auth with Symfony Beacon ingest: secret required, dual auth (HTTP header + envelope DSN), clearer handling of rate limits and rejected credentials.

## [1.4.3] - 2026-07-20

### Fixed

- CI / `composer.lock`: keep the lock on Symfony **7.4** so `composer install` works on PHP 8.2 (code-style jobs). `make composer-sync` pins `platform.php` to 8.2 while refreshing the lock; Symfony 8 remains covered by the matrix `composer update` path.

### Changed

- Git hygiene: broader Cursor co-author trailer stripping (`prepare-commit-msg` + `commit-msg`), always-on Cursor rule, and `make setup-hooks` guidance.

## [1.4.2] - 2026-07-20

### Fixed

- Message `stacktrace` attachment no longer drops the entire backtrace when the package path contains `BeaconBundle` (e.g. GitHub Actions checkout `…/BeaconBundle/BeaconBundle/…`).

## [1.4.1] - 2026-07-20

### Added

- Demo Symfony 8: `make sync-beacon` copies `BEACON_DSN` from sibling symfony-beacon `.demo-client.env` (also runs automatically from `make up` when that file exists).
- Demo route `GET /transaction-nplus1`: six similar DB spans so Beacon can show an N+1 group.

### Changed

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): paired local flow with symfony-beacon `make bootstrap` + HTTP ingest on `:9081`.

## [1.4.0] - 2026-07-20

### Added

- `register_messenger_listener` (default `true`): report `WorkerMessageFailedEvent` when the message will not be retried (requires `symfony/messenger`).
- `auto_http_transaction` (default `false`): send a performance transaction for each main HTTP request (skips profiler / WDT / health / build).

## [1.3.1] - 2026-07-20

### Changed

- Baseline spec and code inventory updated for v1.3.x (breadcrumbs, transactions, `send.*`, Monolog wiring, stack source context).
- Clarified that public specs and integrator docs are maintained in English.
- Expanded English PHPDoc on bundle and demo PHP sources (public APIs and non-trivial helpers).

## [1.3.0] - 2026-07-20

### Added

- Stack frames include source context (`pre_context`, `context_line`, `post_context`, `abs_path`) when the file is readable (≈5 lines around the crash site)

### Fixed

- `monolog_handler.enabled` now prepends a `type: service` Monolog handler (the `monolog.handler` tag alone is not wired by MonologBundle).

## [1.2.0] - 2026-07-20

### Added

- `captureMessage()` attaches current PHP stacktrace when `send.stacktrace` is true (BeaconBundle frames filtered out).
- HTTP `request` / `contexts.request` (url, method, query, safe headers such as Host/User-Agent) on events and transactions when `send.request` is true and a request is available.
- Demo: full `send.*`, `release` / `BEACON_RELEASE`, `symfony/monolog-bundle` + Monolog handler enabled, richer `/fingerprint` sample.

### Changed

- Demo and Flex recipe document complete outbound context defaults (`send.*`, console listener, optional Monolog).

## [1.1.1] - 2026-07-20

### Fixed

- Demo Symfony 8: post-login redirect targeted missing route `demo_debug`; now redirects to `demo_user` (`/user`). Removed obsolete `/debug` access-control rule.

### Changed

- Applied PHP-CS-Fixer / Rector cleanups on sources and tests (no public API changes).
- Demo and README docs list 1.1.x routes (breadcrumbs, user, transaction, monolog) and accurate feature bullets.

## [1.1.0] - 2026-07-20

### Added

- Configurable `send.*` switches for outbound context (environment, release, server name, stacktrace, request, user, runtime/PHP, framework/Symfony, OS).
- Event payloads include fractional `timestamp`, ISO-8601 `datetime` (microseconds), and `contexts` (runtime / framework / os).
- Optional authenticated `user` context via Security token storage (`send.user`, default `false`).
- Breadcrumbs via `BeaconClientInterface::addBreadcrumb()` (attached to the next event/transaction, then cleared).
- Performance transactions via `captureTransaction()` (Envelope item `type: transaction`).
- Optional console error listener (`register_console_listener`, default `true`).
- Optional Monolog handler (`monolog_handler.enabled`, requires `monolog/monolog`).
- Demo routes: `/breadcrumbs`, `/user`, `/transaction`, `/monolog` (Symfony 8 sample).

### Changed

- Envelope `sent_at` / event `datetime` use microsecond precision.
- Demo `.env.example` documents HTTP ingest via `host.docker.internal:9081` for local Symfony Beacon.

### Fixed

- `BeaconConsoleErrorListener` constructor defaults so autowiring does not fail when arguments are not yet set.
- Do not autoload `BeaconMonologHandler` unless Monolog’s `AbstractProcessingHandler` is available (avoids 500 in apps without `monolog/monolog`).

## [1.0.6] - 2026-07-20

### Removed

- `demo/symfony7` — only `demo/symfony8` is maintained as the sample app (bundle runtime still supports Symfony 7 via Composer).

### Changed

- Demo aggregate Makefile and docs (`demo/README.md`, Getting started) now document a single FrankenPHP demo on http://localhost:8011.

## [1.0.5] - 2026-07-20

### Fixed

- CI: remove `composer config platform.php 8.4` for Symfony 8 jobs (Composer treats `8.4` as `8.4.0`, which fails Symfony 8.1’s `php >=8.4.1` requirement).

## [1.0.4] - 2026-07-20

### Added

- [Getting started](GETTING_STARTED.md) manual: create a Symfony Beacon project, obtain a DSN, configure BeaconBundle, and verify event collection.
- Runtime `BeaconClientFactory` so empty `%env(BEACON_DSN)%` disables reporting without failing container compilation.

### Fixed

- Regenerate `composer.lock` on Symfony **7.4** so `composer install` works on PHP 8.2 (CI code-style/coverage jobs). Symfony 8 remains supported via the CI matrix `composer update` path.
- Prefer `%env(string:default::BEACON_DSN)%` so empty env values resolve to `""` instead of `null`.

## [1.0.3] - 2026-07-20

### Fixed

- Changelog now reflects restoration of `demo/symfony7` (incorrect Unreleased note from `1.0.1` era removed).

## [1.0.2] - 2026-07-20

### Fixed

- Restored the Symfony 7 demo (`demo/symfony7`) that was accidentally removed in `1.0.1`.

## [1.0.1] - 2026-07-20

### Changed

- Raised minimum requirements to **PHP `>=8.2 <8.6`** and **Symfony `^7.0 || ^8.0`** (dropped PHP 8.1 and Symfony 6.x).
- CI matrix now runs PHP 8.2–8.5 × Symfony 7.0, 7.4, 8.0, and 8.1.

## [1.0.0] - 2026-07-20

### Added

- Initial `nowo-tech/beacon-bundle` release with DSN-based Envelope transport for Symfony Beacon.
- Automatic `kernel.exception` listener with `ignore_exceptions` support.
- Flex recipe and installation defaults centered on `BEACON_DSN`.
- Expanded documentation set for installation, configuration, usage, release, security, performance, Engram, and Spec Kit workflows.
- Demo routes covering message capture, manual exception capture, listener-triggered exceptions, ignored exceptions, fingerprints, and runtime status.

[Unreleased]: https://github.com/nowo-tech/BeaconBundle/compare/v1.25.0...HEAD
[1.13.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.13.0...v1.13.1
[1.13.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.11.1...v1.12.0
[1.11.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.10.1...v1.11.0
[1.10.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.9.1...v1.10.0
[1.9.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.8.3...v1.9.0
[1.7.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.7.0...v1.7.2
[1.6.11]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.10...v1.6.11
[1.6.10]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.9...v1.6.10
[1.6.9]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.8...v1.6.9
[1.6.8]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.7...v1.6.8
[1.6.7]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.6...v1.6.7
[1.6.6]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.5...v1.6.6
[1.6.5]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.4...v1.6.5
[1.6.4]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.3...v1.6.4
[1.6.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.2...v1.6.3
[1.6.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.5.1...v1.6.0
[1.5.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.3...v1.5.0
[1.4.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.0.0
