<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Credentials;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function is_string;

/**
 * In-memory InstanceSettings (secrets kept as given; the real bundle encrypts them at rest). Saving dispatches
 * {@see SettingsChangedEvent} like the real service.
 */
final class FakeInstanceSettings implements InstanceSettingsInterface
{
    /** @var array<string, string> */
    public array $values = [];

    public ?string $lastOrigin = null;

    public function __construct(private readonly ?EventDispatcherInterface $dispatcher = null)
    {
    }

    public function getString(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function getSecret(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        $changed = [];
        foreach ($values as $field => $value) {
            $key = $section . '.' . $field;
            if (is_string($value) && $value !== '') {
                $this->values[$key] = $value;
            } else {
                unset($this->values[$key]);
            }

            $changed[$field] = true;
        }

        $this->lastOrigin = $origin;
        $this->dispatcher?->dispatch(new SettingsChangedEvent($section, array_keys($changed), [], $origin));

        return array_keys($changed);
    }
}
