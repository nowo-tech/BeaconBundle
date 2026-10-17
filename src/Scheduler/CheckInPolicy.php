<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scheduler;

use Nowo\BeaconBundle\Attribute\BeaconCheckIn;
use ReflectionClass;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Throwable;

use function array_key_exists;
use function class_exists;
use function is_a;
use function mb_substr;
use function preg_split;
use function trim;

/**
 * Decides which Scheduler messages are checked in and their max runtime.
 *
 * Only the message **class** and, for {@see RunCommandMessage}, the **command name** (first token of
 * the input) are read — never message properties, handler arguments or command arguments / options.
 * {@see RedispatchMessage} is unwrapped to the message it redispatches.
 *
 * Precedence: `#[BeaconCheckIn]` on the message class, then the `max_runtime` / `ignore` config
 * (command name first, then exact FQCN, then parent classes / interfaces in config order).
 */
final class CheckInPolicy
{
    public const MAX_RUNTIME_MIN    = 1;
    public const MAX_RUNTIME_MAX    = 604800;
    public const COMMAND_MAX_LENGTH = 120;

    /** @var array<string, BeaconCheckIn|null> class => attribute (metadata cache, bounded by the app's message classes) */
    private array $attributes = [];

    /**
     * @param array<string, int> $maxRuntime message FQCN or command name => seconds
     * @param list<string> $ignore message FQCNs or command names never checked in
     */
    public function __construct(
        private readonly array $maxRuntime = [],
        private readonly array $ignore = [],
    ) {
    }

    /**
     * Class and command name of a scheduled message (unwrapping {@see RedispatchMessage}).
     *
     * @return array{message_class: string, command: string|null}
     */
    public static function describe(object $message): array
    {
        if ($message instanceof RedispatchMessage) {
            $inner   = $message->envelope;
            $message = $inner instanceof Envelope ? $inner->getMessage() : $inner;
        }

        $command = null;
        if ($message instanceof RunCommandMessage) {
            $command = self::commandName($message->input);
        }

        return ['message_class' => $message::class, 'command' => $command];
    }

    /**
     * First token of a console input string (the command name), without quotes.
     */
    public static function commandName(string $input): ?string
    {
        $tokens = preg_split('/\s+/', trim($input), 2);
        $name   = trim($tokens[0] ?? '', '\'"');

        return $name === '' ? null : mb_substr($name, 0, self::COMMAND_MAX_LENGTH);
    }

    /**
     * Whether runs of this message are checked in (attribute `enabled` wins over `ignore`).
     */
    public function isEnabled(string $messageClass, ?string $command = null): bool
    {
        $attribute = $this->attribute($messageClass);
        if ($attribute instanceof BeaconCheckIn) {
            return $attribute->enabled;
        }

        foreach ($this->ignore as $ignored) {
            if ($ignored === $command || $ignored === $messageClass || is_a($messageClass, $ignored, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Max runtime in seconds (attribute first, then config), null when none.
     */
    public function maxRuntime(string $messageClass, ?string $command = null): ?int
    {
        $attribute = $this->attribute($messageClass);
        if ($attribute instanceof BeaconCheckIn && $attribute->maxRuntime !== null) {
            return $this->validMaxRuntime($attribute->maxRuntime);
        }

        if ($command !== null && array_key_exists($command, $this->maxRuntime)) {
            return $this->validMaxRuntime($this->maxRuntime[$command]);
        }

        if (array_key_exists($messageClass, $this->maxRuntime)) {
            return $this->validMaxRuntime($this->maxRuntime[$messageClass]);
        }

        foreach ($this->maxRuntime as $key => $seconds) {
            if ($key !== '' && is_a($messageClass, $key, true)) {
                return $this->validMaxRuntime($seconds);
            }
        }

        return null;
    }

    private function validMaxRuntime(int $seconds): ?int
    {
        return $seconds >= self::MAX_RUNTIME_MIN && $seconds <= self::MAX_RUNTIME_MAX ? $seconds : null;
    }

    private function attribute(string $messageClass): ?BeaconCheckIn
    {
        if (array_key_exists($messageClass, $this->attributes)) {
            return $this->attributes[$messageClass];
        }

        $attribute = null;
        if (class_exists($messageClass)) {
            try {
                $found     = (new ReflectionClass($messageClass))->getAttributes(BeaconCheckIn::class)[0] ?? null;
                $attribute = $found?->newInstance();
            } catch (Throwable) {
                $attribute = null;
            }
        }

        // @igor-ignore - Immutable class metadata cache (one entry per message class), not request state.
        $this->attributes[$messageClass] = $attribute;

        return $attribute;
    }
}
