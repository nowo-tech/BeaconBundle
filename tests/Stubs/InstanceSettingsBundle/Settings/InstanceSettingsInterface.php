<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;

/**
 * Dev stub of nowo-tech/instance-settings-bundle's settings service (the methods the Beacon bridge uses, same
 * signatures).
 */
interface InstanceSettingsInterface
{
    public function getString(string $key): ?string;

    public function getSecret(string $key): ?string;

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array;
}
