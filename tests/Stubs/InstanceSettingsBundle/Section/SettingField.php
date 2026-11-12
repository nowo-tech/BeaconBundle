<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

/**
 * Dev stub of nowo-tech/instance-settings-bundle's field (the named constructors the Beacon bridge uses, same
 * signatures).
 */
final readonly class SettingField
{
    /** @var list<mixed> */
    public array $constraints;

    /**
     * @param iterable<mixed> $constraints
     */
    public function __construct(
        public string $name,
        public SettingType $type = SettingType::String,
        public bool|float|int|string|null $default = null,
        public ?string $label = null,
        public ?string $help = null,
        public bool $required = false,
        public bool $secret = false,
        public bool $portable = true,
        iterable $constraints = [],
    ) {
        $list = [];
        foreach ($constraints as $constraint) {
            $list[] = $constraint;
        }

        $this->constraints = $list;
    }

    /**
     * @param iterable<mixed> $constraints
     */
    public static function string(string $name, ?string $default = null, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = []): self
    {
        return new self($name, SettingType::String, $default, $label, $help, $required, constraints: $constraints);
    }

    /**
     * @param iterable<mixed> $constraints
     */
    public static function secret(string $name, ?string $label = null, ?string $help = null, bool $required = false, iterable $constraints = [], SettingType $type = SettingType::String): self
    {
        return new self($name, $type, null, $label, $help, $required, secret: true, constraints: $constraints);
    }
}
