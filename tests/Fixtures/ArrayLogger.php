<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_scalar;
use function strtr;

/**
 * Logger keeping interpolated records in memory (tests only).
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = is_scalar($value) ? (string) $value : '';
        }

        $this->records[] = ['level' => (string) $level, 'message' => strtr((string) $message, $replace)];
    }

    /**
     * Messages of one level (all levels when null).
     *
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($level === null || $record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
