<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Target;
use App\Entity\TranslationJob;
use App\Message\TranslationWorker\MalformedTranslationResultMessage;
use App\Message\TranslationWorker\PublishTranslationJobMessage;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\MessageHandler\PublishTranslationJobMessageHandler;
use App\MessageHandler\TranslationResultMessageHandler;
use App\Messenger\TranslationResultJsonSerializer;
use App\Service\TranslationIntakeService;
use App\Service\TranslationWorker\TranslationJobPublisherInterface;
use App\Tests\Support\RecordingTranslationJobPublisher;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Native worker path: intake → TranslationJob + outbox → plain-JSON publish → result consumer.
 *
 * Runs against lingua_test. The outbox is in-memory here and the broker is replaced by
 * RecordingTranslationJobPublisher, so nothing touches RabbitMQ; the wire bodies asserted are
 * the exact bytes the real publisher sends.
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
    private InMemoryTransport $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE translation_job, translation_subscription, target, source RESTART IDENTITY CASCADE');
        $this->em->getConnection()->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'translation_notifications'");
        $this->intake = $container->get(TranslationIntakeService::class);
        $this->publisher = $container->get(TranslationJobPublisherInterface::class);
        $this->outbox = $container->get('messenger.transport.translation_outbox');
    }

    private function push(string $text = 'Bonjour le monde.', bool $force = false, string $source = 'fr', string $target = 'en'): array
    {
        return $this->intake->handle(new BatchRequest(
            source: $source,
            target: [$target],
            texts: [$text],
            engine: self::PROFILE,
            forceDispatch: $force,
            callbackUrl: self::CALLBACK,
            refs: ['ref-1'],
        ));
    }

    /** Drain the in-memory outbox through the real publish handler. */
    private function publishOutbox(): void
    {
        $handler = self::getContainer()->get(PublishTranslationJobMessageHandler::class);
        foreach ($this->outbox->get() as $envelope) {
            $handler($envelope->getMessage());
            $this->outbox->ack($envelope);
        }
    }

    private function onlyJob(): TranslationJob
    {
        $this->em->clear();
        $jobs = $this->em->getRepository(TranslationJob::class)->findAll();
        self::assertCount(1, $jobs);

        return $jobs[0];
    }

    private function target(): Target
    {
        $this->em->clear();
        $targets = $this->em->getRepository(Target::class)->findBy(['engine' => self::PROFILE]);
        self::assertCount(1, $targets);

        return $targets[0];
    }

    /** A worker result exactly as FastStream publishes it, decoded by the real wire serializer. */
    private function workerResult(TranslationJob $job, array $override = []): TranslationResultMessage|MalformedTranslationResultMessage
    {
        $body = array_replace([
            'schema_version' => 1,
            'type' => 'translation.result',
            'request_id' => $job->requestId,
            'profile' => $job->profile,
            'source_locale' => $job->sourceLocale,
            'target_locale' => $job->targetLocale,
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

    private function pendingNotifications(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'translation_notifications'");
    }

    public function testCacheMissPersistsJobAndPublishesContractJsonToProfileRoute(): void
    {
        $response = $this->push();

        self::assertSame(1, $response['jobs']['queued']);
        self::assertSame(1, $response['queued']);
        $job = $this->onlyJob();
        self::assertSame(TranslationJob::STATUS_PENDING, $job->status);
        self::assertSame('Bonjour le monde.', $job->inputText);
        self::assertCount(1, $this->outbox->getSent());
        self::assertInstanceOf(PublishTranslationJobMessage::class, $this->outbox->getSent()[0]->getMessage());

        $this->publishOutbox();

        self::assertCount(1, $this->publisher->published);
        self::assertSame('euronano-tiny-v1.to-en', $this->publisher->published[0]['routingKey']);
        self::assertSame($job->requestId, $this->publisher->published[0]['messageId']);
        self::assertSame('translation.request', $this->publisher->published[0]['type']);
        self::assertSame([
            'schema_version' => 1,
            'type' => 'translation.request',
            'request_id' => $job->requestId,
            'text' => 'Bonjour le monde.',
            'source_locale' => 'fr',
            'target_locale' => 'en',
            'profile' => self::PROFILE,
        ], json_decode($this->publisher->published[0]['body'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $job->requestId);
        self::assertSame(TranslationJob::STATUS_DISPATCHED, $this->onlyJob()->status);
    }

    public function testTranslationMemoryHitDispatchesNothing(): void
    {
        $this->push();
        $this->handle($this->workerResult($this->onlyJob()));
        $this->outbox->reset();

        $response = $this->push();

        self::assertSame(['queued' => 0, 'hits' => 1, 'inFlight' => 0, 'superseded' => 0], $response['jobs']);
        self::assertSame([], $this->outbox->getSent());
        self::assertCount(1, $this->em->getRepository(TranslationJob::class)->findAll());
    }

    public function testRepushWhileInFlightDoesNotDuplicateWork(): void
    {
        $this->push();
        $this->outbox->reset();

        $response = $this->push();

        self::assertSame(['queued' => 0, 'hits' => 0, 'inFlight' => 1, 'superseded' => 0], $response['jobs']);
        self::assertSame([], $this->outbox->getSent());
    }

    public function testCompletedResultPersistsProvenanceAndSchedulesCallback(): void
    {
        $this->push();
        $job = $this->onlyJob();
        $before = $this->pendingNotifications();

        $this->handle($this->workerResult($job));

        $target = $this->target();
        self::assertSame(WF::PLACE_TRANSLATED, $target->getMarking());
        self::assertSame('Hello world.', $target->targetText);
        self::assertNull($target->pivotLocale);
        self::assertSame(self::PROFILE, $target->provenance['engine']);
        self::assertSame('ai-tools', $target->provenance['provider']);
        self::assertSame(self::MODEL, $target->provenance['model']);
        self::assertSame(self::REVISION, $target->provenance['revision']);
        self::assertSame('tiny', $target->provenance['variant']);
        self::assertSame('bergamot', $target->provenance['runtime']);
        self::assertSame($job->requestId, $target->provenance['request_id']);

        $job = $this->onlyJob();
        self::assertSame(TranslationJob::STATUS_COMPLETED, $job->status);
        self::assertSame('translated', $job->outcome);
        self::assertSame(12.3, $job->metrics['elapsed_ms']);
        self::assertSame($before + 1, $this->pendingNotifications(), 'the existing callback drain is scheduled');
    }

    public function testIdenticalOutputIsDistinguishedFromTranslated(): void
    {
        $this->push('Paris');
        $before = $this->pendingNotifications();
        $this->handle($this->workerResult($this->onlyJob(), ['translated_text' => 'Paris']));

        self::assertSame(WF::PLACE_IDENTICAL, $this->target()->getMarking());
        self::assertSame('identical', $this->onlyJob()->outcome);
        self::assertSame($before + 1, $this->pendingNotifications());
    }

    public function testDuplicateResultIsIdempotent(): void
    {
        $this->push();
        $job = $this->onlyJob();
        $this->handle($this->workerResult($job));
        $this->handle($this->workerResult($job, ['translated_text' => 'Something else.']));

        self::assertSame('Hello world.', $this->target()->targetText);
        self::assertSame(1, $this->onlyJob()->duplicateResults);
    }

    public function testStaleResultFromSupersededJobIsRecordedButNotApplied(): void
    {
        $this->push();
        $old = $this->onlyJob();

        $response = $this->push(force: true);
        self::assertSame(1, $response['jobs']['superseded']);
        $before = $this->pendingNotifications();

        $this->handle($this->workerResult($old, ['translated_text' => 'Old answer.']));
        self::assertSame(WF::PLACE_UNTRANSLATED, $this->target()->getMarking());
        $this->em->clear();
        $old = $this->em->find(TranslationJob::class, $old->requestId);
        self::assertSame('stale', $old->outcome);
        self::assertSame($before, $this->pendingNotifications());

        $new = $this->em->getRepository(TranslationJob::class)->findOneBy(['status' => TranslationJob::STATUS_PENDING]);
        $this->handle($this->workerResult($new, ['translated_text' => 'New answer.']));
        self::assertSame('New answer.', $this->target()->targetText);
    }

    public function testFailedResultLeavesTargetUntranslatedAndSendsNoCallback(): void
    {
        $this->push();
        $before = $this->pendingNotifications();
        $this->handle($this->workerResult($this->onlyJob(), [
            'status' => 'failed',
            'translated_text' => null,
            'error' => ['code' => 'input_too_long', 'message' => 'Sentence exceeds 120 tokens.', 'retryable' => false],
        ]));

        $target = $this->target();
        self::assertSame(WF::PLACE_UNTRANSLATED, $target->getMarking());
        self::assertNull($target->targetText);
        $job = $this->onlyJob();
        self::assertSame(TranslationJob::STATUS_FAILED, $job->status);
        self::assertSame('input_too_long', $job->error['code']);
        self::assertFalse($job->error['retryable']);
        self::assertSame($before, $this->pendingNotifications());
    }

    public function testEmptyCompletedTranslationIsAFailureNotATranslation(): void
    {
        $this->push();
        $this->handle($this->workerResult($this->onlyJob(), ['translated_text' => '  ']));

        self::assertSame(WF::PLACE_UNTRANSLATED, $this->target()->getMarking());
        self::assertSame('empty_translation', $this->onlyJob()->error['code']);
    }

    public function testResultFromTheWrongVariantIsRejectedUnapplied(): void
    {
        $this->push();
        $job = $this->onlyJob();

        try {
            $this->handle($this->workerResult($job, ['provenance' => ['model' => self::MODEL, 'revision' => self::REVISION, 'variant' => 'base']]));
            self::fail('A base-variant result must not be applied to a tiny profile job.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('variant', $e->getMessage());
        }

        self::assertSame(WF::PLACE_UNTRANSLATED, $this->target()->getMarking());
        self::assertSame(TranslationJob::STATUS_PENDING, $this->onlyJob()->status);
    }

    public function testResultNamingAnotherProfileOrUnknownRequestIsRejected(): void
    {
        $this->push();
        $job = $this->onlyJob();

        foreach ([['profile' => 'euronano-base-v1'], ['request_id' => '00000000-0000-7000-8000-000000000000']] as $override) {
            try {
                $this->handle($this->workerResult($job, $override));
                self::fail('Expected rejection for '.json_encode($override));
            } catch (UnrecoverableMessageHandlingException) {
            }
        }
        self::assertSame(TranslationJob::STATUS_PENDING, $this->onlyJob()->status);
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

    public function testPublishFailureKeepsTheJobPendingForRetry(): void
    {
        $this->push();
        $this->publisher->failWith = new \RuntimeException('unroutable');

        try {
            $this->publishOutbox();
            self::fail('A failed publish must throw so Messenger retries the outbox message.');
        } catch (\RuntimeException) {
        }

        self::assertSame(TranslationJob::STATUS_PENDING, $this->onlyJob()->status);
    }

    public function testUnsupportedPairIsRejectedBeforeAnyJob(): void
    {
        $response = $this->push('Hello.', source: 'en', target: 'fr');

        self::assertArrayHasKey('error', $response);
        self::assertSame([], $this->em->getRepository(TranslationJob::class)->findAll());
        self::assertSame([], $this->outbox->getSent());
    }

    public function testContractExampleRoundTripsThroughTheWireSerializer(): void
    {
        $body = '{"schema_version":1,"type":"translation.result","request_id":"unique-id","profile":"euronano-tiny-v1","source_locale":"fr","target_locale":"en","status":"completed","translated_text":"Hello.","provenance":{"model":"qvac/TranslatePsy-EuroNano","revision":"3a5e1e4e2f7001ff5cfd79785bef03cf19281b73","variant":"tiny","runtime":"bergamot","quantization":"intgemm","beam_size":1},"metrics":{"elapsed_ms":12.3,"total_tokens":3},"confidence":null}';
        $serializer = new TranslationResultJsonSerializer();
        $message = $serializer->decode(['body' => $body, 'headers' => []])->getMessage();

        self::assertInstanceOf(TranslationResultMessage::class, $message);
        self::assertSame('Hello.', $message->translatedText);
        $encoded = $serializer->encode(new \Symfony\Component\Messenger\Envelope($message));
        self::assertSame(['Content-Type' => 'application/json'], $encoded['headers']);
        // Nulls are omitted on encode; everything else survives the round trip unchanged.
        self::assertEquals(array_filter(json_decode($body, true), static fn ($v) => $v !== null), json_decode($encoded['body'], true));
    }
}
