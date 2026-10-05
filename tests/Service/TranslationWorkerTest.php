<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Target;
use App\Message\TranslateBatchMessage;
use App\Message\TranslationWorker\MalformedTranslationResultMessage;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\MessageHandler\TranslateBatchMessageHandler;
use App\MessageHandler\TranslationResultMessageHandler;
use App\Messenger\TranslationResultJsonSerializer;
use App\Service\TranslationIntakeService;
use App\Service\TranslationWorker\TranslationJobPublisherInterface;
use App\Tests\Support\RecordingTranslationJobPublisher;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Worker profiles ride the existing batch path: intake → TranslateBatchMessage →
 * TranslateBatchMessageHandler publishes each job and applies `dispatch` (u → q) → the result
 * consumer applies the answer via TargetTranslationApplier.
 *
 * Runs against lingua_test. The broker is replaced by RecordingTranslationJobPublisher, so
 * nothing touches RabbitMQ; the bodies asserted are the exact bytes the real publisher sends.
 */
final class TranslationWorkerTest extends KernelTestCase
{
    private const string PROFILE = 'euronano-tiny-v1';
    private const string MODEL = 'qvac/TranslatePsy-EuroNano';
    private const string REVISION = '3a5e1e4e2f7001ff5cfd79785bef03cf19281b73';
    private const string CALLBACK = 'https://harvest.test/lingua/callback';

