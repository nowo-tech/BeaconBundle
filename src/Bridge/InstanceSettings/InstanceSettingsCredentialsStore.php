<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Bridge\InstanceSettings;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsStoreInterface;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

use function trim;

/**
 * Beacon credentials kept in the `beacon` instance settings section ({@see BeaconSettingsSection}).
 *
 * Reads decrypt the secrets through InstanceSettings (its errors propagate: the resolver falls back to the
 * configuration and logs the exception class once). Expiries are stored as ISO 8601 (`2026-11-13T10:00:00+00:00`); any
 * other text counts as no expiry, so the previous value is ignored.
 */
final class InstanceSettingsCredentialsStore implements BeaconCredentialsStoreInterface
{
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
    ) {
    }

    public function current(): ?BeaconCredentials
    {
        $section     = BeaconSettingsSection::NAME . '.';
        $credentials = new BeaconCredentials(
            $this->settings->getSecret($section . 'dsn'),
            $this->settings->getSecret($section . 'worker_key'),
            $this->settings->getSecret($section . 'previous_dsn'),
            $this->date($this->settings->getString($section . 'previous_dsn_expires_at')),
            $this->settings->getSecret($section . 'previous_worker_key'),
            $this->date($this->settings->getString($section . 'previous_worker_key_expires_at')),
        );

        return $credentials->isEmpty() ? null : $credentials;
    }

    public function save(BeaconCredentials $credentials): void
    {
        $this->settings->save(BeaconSettingsSection::NAME, [
            'dsn'                            => $credentials->dsn(),
            'worker_key'                     => $credentials->workerKey(),
            'previous_dsn'                   => $credentials->previousDsn(),
            'previous_dsn_expires_at'        => $credentials->previousDsnExpiresAt()?->format(DateTimeInterface::ATOM),
            'previous_worker_key'            => $credentials->previousWorkerKey(),
            'previous_worker_key_expires_at' => $credentials->previousWorkerKeyExpiresAt()?->format(DateTimeInterface::ATOM),
        ], SettingsChangedEvent::ORIGIN_API);
    }

    /**
     * Strict ISO 8601 (`DateTimeInterface::ATOM`); relative strings such as "+1 day" are refused (they never expire).
     */
    private function date(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);

        return $date instanceof DateTimeImmutable && $date->format(DateTimeInterface::ATOM) === $value ? $date : null;
    }
}
