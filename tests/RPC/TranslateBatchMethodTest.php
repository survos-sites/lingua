<?php
declare(strict_types=1);

namespace App\Tests\RPC;

use App\Entity\Source;
use App\Entity\Target;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * translateBatch -- the write half -- end to end over POST /api/v1.
 *
 * Dispatched jobs are counted in the messenger_messages table, on the target.translate
 * transport (doctrine://default = lingua_test here) that state-bundle registers for the
 * `async: true` translate transition -- so overriding `async` in the test env would have no
 * effect on this path, worth knowing before reaching for in-memory://.
 *
 * NOTE the counts here are MESSAGES, not targets, and since 2026-08-18 those differ: intake
 * dispatches one TranslateBatchMessage per (target locale, chunk of 100) rather than one
 * TransitionMessage per Target. `result.queued` still counts targets. A test that asserts
 * one row per target is asserting the thing the batching removed.
 */
final class TranslateBatchMethodTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE target, source RESTART IDENTITY CASCADE');

        // state-bundle auto-registers a per-transition transport (target.translate) on
        // doctrine://default, which in the test env is lingua_test. auto_setup creates the
        // table on first dispatch, so it may not exist on a clean database yet.
        $this->em->getConnection()->executeStatement('TRUNCATE messenger_messages RESTART IDENTITY');
    }

    private function queuedJobRowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM messenger_messages WHERE queue_name = 'target.translate'",
        );
    }

    /** @param array<string,mixed> $params */
    private function call(array $params, string $id = '1'): array
    {
        $this->client->request(
            'POST',
            '/api/v1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['jsonrpc' => '2.0', 'method' => 'translateBatch', 'params' => $params, 'id' => $id],
                JSON_THROW_ON_ERROR,
            ),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testCreatesSourcesAndQueuesOneJobPerTargetLocale(): void
    {
        $body = $this->call([
            'source' => 'en',
            'target' => ['es', 'fr'],
            'texts' => ['good morning', 'hello world'],
        ]);

        // 2 texts x 2 locales
        self::assertSame(4, $body['result']['queued']);
        self::assertSame([], $body['result']['missing']);
        self::assertCount(2, $body['result']['items']);

        // FOUR targets, TWO messages — one TranslateBatchMessage per target locale, each
        // carrying both target keys. `queued` still counts TARGETS (the caller's answer to
        // "how much work did you accept"); the row count is what changed, and it is the
        // whole point: one /translate call per locale instead of one per string.
        // See App\Message\TranslateBatchMessage.
        self::assertSame(2, $this->queuedJobRowCount());

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM source'));
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM target'));
    }

    public function testTheSameTextTwiceIsDedupedToOneSource(): void
    {
        $body = $this->call([
            'source' => 'en',
            'target' => ['es'],
            'texts' => ['repeated', 'repeated'],
        ]);

        self::assertSame(1, $body['result']['queued']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM source'));
    }

    public function testInsertNewStringsFalseCreatesNothingAndReportsTheTextAsMissing(): void
    {
        $body = $this->call([
            'source' => 'en',
            'target' => ['es'],
            'texts' => ['never seen before'],
            'insertNewStrings' => false,
        ]);

        self::assertSame(0, $body['result']['queued']);
        self::assertSame(['never seen before'], $body['result']['missing']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM source'));
        self::assertSame(0, $this->queuedJobRowCount());
    }

    public function testAnAlreadyTranslatedTargetIsNotRequeued(): void
    {
        $source = new Source('good morning', 'en');
        $this->em->persist($source);
        $target = new Target($source, 'da', 'libre');
        $target->targetText = 'god morgen';
        $target->setMarking(WF::PLACE_TRANSLATED);
        $this->em->persist($target);
        $this->em->flush();

        $body = $this->call(['source' => 'en', 'target' => ['da'], 'texts' => ['good morning']]);

        self::assertSame(0, $body['result']['queued'], 'a translated target must not be re-queued');
        self::assertSame(0, $this->queuedJobRowCount());
    }

    public function testForceDispatchRequeuesAnAlreadyTranslatedTarget(): void
    {
        $source = new Source('good morning', 'en');
        $this->em->persist($source);
        $target = new Target($source, 'da', 'libre');
        $target->targetText = 'god morgen';
        $target->setMarking(WF::PLACE_TRANSLATED);
        $this->em->persist($target);
        $this->em->flush();

        $body = $this->call([
            'source' => 'en',
            'target' => ['da'],
            'texts' => ['good morning'],
            'forceDispatch' => true,
        ]);

        self::assertSame(1, $body['result']['queued']);
    }

    /**
     * The reliability fix. Nothing validated `engine` before: an unknown one created Targets,
     * queued them, and failed one at a time inside the worker at TargetWorkflow's
     * Assert::inArray -- after the write, where the caller never saw it.
     */
    public function testAnUnknownEngineIsRejectedBeforeAnythingIsWritten(): void
    {
        $body = $this->call([
            'source' => 'en',
            'target' => ['es'],
            'texts' => ['hello'],
            'engine' => 'nosuchengine',
        ]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('Unknown engine "nosuchengine"', $body['error']['message']);
        self::assertStringContainsString('Configured engines:', $body['error']['message']);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM source'));
        self::assertSame(0, $this->queuedJobRowCount());
    }

    public function testAKnownEngineIsAccepted(): void
    {
        $body = $this->call([
            'source' => 'en',
            'target' => ['es'],
            'texts' => ['hello'],
            'engine' => 'deepl',
        ]);

        self::assertArrayNotHasKey('error', $body);
        self::assertSame(1, $body['result']['queued']);
    }

    /**
     * The REST route answers this with {"status":"ok","response":{"error":"..."}} at HTTP
     * 200. Over RPC it is a real error object with a code.
     */
    public function testAnEmptyTextListIsInvalidParamsNotASuccessfulResponse(): void
    {
        $body = $this->call(['source' => 'en', 'target' => ['es'], 'texts' => []]);

        self::assertSame(-32602, $body['error']['code']);
        self::assertStringContainsString('source/target/texts required', $body['error']['message']);
    }

    public function testATargetEqualToTheSourceLocaleIsInvalidParams(): void
    {
        $body = $this->call(['source' => 'en', 'target' => ['en'], 'texts' => ['hello']]);

        self::assertSame(-32602, $body['error']['code']);
    }

    public function testANonStringTargetElementIsInvalidParams(): void
    {
        $body = $this->call(['source' => 'en', 'target' => [42], 'texts' => ['hello']]);

        self::assertSame(-32602, $body['error']['code']);
    }

    public function testTheOptionalFlagsMayBeOmitted(): void
    {
        // Regression: insertNewStrings/forceDispatch defaulted only in the constructor
        // signature, not on the property, so the bundle called them required and every
        // caller that omitted them got "[insertNewStrings] - This field is missing."
        $body = $this->call(['source' => 'en', 'target' => ['es'], 'texts' => ['hello']]);

        self::assertArrayNotHasKey('error', $body);
    }

    /** Several locale groups in one round trip -- the reason for migrating the write path. */
    public function testABatchQueuesEveryElementInOneRoundTrip(): void
    {
        $this->client->request(
            'POST',
            '/api/v1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                ['jsonrpc' => '2.0', 'method' => 'translateBatch', 'id' => 'es',
                 'params' => ['source' => 'en', 'target' => ['es'], 'texts' => ['one', 'two']]],
                ['jsonrpc' => '2.0', 'method' => 'translateBatch', 'id' => 'fr',
                 'params' => ['source' => 'en', 'target' => ['fr'], 'texts' => ['three']]],
            ], JSON_THROW_ON_ERROR),
        );

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $byId = array_column($body, null, 'id');

        self::assertSame(2, $byId['es']['result']['queued']);
        self::assertSame(1, $byId['fr']['result']['queued']);
        // 3 targets, but 2 messages: each RPC call in the batch requests a single locale, so
        // each produces one TranslateBatchMessage regardless of how many texts it carries.
        self::assertSame(2, $this->queuedJobRowCount());
    }

    /** The REST route must keep its existing envelope; zm/bts/harvest parse it. */
    public function testTheRestRouteStillReturnsItsStatusEnvelope(): void
    {
        $this->client->request(
            'POST',
            '/batch-translate',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'source' => 'en', 'target' => ['es'], 'texts' => ['via rest'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ok', $body['status']);
        self::assertSame(1, $body['response']['queued']);
    }
}