    private EntityManagerInterface $em;
    private TranslationIntakeService $intake;
    private RecordingTranslationJobPublisher $publisher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE translation_subscription, target, source RESTART IDENTITY CASCADE');
        $this->em->getConnection()->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('translation_notifications', 'target.translate')");
        $this->intake = $container->get(TranslationIntakeService::class);
        $this->publisher = $container->get(TranslationJobPublisherInterface::class);
    }

    /** Push through the real intake, then run the batches it queued, as the worker would. */
    private function push(string $text = 'Bonjour le monde.', bool $force = false, string $source = 'fr', string $target = 'en'): array
    {
        $response = $this->intake->handle(new BatchRequest(
            source: $source,
            target: [$target],
            texts: [$text],
            engine: self::PROFILE,
            forceDispatch: $force,
            callbackUrl: self::CALLBACK,
            refs: ['ref-1'],
        ));

        $transport = self::getContainer()->get('messenger.transport.target.translate');
        $handler = self::getContainer()->get(TranslateBatchMessageHandler::class);
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            $transport->ack($envelope);
            if ($message instanceof TranslateBatchMessage) {
                $handler($message);
            }
        }

        return $response;
    }

    private function target(): Target
    {
        $this->em->clear();
        $targets = $this->em->getRepository(Target::class)->findBy(['engine' => self::PROFILE]);
        self::assertCount(1, $targets);

        return $targets[0];
    }

    /** A worker result exactly as FastStream publishes it, decoded by the real wire serializer. */
    private function workerResult(array $override = []): TranslationResultMessage|MalformedTranslationResultMessage
    {
        $target = $this->target();
        $body = array_replace([
            'schema_version' => 1,
            'type' => 'translation.result',
            'request_id' => $target->key,
            'profile' => $target->engine,
            'source_locale' => $target->source->locale,
            'target_locale' => $target->targetLocale,
            'status' => 'completed',
            'translated_text' => 'Hello world.',
            'provenance' => ['model' => self::MODEL, 'revision' => self::REVISION, 'variant' => 'tiny', 'runtime' => 'bergamot', 'quantization' => 'intgemm', 'beam_size' => 1],
            'metrics' => ['elapsed_ms' => 12.3, 'total_tokens' => 3],
            'confidence' => null,
        ], $override);

        return (new TranslationResultJsonSerializer())->decode(['body' => json_encode($body), 'headers' => []])->getMessage();
    }

    private function handle(object $message): void
    {
        $handler = self::getContainer()->get(TranslationResultMessageHandler::class);
        $message instanceof MalformedTranslationResultMessage ? $handler->malformed($message) : $handler->result($message);
    }

    private function expectRejected(object $message, string $contains): void
    {
        try {
            $this->handle($message);
            self::fail('Expected the result to be rejected into the failed transport.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString($contains, $e->getMessage());
        }
    }

    private function pendingNotifications(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'translation_notifications'");
    }

    public function testCacheMissQueuesTargetAndPublishesContractJsonToProfileRoute(): void
    {
        self::assertSame(1, $this->push()['queued']);

        $target = $this->target();
        self::assertSame(WF::PLACE_QUEUED, $target->getMarking());
        self::assertCount(1, $this->publisher->published);
        $published = $this->publisher->published[0];
        self::assertSame('euronano-tiny-v1.to-en', $published['routingKey']);
        self::assertSame($target->key, $published['messageId']);
        self::assertSame('translation.request', $published['type']);
        self::assertSame([
            'schema_version' => 1,
            'type' => 'translation.request',
            'request_id' => $target->key,
            'text' => 'Bonjour le monde.',
            'source_locale' => 'fr',
            'target_locale' => 'en',
            'profile' => self::PROFILE,
        ], json_decode($published['body'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $target->key, 'request_id is the Target key the worker validates');
    }

    public function testTranslationMemoryHitPublishesNothing(): void
    {
        $this->push();
        $this->handle($this->workerResult());
        $this->publisher->published = [];

        self::assertSame(0, $this->push()['queued']);
        self::assertSame([], $this->publisher->published);
    }

    public function testRepushWhileQueuedDoesNotPublishAgain(): void
    {
        $this->push();
        $this->publisher->published = [];

        $this->push();

        self::assertSame([], $this->publisher->published);
        self::assertSame(WF::PLACE_QUEUED, $this->target()->getMarking());
    }

    public function testCompletedResultPersistsProvenanceAndSchedulesCallback(): void
    {
        $this->push();
        $before = $this->pendingNotifications();

        $this->handle($this->workerResult());

        $target = $this->target();
        self::assertSame(WF::PLACE_TRANSLATED, $target->getMarking());
        self::assertSame('Hello world.', $target->targetText);
        self::assertSame(self::PROFILE, $target->provenance['engine']);
        self::assertSame('ai-tools', $target->provenance['provider']);
        self::assertSame(self::MODEL, $target->provenance['model']);
        self::assertSame(self::REVISION, $target->provenance['revision']);
        self::assertSame('tiny', $target->provenance['variant']);
        self::assertSame('bergamot', $target->provenance['runtime']);
        self::assertSame(1, $target->provenance['beam_size']);
        self::assertSame($before + 1, $this->pendingNotifications(), 'the existing callback drain is scheduled');
    }

    public function testIdenticalOutputIsDistinguishedFromTranslated(): void
    {
        $this->push('Paris');
        $before = $this->pendingNotifications();

        $this->handle($this->workerResult(['translated_text' => 'Paris']));

        self::assertSame(WF::PLACE_IDENTICAL, $this->target()->getMarking());
        self::assertSame($before + 1, $this->pendingNotifications());
    }

    public function testDuplicateResultIsIdempotent(): void
    {
        $this->push();
        $this->handle($this->workerResult());
        $before = $this->pendingNotifications();

        $this->handle($this->workerResult(['translated_text' => 'Something else.']));

        self::assertSame('Hello world.', $this->target()->targetText);
        self::assertSame($before, $this->pendingNotifications());
    }

    public function testFailedResultIsParkedAndTheTargetIsSentAgainOnTheNextPush(): void
    {
        $this->push();
        $before = $this->pendingNotifications();

        $this->expectRejected($this->workerResult([
            'status' => 'failed',
            'translated_text' => null,
            'error' => ['code' => 'input_too_long', 'message' => 'Sentence exceeds 120 tokens.', 'retryable' => false],
        ]), 'input_too_long');

        $target = $this->target();
        self::assertSame(WF::PLACE_UNTRANSLATED, $target->getMarking());
        self::assertNull($target->targetText);
        self::assertSame($before, $this->pendingNotifications(), 'no callback for a failure');

        $this->publisher->published = [];
        $this->push();
        self::assertCount(1, $this->publisher->published, 'an untranslated Target is a cache miss again');
    }

    public function testEmptyCompletedTranslationIsAFailureNotATranslation(): void
    {
        $this->push();

        $this->expectRejected($this->workerResult(['translated_text' => '  ']), 'empty translation');

        self::assertSame(WF::PLACE_UNTRANSLATED, $this->target()->getMarking());
    }

    public function testResultFromTheWrongVariantIsRejectedUnapplied(): void
    {
        $this->push();

        $this->expectRejected($this->workerResult(['provenance' => ['model' => self::MODEL, 'revision' => self::REVISION, 'variant' => 'base']]), 'variant');

        self::assertSame(WF::PLACE_QUEUED, $this->target()->getMarking());
    }

    public function testResultNamingAnotherProfileOrUnknownTargetIsRejected(): void
    {
        $this->push();

        $this->expectRejected($this->workerResult(['profile' => 'euronano-base-v1']), 'but the Target is');
        $this->expectRejected($this->workerResult(['request_id' => '0000000000000000']), 'No Target');
        self::assertSame(WF::PLACE_QUEUED, $this->target()->getMarking());
    }

    public function testMalformedBodyIsParkedNotRequeued(): void
    {
        $serializer = new TranslationResultJsonSerializer();
        foreach (['not json', '[]', '{"schema_version":2}', json_encode(['schema_version' => 1, 'type' => 'translation.result', 'request_id' => 'x y'])] as $body) {
            $message = $serializer->decode(['body' => $body, 'headers' => []])->getMessage();
            self::assertInstanceOf(MalformedTranslationResultMessage::class, $message, $body);
        }

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handle($message);
    }

    public function testUnconfirmedPublishLeavesTheTargetUntranslatedForRetry(): void
    {
        $this->publisher->failWith = new TransportException('unroutable');

        try {
            $this->push();
            self::fail('A publish the broker did not confirm must fail the batch so Messenger retries it.');
        } catch (TransportException) {
        }

        self::assertSame(WF::PLACE_UNTRANSLATED, $this->target()->getMarking());
    }

    public function testUnsupportedPairIsRejectedBeforeAnyWrite(): void
    {
        $response = $this->push('Hello.', source: 'en', target: 'fr');

        self::assertArrayHasKey('error', $response);
        self::assertSame([], $this->em->getRepository(Target::class)->findAll());
        self::assertSame([], $this->publisher->published);
    }

    public function testContractExampleRoundTripsThroughTheWireSerializer(): void
    {
        $body = '{"schema_version":1,"type":"translation.result","request_id":"6fe29a3972c954f1","profile":"euronano-tiny-v1","source_locale":"fr","target_locale":"en","status":"completed","translated_text":"Hello.","provenance":{"model":"qvac/TranslatePsy-EuroNano","revision":"3a5e1e4e2f7001ff5cfd79785bef03cf19281b73","variant":"tiny","runtime":"bergamot","quantization":"intgemm","beam_size":1},"metrics":{"elapsed_ms":12.3,"total_tokens":3},"confidence":null}';
        $serializer = new TranslationResultJsonSerializer();
        $message = $serializer->decode(['body' => $body, 'headers' => []])->getMessage();

        self::assertInstanceOf(TranslationResultMessage::class, $message);
        self::assertSame('Hello.', $message->translatedText);
        $encoded = $serializer->encode(new Envelope($message));
        self::assertSame(['Content-Type' => 'application/json'], $encoded['headers']);
        // Nulls are omitted on encode; everything else survives the round trip unchanged.
        self::assertEquals(array_filter(json_decode($body, true), static fn ($v) => $v !== null), json_decode($encoded['body'], true));
    }
}
