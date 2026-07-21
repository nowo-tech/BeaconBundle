<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomepageIsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Beacon Bundle demo');
    }

    public function testReportPageIsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Beacon info report');
    }

    public function testStatusIsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/status');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $payload = json_decode($client->getResponse()->getContent() ?: '', true);

        self::assertIsArray($payload);
        self::assertArrayHasKey('enabled', $payload);
        self::assertArrayHasKey('has_dsn', $payload);
        self::assertArrayHasKey('has_secret_in_dsn', $payload);
        self::assertArrayHasKey('environment', $payload);
        self::assertArrayHasKey('release', $payload);
        self::assertArrayHasKey('auth', $payload);
    }

    public function testFullContextPageIsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/full-context');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Full context demo');
    }

    public function testMessengerFailPageIsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/messenger-fail');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Messenger failure demo');
    }
}
