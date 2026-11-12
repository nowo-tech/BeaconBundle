<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

/**
 * Dev stub of nowo-tech/instance-settings-bundle's section port. The real getIntro() returns
 * `Label|TranslatableMessage|string|null`; the Beacon section narrows it to `?string`, valid for both.
 */
interface SettingsSectionInterface
{
    public function getName(): string;

    public function getLabel(): string;

    public function getTranslationDomain(): string;

    public function getIntro(): mixed;

    public function getIcon(): ?string;

    /**
     * @return iterable<SettingField>
     */
    public function getFields(): iterable;
}
