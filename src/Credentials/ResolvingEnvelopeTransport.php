<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Credentials;

use Closure;
use LogicException;
use Nowo\BeaconBundle\Client\BeaconClientFactory;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Envelope\EnvelopeTransportInterface;

/**
 * Synchronous Envelope transport that follows the effective credentials: the Messenger handler of
 * `transport.mode: messenger` POSTs queued bodies with the DSN valid when the message is handled, not the one the
 * worker started with. The transport of one generation is rebuilt when the credentials change. No usable DSN: the body
 * is dropped (send() returns false).
 *
 * @internal built by {@see BeaconClientFactory} when a credentials provider is configured
 */
final class ResolvingEnvelopeTransport implements EnvelopeTransportInterface
{
    private ?RotatingEnvelopeTransport $inner = null;

    private ?int $innerGeneration = null;

    /**
     * @param Closure(ResolvedBeaconCredentials, BeaconDsn): RotatingEnvelopeTransport $build
     */
    public function __construct(
        private readonly BeaconCredentialsResolver $resolver,
        private readonly Closure $build,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $envelopeBody): bool
    {
        $inner = $this->inner();

        return $inner instanceof RotatingEnvelopeTransport && $inner->send($envelopeBody);
    }

    /**
     * {@inheritdoc}
     *
     * @throws LogicException when no DSN is usable
     */
    public function getDsn(): BeaconDsn
    {
        $inner = $this->inner();
        if (!$inner instanceof RotatingEnvelopeTransport) {
            throw new LogicException('No usable Beacon DSN (see nowo:beacon:test).');
        }

        return $inner->getDsn();
    }

    private function inner(): ?RotatingEnvelopeTransport
    {
        $credentials = $this->resolver->resolve();
        if ($this->innerGeneration === $credentials->generation) {
            return $this->inner;
        }

        $dsn = $credentials->dsn();
        // @igor-ignore - Transport of the current credentials generation (configuration / provider values, no request data).
        $this->inner = $dsn instanceof BeaconDsn ? ($this->build)($credentials, $dsn) : null;
        // @igor-ignore - Credentials generation of the inner transport.
        $this->innerGeneration = $credentials->generation;

        return $this->inner;
    }
}
