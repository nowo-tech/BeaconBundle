<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Bridge\InstanceSettings;

use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Psr\Log\LoggerInterface;

use function implode;

/**
 * After the `beacon` section was saved (admin form, import, API or the rotate command): the credentials cache of this
 * process expires at once, and one notice names the changed fields and the origin (never a value). Other processes
 * (workers) pick the change up within `credentials_cache_ttl`.
 */
final class InstanceSettingsCredentialsListener
{
    public function __construct(
        private readonly BeaconCredentialsResolver $resolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(SettingsChangedEvent $event): void
    {
        if ($event->section !== BeaconSettingsSection::NAME) {
            return;
        }

        $this->resolver->invalidate();
        $this->logger?->notice('Beacon credentials changed in instance settings (fields: {fields}; origin: {origin}).', [
            'fields'                            => implode(', ', $event->changedFields),
            'origin'                            => $event->origin,
            EnvelopeTransport::LOG_CONTEXT_FLAG => true,
        ]);
    }
}
