<?php
declare(strict_types=1);
namespace App\Tests\Service;

use App\Entity\Source;
use App\Entity\Target;
use App\Service\TargetTranslationApplier;
use App\Workflow\TargetWorkflow;
use App\Workflow\TargetWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Survos\TranslatorBundle\Contract\TranslatorEngineInterface;
use Survos\TranslatorBundle\Model\TranslationResult;
use Survos\TranslatorBundle\Service\TranslatorManager;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

final class EmptyTranslationWorkflowTest extends TestCase
{
    public function testEmptyEngineResponseCannotCompleteTheWorkflow(): void
    {
        $engine = $this->createStub(TranslatorEngineInterface::class);
        $engine->method('translate')->willReturn(new TranslationResult('  '));
        $locator = $this->createStub(ContainerInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($engine);
        $manager = new TranslatorManager(new TranslatorRegistry($locator, ['libre' => 'libre'], 'libre'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $logger = new NullLogger();
        $listener = new TargetWorkflow($em, $manager, new TargetTranslationApplier($logger), $logger);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('workflow.' . WF::WORKFLOW_NAME . '.transition.' . WF::TRANSITION_TRANSLATE, $listener->onTransition(...));
        $workflow = new Workflow(new Definition(WF::PLACES, [new Transition(WF::TRANSITION_TRANSLATE, WF::PLACE_UNTRANSLATED, WF::PLACE_TRANSLATED)]), new MethodMarkingStore(true, 'marking'), $dispatcher, WF::WORKFLOW_NAME);
        $target = new Target(new Source('santiago', 'es'), 'en');
        try {
            $workflow->apply($target, WF::TRANSITION_TRANSLATE);
            self::fail('Empty translation completed the workflow.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('empty text', $e->getMessage());
        }
        self::assertSame(WF::PLACE_UNTRANSLATED, $target->getMarking());
        self::assertNull($target->targetText);
    }
}
