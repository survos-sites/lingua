<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Source;
use App\Entity\Target;
use App\Service\TranslationPullService;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The query behind both transports. POST /babel/pull and the pullTranslations RPC method
 * both call this service, so what is asserted here holds for both.
 */
final class TranslationPullServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TranslationPullService $pull;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pull = self::getContainer()->get(TranslationPullService::class);

        // Order matters: target has a non-nullable FK to source.
        $this->em->getConnection()->executeStatement('TRUNCATE target, source RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }

    /** @return array<string,string> hash keyed by the text used to build it */
    private function seed(): array
    {
        $rows = [
            // text,        source locale, target locale, engine, translation, marking
            ['good morning', 'en', 'da', 'libre', 'god morgen',   WF::PLACE_TRANSLATED],
            ['hello world',  'en', 'es', 'libre', 'hola mundo',   WF::PLACE_TRANSLATED],
            ['hello world',  'en', 'fr', 'deepl', 'bonjour tout', WF::PLACE_TRANSLATED],
            // Queued but not yet translated -- present in the DB, absent from a pull.
            ['not done yet', 'en', 'es', 'libre', null,           WF::PLACE_UNTRANSLATED],
        ];

        $hashes = [];
        $sources = [];
        foreach ($rows as [$text, $from, $to, $engine, $translation, $marking]) {
            $source = $sources[$text] ??= new Source($text, $from);
            $hashes[$text] = $source->hash;
            $this->em->persist($source);

            $target = new Target($source, $to, $engine);
            $target->targetText = $translation;
            $target->setMarking($marking);
            $this->em->persist($target);
        }
        $this->em->flush();

        return $hashes;
    }

    public function testReturnsTranslationsKeyedBySourceHash(): void
    {
        $h = $this->seed();

        $map = $this->pull->pullByHashes([$h['good morning']]);

        self::assertSame([$h['good morning'] => 'god morgen'], $map);
    }

    /**
     * The behaviour the RPC method exists to expose: an untranslated row and an unknown hash
     * are BOTH simply absent from the map, which is why `missing` had to be reported
     * separately.
     */
    public function testUntranslatedAndUnknownHashesAreEquallyAbsent(): void
    {
        $h = $this->seed();
        $unknown = 'deadbeefdeadbeef';

        $map = $this->pull->pullByHashes([$h['not done yet'], $unknown]);

        self::assertSame([], $map);
        self::assertEqualsCanonicalizing(
            [$h['not done yet'], $unknown],
            $this->pull->missing([$h['not done yet'], $unknown], $map),
        );
    }

    public function testMissingReportsOnlyWhatDidNotComeBack(): void
    {
        $h = $this->seed();
        $requested = [$h['good morning'], 'deadbeefdeadbeef'];

        $map = $this->pull->pullByHashes($requested);

        self::assertSame(['deadbeefdeadbeef'], $this->pull->missing($requested, $map));
    }

    public function testLocaleFilterRestrictsTheResult(): void
    {
        $h = $this->seed();

        self::assertSame(
            [$h['hello world'] => 'hola mundo'],
            $this->pull->pullByHashes([$h['hello world']], locale: 'es'),
        );
        self::assertSame([], $this->pull->pullByHashes([$h['hello world']], locale: 'de'));
    }

    public function testEngineFilterRestrictsTheResult(): void
    {
        $h = $this->seed();

        self::assertSame(
            [$h['hello world'] => 'bonjour tout'],
            $this->pull->pullByHashes([$h['hello world']], engine: 'deepl'),
        );
    }

    public function testAnEmptyHashListSkipsTheQueryEntirely(): void
    {
        $this->seed();

        self::assertSame([], $this->pull->pullByHashes([]));
    }

    public function testNormalizeDedupesAndDropsBlanks(): void
    {
        self::assertSame(
            ['abc', 'def'],
            $this->pull->normalizeHashes(['abc', 'abc', '', 'def', '0']),
            'array_filter drops "" -- and also "0", which is worth knowing but harmless for '
            . '16-hex hashes, since a hash is never the single character 0.',
        );
    }
}
