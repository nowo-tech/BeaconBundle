<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scheduler;

use ArrayObject;
use Nowo\BeaconBundle\Scheduler\CheckInPolicy;
use Nowo\BeaconBundle\Scheduler\TaskDescriptionResolver;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\DocumentedTasks;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\OutOfRangeMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\PlainMessage;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\ReportMessageInterface;
use Nowo\BeaconBundle\Tests\Fixtures\Scheduler\SlowSyncMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

use function str_repeat;

final class TaskDescriptionResolverTest extends TestCase
{
    public function testResolutionOrder(): void
    {
        $instantiated = new ArrayObject();
        $loader       = new FactoryCommandLoader([
            'app:reports:nightly' => static fn (): Command => new LazyCommand('app:reports:nightly', [], "Builds the nightly sales report\n and e-mails it.", false, static function () use ($instantiated): Command {
                $instantiated->append(true);

                return new Command('app:reports:nightly');
            }),
            'app:silent'     => static fn (): Command => new Command('app:silent'),
            'app:configured' => static fn (): Command => (new Command('app:configured'))->setDescription('From the command.'),
        ]);
        $resolver = new TaskDescriptionResolver(
            new CheckInPolicy([], [], [
                'app:configured'              => 'From the config.',
                ReportMessageInterface::class => 'Reports something.',
                SlowSyncMessage::class        => 'Loses against the attribute.',
                OutOfRangeMessage::class      => 'Exact class.',
            ]),
            static fn (): CommandLoaderInterface => $loader,
        );

        // 1. attribute
        self::assertSame('Syncs the product catalog from the ERP.', $resolver->describe(new SlowSyncMessage()));
        // 2. config (command name, then parent classes / interfaces)
        self::assertSame('From the config.', $resolver->describe(new RunCommandMessage('app:configured --x=1')));
        self::assertSame('Reports something.', $resolver->describe(new PlainMessage('secret')));
        self::assertSame('Exact class.', $resolver->describe(new OutOfRangeMessage()));
        // 3. console command description (lazy command not instantiated)
        self::assertSame('Builds the nightly sales report and e-mails it.', $resolver->describe(new RunCommandMessage('app:reports:nightly --token=s3cr3t')));
        self::assertCount(0, $instantiated, 'the lazy command is never instantiated');
        self::assertNull($resolver->describe(new RunCommandMessage('app:silent')));
        self::assertNull($resolver->describe(new RunCommandMessage('app:unknown')));
        // RedispatchMessage is unwrapped
        self::assertSame('Syncs the product catalog from the ERP.', $resolver->describe(new RedispatchMessage(new Envelope(new SlowSyncMessage()), 'async')));
    }

    public function testServiceCallsAndDocblocks(): void
    {
        $resolver = new TaskDescriptionResolver(new CheckInPolicy());

        self::assertSame('Purges expired password-reset tokens!', $resolver->describe(new ServiceCallMessage(DocumentedTasks::class, 'purgeTokens', [500])));
        self::assertSame('Warms the HTTP cache for the home page', $resolver->describe(new ServiceCallMessage(DocumentedTasks::class, 'warmCache')));
        self::assertNull($resolver->describe(new ServiceCallMessage(DocumentedTasks::class, 'undocumented')));
        self::assertNull($resolver->describe(new ServiceCallMessage(DocumentedTasks::class, 'missing')));
        self::assertNull($resolver->describe(new ServiceCallMessage('app.some_service_id', 'run')));

        // 4. class docblock summary (tags stripped, whitespace collapsed, first sentence, inline tags kept as text)
        self::assertSame('Sends the weekly digest e-mail to subscribers.', $resolver->describe(new PlainMessage()));
        self::assertNull($resolver->describe(new stdClass()));
        self::assertNull($resolver->describe(new OutOfRangeMessage()), 'no docblock');
        self::assertNull($resolver->describe(new RunCommandMessage('app:x')), 'no command loader: Symfony\'s generic message class is not described');

        // Cached per class / method (second call served from the cache).
        self::assertSame('Sends the weekly digest e-mail to subscribers.', $resolver->describe(new PlainMessage()));
    }

    public function testBrokenLoaderAndCacheBound(): void
    {
        $resolver = new TaskDescriptionResolver(new CheckInPolicy(), static fn () => throw new RuntimeException('container down'));
        self::assertNull($resolver->describe(new RunCommandMessage('app:x')));

        $null = new TaskDescriptionResolver(new CheckInPolicy(), static fn (): ?CommandLoaderInterface => null);
        self::assertNull($null->describe(new RunCommandMessage('app:x')));

        for ($i = 0; $i < 501; ++$i) {
            $null->describe(new RunCommandMessage('app:x' . $i));
        }
        self::assertSame('Sends the weekly digest e-mail to subscribers.', $null->describe(new PlainMessage()));
    }

    public function testNormalizeAndDocSummary(): void
    {
        self::assertNull(TaskDescriptionResolver::normalize(null));
        self::assertNull(TaskDescriptionResolver::normalize(" \n\t "));
        self::assertSame(str_repeat('a', 500), TaskDescriptionResolver::normalize(str_repeat('a', 600)));
        self::assertSame('Line one line two', TaskDescriptionResolver::normalize("Line one\r\n   line <i>two</i>"));

        self::assertNull(TaskDescriptionResolver::docSummary(false));
        self::assertNull(TaskDescriptionResolver::docSummary(''));
        self::assertNull(TaskDescriptionResolver::docSummary("/**\n * @return void\n */"));
        self::assertSame('One line', TaskDescriptionResolver::docSummary('/** One line */'));
        self::assertSame('Spans two lines.', TaskDescriptionResolver::docSummary("/**\n *\n * Spans\n * two lines. Second sentence.\n *\n * Body.\n */"));
        self::assertSame('Use Foo\Bar here.', TaskDescriptionResolver::docSummary('/** Use {@see Foo\Bar} here. */'));
    }

    public function testConfiguredDescriptionsArePlainSingleLine(): void
    {
        $resolver = new TaskDescriptionResolver(new CheckInPolicy([], [], ['app:x' => "  Multi\nline  <b>text</b>  "]));

        self::assertSame('Multi line text', $resolver->describe(new RunCommandMessage('app:x')));
    }
}
