<?php

namespace App\Controller;

use App\Entity\Source;
use App\Entity\Target;
use App\Form\TranslationPayloadFormType;
use App\Repository\SourceRepository;
use App\Repository\TargetRepository;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Workflow\StrTrWorkflowInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Vanderlee\Sentence\Sentence;


final class AppController extends AbstractController
{

    public function __construct(
        private SourceRepository                              $sourceRepository,
        private TargetRepository                              $targetRepository,
        private EntityManagerInterface                        $entityManager,
        private ChartBuilderInterface                         $chartBuilder,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales,
    )
    {

    }

    // src/Controller/HealthController.php
    #[Route('/health')]
    public function __invoke(): Response
    {
        return new Response('ok');
    }

    #[Route('/{locale}/test-api', name: 'app_test_api')]
    #[RouteMeta(
        description: 'Interactive form for testing batch translation requests across configured locales.',
        purpose: Purpose::Custom,
        label: 'Test API',
        audience: Audience::Authenticated,
        tags: ['tools', 'translation']
    )]
    public function testApi(
        ApiController                                    $apiController,
        Request                                          $request,
        #[Autowire('%env(TRANSLATOR_ENDPOINT)%')] string $translationServer,
        #[MapQueryParameter] bool                        $force = false,
        string $locale='en'

    ): Response
    {
        $examples = [
            'en' => ['good morning', 'hello, world'],
            'es' => ['hola','taza'],
        ];
        $enabled = array_filter($this->enabledLocales, fn(string $x) => $x <> $locale);
        $payload = new BatchRequest(
            source: $locale, engine: 'libre', target: $enabled, texts: $examples[$locale]??[]);
        $form = $this->createForm(TranslationPayloadFormType::class, $payload, [
//            'action' => $this->generateUrl('api_queue_translation'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);
//        dd($form->getData());
        //  && $form->isValid()
        if ($form->isSubmitted()) {
//            $response = $this->forward(ApiController::class . '::dispatch',
//                ['payload' => $form->getData()]);
////            dd($response);
            $response = json_decode($apiController->batchRequest($payload)->getContent(), true);
//            dd($response, $payload, $force);
        }

        return $this->render("app/test-api.html.twig", [
            'translationServer' => $translationServer,
            'payload' => $payload,
            'response' => $response??null,
            'form' => $form->createView()
        ]);
    }



    private function createChart(array $data, int $size = 40): Chart
    {
        // map marking code -> color
        $colorMap = [
            't'     => 'rgb(25, 135, 84)',   // translated (green)
            'u'     => 'rgb(255, 193, 7)',   // untranslated/queued (yellow)
            'i'     => 'rgb(220, 53, 69)',   // identical / error (red)
            'total' => 'rgba(108,117,125,0.4)',
        ];

        // drop "total" & zeros
        $filtered = array_filter(
            $data,
            static fn (int|float $value, string $key) => $value > 0 && $key !== 'total',
            ARRAY_FILTER_USE_BOTH
        );

        if (!$filtered) {
            $filtered = ['u' => 1];
        }

        $labels = array_keys($filtered);
        $values = array_values($filtered);
        $colors = array_map(
            static fn (string $label) => $colorMap[$label] ?? $colorMap['total'],
            $labels
        );

        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);

        // 🟢 THIS is what controls pixel size in UX-Chartjs
//        $chart->setMaxSize($size, $size);

        $chart->setData([
            'labels'   => $labels,
            'datasets' => [[
                'borderWidth'     => 0,
                'backgroundColor' => $colors,
                'data'            => $values,
            ]],
        ]);

        $chart->setOptions([
            'responsive'        => true,   // let it respect maxSize / container
            'maintainAspectRatio' => true,
            'plugins'           => [
                'legend' => ['display' => false],
            ],
        ]);

        return $chart;
    }

    #[Route('/', name: 'app_homepage')]
    public function home(): Response
    {
        return $this->render('app/home.html.twig', [
            'locales' => $this->enabledLocales,
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[Route('/admin/charts', name: 'admin_app_charts')]
    public function charts(): Response
    {
        // entity counts (unchanged)
        $counts = [];
        foreach ([Source::class, Target::class] as $class) {
            $counts[$class] = [
                'count' => $this->entityManager->getRepository($class)->count([]),
            ];
        }

        // base structure for all markings + total
        $markingKeys  = ['t', 'u', 'i'];
        $emptyMarking = array_fill_keys($markingKeys, 0);
        $emptyMarking['total'] = 0;

        // overall totals across all language pairs
        $overall = $emptyMarking;

        // matrix[sourceLocale][targetLocale][marking|total]
        $grid = [];
        foreach ($this->enabledLocales as $sourceLocale) {
            $grid[$sourceLocale] = [];
            foreach ($this->enabledLocales as $targetLocale) {
                $grid[$sourceLocale][$targetLocale] = $emptyMarking;
            }
        }

        // fetch grouped counts (source.locale, targetLocale, marking)
        $results = $this->targetRepository->createQueryBuilder('t')
            ->join('t.source', 's')
            ->groupBy('t.targetLocale', 't.marking', 's.locale')
            ->select('t.targetLocale AS targetLocale, t.marking AS marking, s.locale AS sourceLocale, COUNT(t) AS count')
            ->getQuery()
            ->getArrayResult();

        foreach ($results as $row) {
            $sourceLocale = $row['sourceLocale'];
            $targetLocale = $row['targetLocale'];
            $marking      = $row['marking'];
            $count        = (int) $row['count'];

            // ignore anything outside the configured locale list
            if (!isset($grid[$sourceLocale][$targetLocale])) {
                continue;
            }

            $overall[$marking]      = ($overall[$marking] ?? 0) + $count;
            $overall['total']       += $count;

            $grid[$sourceLocale][$targetLocale][$marking] =
                ($grid[$sourceLocale][$targetLocale][$marking] ?? 0) + $count;
            $grid[$sourceLocale][$targetLocale]['total']  += $count;
        }

        // build global chart

        // build per-cell charts only where there is data
        $globalCharts['marking'] = [
            'data'  => $overall,
            'chart' => $this->createChart($overall, 80),   // e.g. 80px
        ];

        $charts = [];
        foreach ($grid as $sourceLocale => $targets) {
            foreach ($targets as $targetLocale => $stats) {
                if (($stats['total'] ?? 0) > 0) {
                    $sliceData = $stats;
                    unset($sliceData['total']);

                    // tiny pair charts, e.g. 40px
                    $charts[$sourceLocale][$targetLocale] = $this->createChart($sliceData, 40);
                }
            }
        }
        $recent = $this->entityManager
            ->getRepository(Source::class)
            ->findBy([],
                [
//                    'createdAt' => 'DESC'
                ],
                4);

        return $this->render('app/dashboard.html.twig', [
            'counts'        => $counts,
            'grid'          => $grid,
            'results'       => $results,
            'recent'        => $recent,
            'charts'        => $charts,
            'globalCharts'  => $globalCharts,
            'recentTargets' => $this->entityManager
                ->getRepository(Target::class)
                ->findBy(['source' => $recent], limit: 10),
        ]);
    }

    #[Route('/source/browse', name: 'app_browse_source')]
    #[Template('app/browse-source.html.twig')]
    #[RouteMeta(
        description: 'Browse source strings with optional locale filtering.',
        entity: Source::class,
        purpose: Purpose::List,
        label: 'Sources',
        audience: Audience::Authenticated,
        tags: ['translation', 'browse']
    )]
    public function browseSource(
        #[MapQueryParameter] int $limit = 500,
        #[MapQueryParameter] ?string $locale=null,
    ): Response|array
    {
        $qb = $this->sourceRepository->createQueryBuilder('s');
        if ($locale) {
            $qb->andWhere('s.locale = :locale')->setParameter('locale', $locale);
        }
        $qb->setMaxResults($limit);

        return [
            'sources' => $qb->getQuery()->getResult(),
        ];

    }

    #[Route('/target/{marking}', name: 'app_browse_target', defaults: ['marking' => null])]
    #[RouteMeta(
        description: 'Browse translated target strings by workflow marking, engine, locale, or text filters.',
        entity: Target::class,
        purpose: Purpose::List,
        label: 'Targets',
        audience: Audience::Authenticated,
        tags: ['translation', 'browse']
    )]
    public function browseTarget(
        ?string $marking=null,
        #[MapQueryParameter] int $limit = 500,
        #[MapQueryParameter] ?string $engine=null,
        #[MapQueryParameter] ?string $targetLocale=null,
        #[MapQueryParameter] ?string $q=null,
        #[MapQueryParameter] ?string $key=null,
        #[MapQueryParameter] ?string $sourceKey=null,
    ): Response
    {
        $qb = $this->targetRepository->createQueryBuilder('t');
        $qb->join('t.source', 'source');
        if ($marking) {
            $qb->andWhere('t.marking = :marking')->setParameter('marking', $marking);
        }
        if ($engine) {
            $qb->andWhere('t.engine = :engine')->setParameter('engine', $engine);
        }
        if ($targetLocale) {
            $qb->andWhere('t.targetLocale = :targetLocale')->setParameter('targetLocale', $targetLocale);
        }
        if ($q) {
            $qb->andWhere('t.targetText LIKE :q')->setParameter('q', $q);
        }
        if ($key) {
            $qb->andWhere('t.key LIKE :key')->setParameter('key', $key);
        }
        if ($sourceKey) {
            $qb->andWhere('source.hash LIKE :sourceKey')->setParameter('sourceKey', $sourceKey);
        }
        $qb->orderBy('t.updatedAt', 'DESC');
        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $targets = $qb->getQuery()->getResult();
        return $this->render("app/browse-target.html.twig", [
            'targets' => $targets,
        ]);

    }

    #[Route('/source/{hash}.{_format}', name: 'app_source')]
    #[Template('app/source.html.twig')]
    #[RouteMeta(
        description: 'Source detail page and JSON translation payload for a source hash.',
        entity: Source::class,
        purpose: Purpose::Show,
        label: 'Source',
        audience: Audience::Authenticated,
        tags: ['translation']
    )]
    public function source(
        ?string $hash,
        string $_format='html'
    ): Response|array
    {

        /** @var Source $source */
        $source = $this->sourceRepository->findOneBy(['hash' => $hash]);
        if ($_format=='json') {
            return $this->json($source->translations);
        }
        return [
            'hash' => $hash,
            'source' => $source];

    }

}
