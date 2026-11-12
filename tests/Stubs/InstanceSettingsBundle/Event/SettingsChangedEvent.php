<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

/**
 * Dev stub of nowo-tech/instance-settings-bundle's event (same constructor and constants).
 */
final readonly class SettingsChangedEvent
{
    public const ORIGIN_FORM = 'form';

    public const ORIGIN_IMPORT = 'import';

    public const ORIGIN_API = 'api';

    public const ORIGIN_SEED = 'seed';

    /**
     * @param list<string> $changedFields
     * @param list<string> $secretFields
     * @param array<string, mixed> $fileChanges
     */
    public function __construct(
        public string $section,
        public array $changedFields,
        public array $secretFields = [],
        public string $origin = self::ORIGIN_API,
        public array $fileChanges = [],
    ) {
    }
}
