<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest;

/**
 * Outcome of a self-test kind that returns (does not abort the request / command).
 */
final readonly class SelfTestResult
{
    /** The event was built and handed to the transport. */
    public const DELIVERY_SENT = 'sent';

    /** The PHP warning went through the bundle warning handler (no event id is returned synchronously). */
    public const DELIVERY_WARNING_HANDLER = 'warning_handler';

    /** The failing message was queued; the Messenger failure listener reports it once a worker consumes it. */
    public const DELIVERY_QUEUED = 'queued';

    /** The failing message was handled synchronously; the failure was captured inline. */
    public const DELIVERY_INLINE = 'inline';

    public function __construct(
        public string $kind,
        public string $runToken,
        public ?string $eventId,
        public string $delivery,
    ) {
    }

    /**
     * @return array{kind: string, run: string, event_id: string|null, delivery: string, tag: string}
     */
    public function toArray(): array
    {
        return [
            'kind'     => $this->kind,
            'run'      => $this->runToken,
            'event_id' => $this->eventId,
            'delivery' => $this->delivery,
            'tag'      => BeaconSelfTest::TAG,
        ];
    }
}
