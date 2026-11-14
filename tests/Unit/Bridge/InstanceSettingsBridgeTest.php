<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use Nowo\BeaconBundle\Bridge\InstanceSettings\BeaconSettingsSection;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsListener;
use Nowo\BeaconBundle\Bridge\InstanceSettings\InstanceSettingsCredentialsStore;
use Nowo\BeaconBundle\Credentials\BeaconCredentials;
use Nowo\BeaconBundle\Credentials\BeaconCredentialsResolver;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Tests\Fixtures\ArrayLogger;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\CredentialsFixtures as F;
use Nowo\BeaconBundle\Tests\Fixtures\Credentials\FakeInstanceSettings;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function iterator_to_array;
use function print_r;

#[CoversClass(BeaconSettingsSection::class)]
#[CoversClass(InstanceSettingsCredentialsStore::class)]
#[CoversClass(InstanceSettingsCredentialsListener::class)]
final class InstanceSettingsBridgeTest extends TestCase
{
    public function testSectionDeclaresSecretFieldsAndPlainExpiries(): void
    {
        $section = new BeaconSettingsSection();
        self::assertSame('beacon', $section->getName());
        self::assertSame('settings.section.label', $section->getLabel());
        self::assertSame('NowoBeaconBundle', $section->getTranslationDomain());
        self::assertSame('settings.section.intro', $section->getIntro());
        self::assertSame('tabler:radar', $section->getIcon());

        $secret = [];
        foreach ($section->getFields() as $field) {
            self::assertInstanceOf(SettingField::class, $field);
            $secret[$field->name] = $field->secret;
        }

        self::assertSame([
            'dsn'                            => true,
            'worker_key'                     => true,
            'previous_dsn'                   => true,
            'previous_dsn_expires_at'        => false,
            'previous_worker_key'            => true,
            'previous_worker_key_expires_at' => false,
        ], $secret);
        self::assertCount(6, iterator_to_array($section->getFields(), false));
    }

    public function testStoreRoundTripAndEmptyRecord(): void
    {
        $settings = new FakeInstanceSettings();
        $store    = new InstanceSettingsCredentialsStore($settings);
        self::assertNull($store->current());

        $expires = new DateTimeImmutable('2026-11-13T10:00:00+00:00');
        $store->save(new BeaconCredentials(F::DB_DSN, F::DB_KEY, F::CONFIG_DSN, $expires, F::CONFIG_KEY, $expires));
        self::assertSame(SettingsChangedEvent::ORIGIN_API, $settings->lastOrigin);
        self::assertSame('2026-11-13T10:00:00+00:00', $settings->values['beacon.previous_dsn_expires_at']);

        $read = $store->current();
        self::assertNotNull($read);
        self::assertSame(F::DB_DSN, $read->dsn());
        self::assertSame(F::DB_KEY, $read->workerKey());
        self::assertSame(F::CONFIG_DSN, $read->previousDsn());
        self::assertEquals($expires, $read->previousDsnExpiresAt());
        self::assertSame(F::CONFIG_KEY, $read->previousWorkerKey());
        self::assertEquals($expires, $read->previousWorkerKeyExpiresAt());

        $store->save(new BeaconCredentials());
        self::assertSame([], $settings->values, 'null values remove the stored ones');
        self::assertNull($store->current());
    }

    public function testOnlyStrictIso8601ExpiriesCount(): void
    {
        $settings = new FakeInstanceSettings();
        $store    = new InstanceSettingsCredentialsStore($settings);
        foreach (['+1 day', 'tomorrow', '2026-11-13', '2026-11-13 10:00:00', '2026-11-13T10:00:00+0000'] as $text) {
            $settings->values = ['beacon.previous_dsn' => F::CONFIG_DSN, 'beacon.previous_dsn_expires_at' => $text];
            self::assertNull($store->current()?->previousDsnExpiresAt(), $text);
        }
    }

    public function testListenerInvalidatesTheCacheAndLogsFieldNamesOnly(): void
    {
        $clock      = new MockClock(new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
        $logger     = new ArrayLogger();
        $dispatcher = new EventDispatcher();
        $settings   = new FakeInstanceSettings($dispatcher);
        $store      = new InstanceSettingsCredentialsStore($settings);
        $resolver   = new BeaconCredentialsResolver(new BeaconDsnParser(), F::CONFIG_DSN, '', $store, 3600, $clock, $logger);
        $resolver->arm();
        $dispatcher->addListener(SettingsChangedEvent::class, new InstanceSettingsCredentialsListener($resolver, $logger));

        self::assertSame('pub-env', $resolver->resolve()->dsn()?->getPublicKey());
        $settings->save('beacon', ['dsn' => F::DB_DSN], SettingsChangedEvent::ORIGIN_FORM);
        $dsn = $resolver->resolve()->dsn();
        self::assertSame('pub-db', $dsn?->getPublicKey(), 'invalidated at once despite the 1 h TTL');

        self::assertSame(['Beacon credentials changed in instance settings (fields: dsn; origin: form).'], $logger->messages('notice'));
        self::assertTrue($logger->records[0]['context'][EnvelopeTransport::LOG_CONTEXT_FLAG]);
        self::assertStringNotContainsString('fixture-secret-db', print_r($logger->records, true));

        $settings->save('mailer', ['dsn' => 'smtp://x']);
        self::assertCount(1, $logger->messages('notice'), 'other sections are ignored');

        (new InstanceSettingsCredentialsListener($resolver))(new SettingsChangedEvent('beacon', ['dsn']));
        self::assertCount(1, $logger->messages('notice'));
    }
}
