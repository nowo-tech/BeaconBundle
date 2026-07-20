<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Envelope;

/**
 * Per-category switches for outbound event context.
 *
 * Defaults favour diagnostics; {@see $user} stays opt-in because it may include PII.
 */
final readonly class SendOptions
{
    public function __construct(
        public bool $environment = true,
        public bool $release = true,
        public bool $serverName = true,
        public bool $stacktrace = true,
        public bool $request = true,
        public bool $user = false,
        public bool $runtime = true,
        public bool $framework = true,
        public bool $os = true,
    ) {
    }

    /**
     * Build SendOptions from the `nowo_beacon.send` config array.
     *
     * @param array{
     *     environment?: bool,
     *     release?: bool,
     *     server_name?: bool,
     *     stacktrace?: bool,
     *     request?: bool,
     *     user?: bool,
     *     runtime?: bool,
     *     framework?: bool,
     *     os?: bool
     * } $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            environment: (bool) ($config['environment'] ?? true),
            release: (bool) ($config['release'] ?? true),
            serverName: (bool) ($config['server_name'] ?? true),
            stacktrace: (bool) ($config['stacktrace'] ?? true),
            request: (bool) ($config['request'] ?? true),
            user: (bool) ($config['user'] ?? false),
            runtime: (bool) ($config['runtime'] ?? true),
            framework: (bool) ($config['framework'] ?? true),
            os: (bool) ($config['os'] ?? true),
        );
    }
}
