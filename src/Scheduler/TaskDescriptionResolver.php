<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Scheduler;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Throwable;

use function array_key_exists;
use function class_exists;
use function count;
use function explode;
use function implode;
use function is_string;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strip_tags;
use function trim;

/**
 * Human description of a scheduled task for the `schedule_catalog` (`description`, string|null).
 *
 * Resolution order:
 * 1. `#[BeaconCheckIn(description: '…')]` on the message class;
 * 2. `nowo_beacon.scheduler.check_ins.descriptions` (command name / FQCN / parent classes);
 * 3. {@see RunCommandMessage}: the console command description, read through the command loader
 *    (a lazy `#[AsCommand(description:)]` command is not instantiated);
 *    {@see ServiceCallMessage} (`#[AsPeriodicTask]` / `#[AsCronTask]` on a method): the summary of the
 *    target method's docblock (when the service id is a class name);
 * 4. the summary (first sentence) of the message class docblock — only when PHP keeps doc comments
 *    (`opcache.save_comments=1`), silently skipped otherwise.
 *
 * The result is plain text on one line (tags stripped, whitespace collapsed), at most 500 characters,
 * or null. Only class / command / method metadata is read — never message properties, command
 * arguments or service-call arguments.
 */
final class TaskDescriptionResolver
{
    public const MAX_LENGTH = 500;

    /** Bound of the per-process metadata cache. */
    private const CACHE_MAX = 500;

    /** @var array<string, string|null> class / command / method => description (metadata only) */
    private array $cache = [];

    /**
     * @param (Closure(): (CommandLoaderInterface|null))|null $commandLoader Lazy console command loader
     */
    public function __construct(
        private readonly CheckInPolicy $policy,
        private readonly ?Closure $commandLoader = null,
    ) {
    }

    /**
     * Description of the task dispatching `$message` (null when none can be found).
     */
    public function describe(object $message): ?string
    {
        if ($message instanceof RedispatchMessage) {
            $inner   = $message->envelope;
            $message = $inner instanceof Envelope ? $inner->getMessage() : $inner;
        }

        ['message_class' => $class, 'command' => $command] = CheckInPolicy::describe($message);

        $configured = self::normalize($this->policy->description($class, $command));
        if ($configured !== null) {
            return $configured;
        }

        if ($message instanceof RunCommandMessage && $command !== null) {
            $fromCommand = $this->remember('command:' . $command, fn (): ?string => $this->commandDescription($command));
            if ($fromCommand !== null) {
                return $fromCommand;
            }
        }

        if (class_exists(ServiceCallMessage::class) && $message instanceof ServiceCallMessage) {
            $serviceId = $message->getServiceId();
            $method    = $message->getMethod();
            $fromCall  = $this->remember('call:' . $serviceId . '::' . $method, static fn (): ?string => self::methodSummary($serviceId, $method));
            if ($fromCall !== null) {
                return $fromCall;
            }
        }

        return $this->remember('class:' . $class, static fn (): ?string => self::classSummary($class));
    }

    /**
     * Plain text on one line, at most {@see MAX_LENGTH} characters; null when empty.
     */
    public static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Summary (first sentence) of a doc comment; null when there is none.
     */
    public static function docSummary(string|false $docComment): ?string
    {
        if (!is_string($docComment) || $docComment === '') {
            return null;
        }

        $lines = [];
        foreach (explode("\n", $docComment) as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)?#', '', $line));
            $line = trim((string) preg_replace('#\*/$#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '') {
                if ($lines !== []) {
                    break;
                }

                continue;
            }
            $lines[] = $line;
        }

        // Inline tags: {@link https://… text} → text, {@see Foo} → Foo.
        $summary = (string) preg_replace('/\{@\w+\s+\S+\s+([^}]+)\}/', '$1', implode(' ', $lines));
        $summary = (string) preg_replace('/\{@\w+\s+([^}\s]+)\s*\}/', '$1', $summary);
        $summary = self::normalize($summary);
        if ($summary === null) {
            return null;
        }

        return preg_match('/^(.+?[.!?])(\s|$)/u', $summary, $m) === 1 ? $m[1] : $summary;
    }

    private function commandDescription(string $name): ?string
    {
        try {
            $loader = $this->commandLoader instanceof Closure ? ($this->commandLoader)() : null;
            if (!$loader instanceof CommandLoaderInterface || !$loader->has($name)) {
                return null;
            }

            return self::normalize($loader->get($name)->getDescription());
        } catch (Throwable) {
            return null;
        }
    }

    private static function methodSummary(string $serviceId, string $method): ?string
    {
        if (!class_exists($serviceId)) {
            return null;
        }

        try {
            return self::docSummary((new ReflectionMethod($serviceId, $method))->getDocComment());
        } catch (Throwable) {
            return null;
        }
    }

    private static function classSummary(string $class): ?string
    {
        if (!class_exists($class) || $class === RunCommandMessage::class || (class_exists(ServiceCallMessage::class) && $class === ServiceCallMessage::class)) {
            return null; // Symfony's generic messages describe themselves, not the task
        }

        return self::docSummary((new ReflectionClass($class))->getDocComment());
    }

    /**
     * @param Closure(): (string|null) $resolve
     */
    private function remember(string $key, Closure $resolve): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (count($this->cache) >= self::CACHE_MAX) {
            // @igor-ignore - Bounded metadata cache (class / command descriptions), not request state.
            $this->cache = [];
        }

        // @igor-ignore - Bounded metadata cache (class / command descriptions), not request state.
        return $this->cache[$key] = $resolve();
    }
}
