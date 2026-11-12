<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Client;

use Closure;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Credentials\ResolvedBeaconCredentials;
use Nowo\BeaconBundle\Scope\Scope;
use Throwable;

/**
 * Beacon client used when `nowo_beacon.credentials_provider` is set: every capture resolves the effective credentials
 * ({@see BeaconCredentialsResolver}, cached for the TTL) and, when they changed, replaces the inner client (and its
 * transport, HTTP options and async queue) built for the previous ones. No usable DSN: captures are dropped, like
 * {@see NullBeaconClient}.
 *
 * Breadcrumbs and tags go to the shared buffers directly (no credentials read). Nothing here holds request data: the
 * inner client is keyed by the credentials generation only.
 */
final class ResolvingBeaconClient implements BeaconClientInterface, QueueSnapshotCapableInterface, DeployCapableInterface, CheckInCapableInterface
{
    private ?BeaconClientInterface $inner = null;

    private ?int $innerGeneration = null;

    /**
     * @param Closure(ResolvedBeaconCredentials): BeaconClientInterface $build builds the client of one generation
     */
    public function __construct(
        private readonly BeaconCredentialsResolver $resolver,
        private readonly Closure $build,
        private readonly ?BreadcrumbBuffer $breadcrumbBuffer = null,
        private readonly ?Scope $scope = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->inner()->isEnabled();
    }

    /**
     * {@inheritdoc}
     */
    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return $this->inner()->captureException($throwable, $extra, $fingerprint);
    }

    /**
     * {@inheritdoc}
     */
    public function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): ?string
    {
        return $this->inner()->captureMessage($message, $level, $extra, $fingerprint);
    }

    /**
     * {@inheritdoc}
     */
    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $this->breadcrumbBuffer?->add($message, $category, $level, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function captureTransaction(string $transactionName, float $startTimestamp, float $endTimestamp, array $spans = [], array $extra = []): ?string
    {
        return $this->inner()->captureTransaction($transactionName, $startTimestamp, $endTimestamp, $spans, $extra);
    }

    /**
     * {@inheritdoc}
     */
    public function captureQueueSnapshot(array $transports): ?string
    {
        $inner = $this->inner();

        return $inner instanceof QueueSnapshotCapableInterface ? $inner->captureQueueSnapshot($transports) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function captureDeploy(array $deploy): ?string
    {
        $inner = $this->inner();

        return $inner instanceof DeployCapableInterface ? $inner->captureDeploy($deploy) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function captureScheduleCatalog(array $schedules): ?string
    {
        $inner = $this->inner();

        return $inner instanceof CheckInCapableInterface ? $inner->captureScheduleCatalog($schedules) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function captureCheckIn(array $checkIn): ?string
    {
        $inner = $this->inner();

        return $inner instanceof CheckInCapableInterface ? $inner->captureCheckIn($checkIn) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function setTag(string $key, mixed $value): void
    {
        $this->scope?->setTag($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function setTags(array $tags): void
    {
        $this->scope?->setTags($tags);
    }

    /**
     * {@inheritdoc}
     */
    public function getTags(): array
    {
        return $this->scope?->getTags() ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function clearTags(): void
    {
        $this->scope?->clearTags();
    }

    /**
     * Client of the current credentials generation (rebuilt when they changed; a build failure drops captures).
     */
    private function inner(): BeaconClientInterface
    {
        $credentials = $this->resolver->resolve();
        if ($this->inner instanceof BeaconClientInterface && $this->innerGeneration === $credentials->generation) {
            return $this->inner;
        }

        try {
            $inner = ($this->build)($credentials);
        } catch (Throwable) {
            $inner = new NullBeaconClient();
        }

        // @igor-ignore - Client of the current credentials generation (configuration / provider values, no request data).
        $this->inner = $inner;
        // @igor-ignore - Credentials generation of the inner client.
        $this->innerGeneration = $credentials->generation;

        return $inner;
    }
}
