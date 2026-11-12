<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Credentials;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function in_array;
use function is_string;
use function preg_match;

/**
 * Fake Beacon ingest: answers 200 to the accepted DSN secrets and 401 to the others, and records which public key /
 * project each POST used (never the secret).
 */
final class ScriptedIngest
{
    /** @var list<array{public_key: string, url: string, status: int}> */
    public array $calls = [];

    /**
     * @param list<string> $acceptedSecrets
     */
    public function __construct(public array $acceptedSecrets)
    {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $auth = '';
            foreach ((array) ($options['headers'] ?? []) as $header) {
                if (is_string($header) && preg_match('/^X-Beacon-Auth: (.*)$/i', $header, $m) === 1) {
                    $auth = $m[1];
                }
            }

            preg_match('/beacon_key=([^,]+), beacon_secret=(.*)$/', $auth, $parts);
            $public = $parts[1] ?? '';
            $secret = $parts[2] ?? '';
            $status = in_array($secret, $this->acceptedSecrets, true) ? 200 : 401;

            $this->calls[] = ['public_key' => $public, 'url' => $url, 'status' => $status];

            return new MockResponse('', ['http_code' => $status]);
        });
    }

    /**
     * URL of the first recorded POST ('' when none).
     */
    public function firstUrl(): string
    {
        return $this->calls[0]['url'] ?? '';
    }

    /**
     * Public keys of the recorded POSTs, in order.
     *
     * @return list<string>
     */
    public function publicKeys(): array
    {
        $keys = [];
        foreach ($this->calls as $call) {
            $keys[] = $call['public_key'];
        }

        return $keys;
    }
}
