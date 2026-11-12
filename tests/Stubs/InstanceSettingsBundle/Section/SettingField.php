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
     * @param array<string, int|string> $choices
     * @param array<string, mixed> $formOptions
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
        public array $choices = [],
        public array $formOptions = [],
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

    /**
     * Copy that is left out of exports and refused on imports.
     */
    public function notPortable(): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, false, $this->constraints, $this->choices, $this->formOptions);
    }

    /**
     * Copy with extra admin form options.
     *
     * @param array<string, mixed> $formOptions
     */
    public function withFormOptions(array $formOptions): self
    {
        return new self($this->name, $this->type, $this->default, $this->label, $this->help, $this->required, $this->secret, $this->portable, $this->constraints, $this->choices, $formOptions + $this->formOptions);
    }
}
