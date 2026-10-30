<?php

namespace App\Command;

use App\Entity\Source;
use App\Entity\Target;
use App\Repository\SourceRepository;
use App\Repository\TargetRepository;
use App\Service\TranslationIntakeService;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\SerializerInterface;

#[AsCommand('app:dispatch', 'dispatch messages from the database')]
final class AppDispatchCommand
{

    public function __construct(
        private SourceRepository                              $sourceRepository,
        private EntityManagerInterface                        $entityManager,
        private SerializerInterface                           $serializer,
        private TransitionDispatcher                          $transitions,
        private TranslationIntakeService                      $intake,
        #[Autowire('%kernel.enabled_locales%')] private array $supportedLocales,
        private readonly TargetRepository                     $targetRepository,
    )
    {
    }

    public function __invoke(
        SymfonyStyle                    $io,
        #[Argument()] ?string $action = null, // source or target.
        #[Option('overwrite the database entry')]
        string                $marking = TargetWorkflowInterface::PLACE_UNTRANSLATED,

        #[Option(description: 'limit source language')]
        string                $from = 'en',

        #[Option(name: 'to', description: 'limit (or add) target languages, comma-delimited')]
        ?string               $toString = null,

        #[Option(description: 'sync or async')]
        ?string               $transport = null,

        #[Option(description: 'limit the number of records')]
        int                   $limit = 0,

        #[Option(description: 'batch size for dispatch')]
        int                   $batch = 100,


    ): int
    {

        if ($action ==='source') {
            assert($toString, "require tostring for now");
            $to = $toString ? explode(',', $toString) : $this->supportedLocales;
        }


//        if ($purgeUntranslated) {
        // ./c d:run "delete from target where marking='u'"
//        }

        if (!$action) {
            $io->writeln("Actions: 'source, target");
            return Command::SUCCESS;
        }

        if ($action === 'target') {
            $qb = $this->targetRepository->createQueryBuilder('t');
            if ($marking) {
                $qb->andWhere('t.marking = :marking')
                    ->setParameter('marking', $marking);
            }
            if ($limit) {
                $qb->setMaxResults($limit);
            }
            // The translate transition declares its own transport; 'sync' applies it in-process.
            if ($transport && 'sync' !== $transport) {
                $io->error("--transport only accepts 'sync'; the translate transition declares its own transport.");

                return Command::FAILURE;
            }
            $context = 'sync' === $transport ? ['cascade' => 'sync'] : [];
            $count = 0;
            /** @var Target $target */
            foreach ($qb->getQuery()->getResult() as $idx => $target) {
                $this->transitions->dispatch(
                    TargetWorkflowInterface::WORKFLOW_NAME,
                    new SubjectReference(Target::class, (string) $target->key),
                    TargetWorkflowInterface::TRANSITION_TRANSLATE,
                    $context,
                );
                $count++;
            }
            $io->writeln("Finished dispatching " . $count);

            return Command::SUCCESS;

        }

        $qb = $this->sourceRepository->createQueryBuilder('s');
        if ($from) {
            $qb->andWhere('s.locale = :from')
                ->setParameter('from', $from);
            // hmm.  target marking?
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $rows = $qb
            ->getQuery()
            ->toIterable();

        $items = [];
        $count = $this->sourceRepository->count(['locale' => $from]);
        $progressBar = new ProgressBar($io, $count);
        $progressBar->start();
        foreach ($rows as $idx => $row) {
            $progressBar->advance();
            $items[] = $row->getText();
            if ( (count($items) > $batch) || ($progressBar->getProgress() >= $idx)) {
                $results = $this->dispatch($from, $to, $items, $transport);
                if ($io->isVeryVerbose()) {
                    dump($results);
                }
                $items=[];
            }
        }

        $progressBar->finish();
        $io->writeln("\nFinished dispatching " . $idx+1 . "\n");
        assert(count($items) == 0, sprintf(" %d <> %d", $progressBar->getProgress(), $idx));

        return Command::SUCCESS;
    }

    private function dispatch(string $locale, array $to, array $items, ?string $transport): array
    {
        return $this->intake->handle(new BatchRequest(
            source: $locale,
            target: $to,
            texts: $items,
            engine: 'libre',
            insertNewStrings: true, // new translation targets
            forceDispatch: true,
            transport: $transport,
        ));
    }

}
