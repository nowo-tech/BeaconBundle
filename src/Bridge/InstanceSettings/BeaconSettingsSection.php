<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Bridge\InstanceSettings;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;

/**
 * The `beacon` instance settings section (`nowo_beacon.instance_settings_bridge: true`): Beacon DSN and worker key
 * stored in the database, encrypted at rest by InstanceSettings, never rendered back by the admin form (write-only),
 * never exported nor imported (`notPortable()`, InstanceSettings >= 1.0), every change dispatched as a `SettingsChangedEvent` (audit). The previous values and their
 * expiries are written by `nowo:beacon:credentials:rotate`.
 */
final class BeaconSettingsSection implements SettingsSectionInterface
{
    /** Section name (key prefix `beacon.`, URL segment, export key). Never rename it: stored values are keyed by it. */
    public const NAME = 'beacon';

    public const TRANSLATION_DOMAIN = 'NowoBeaconBundle';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return 'settings.section.label';
    }

    public function getTranslationDomain(): string
    {
        return self::TRANSLATION_DOMAIN;
    }

    public function getIntro(): string
    {
        return 'settings.section.intro';
    }

    public function getIcon(): string
    {
        return 'tabler:radar';
    }

    /**
     * @return iterable<SettingField>
     */
    public function getFields(): iterable
    {
        // Never exported nor imported (audit NS-L3): credentials stay on the instance that created them.
        yield SettingField::secret('dsn', 'settings.dsn', 'settings.dsn_help')->notPortable();
        yield SettingField::secret('worker_key', 'settings.worker_key', 'settings.worker_key_help')->notPortable();
        yield SettingField::secret('previous_dsn', 'settings.previous_dsn', 'settings.previous_help')->notPortable();
        yield $this->expiry('previous_dsn_expires_at', 'settings.previous_dsn_expires_at');
        yield SettingField::secret('previous_worker_key', 'settings.previous_worker_key', 'settings.previous_help')->notPortable();
        yield $this->expiry('previous_worker_key_expires_at', 'settings.previous_worker_key_expires_at');
    }

    /**
     * Expiry written by the rotate command: read-only in the admin form (audit NS-L1; the resolver also ignores an
     * expiry more than 30 days ahead, whatever wrote it).
     */
    private function expiry(string $name, string $label): SettingField
    {
        return SettingField::string($name, null, $label, 'settings.expires_at_help')
            ->notPortable()
            ->withFormOptions(['attr' => ['readonly' => 'readonly']]);
    }
}
