<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\TargetRepository;
use App\Workflow\TargetWorkflowInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\Lingua\Core\Identity\HashUtil;

use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: TargetRepository::class)]
#[ORM\UniqueConstraint(
    name: 'target_unique_idx',
    fields: ['targetLocale', 'source', 'engine']
)]
#[ORM\Index(name: 'target_source', fields: ['source'])]
#[ORM\Index(name: 'target_marking', fields: ['marking'])]
#[ORM\HasLifecycleCallbacks]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'key'          => 'exact',
    'targetLocale' => 'exact',
    'marking'      => 'exact',
    'engine'       => 'exact',
])]
#[ApiResource]
#[Get]
#[GetCollection]
#[EntityMeta(
    icon: 'tabler:language',
    order: 20,
    group: 'Translation',
    label: 'Targets',
    description: 'Translated target strings by locale, engine, and workflow state.'
)]
class Target implements RouteParametersInterface, MarkingInterface
{
    use MarkingTrait;
    use RouteParametersTrait;

    public const UNIQUE_PARAMETERS = ['targetId' => 'key'];

    public function __construct(
        #[ORM\ManyToOne(fetch: 'EXTRA_LAZY', inversedBy: 'targets')]
        #[ORM\JoinColumn(nullable: false)]
        public ?Source $source = null,

        #[ORM\Column(length: 6)]
        #[Groups(['target.read', 'target.write', 'source.export'])]
        #[Field(sortable: true, filterable: true, widget: Widget::Select, facet: true, order: 20, width: '6rem')]
        public ?string $targetLocale = null,

        #[ORM\Column(length: 12, nullable: false, options: ['default' => 'libre'])]
        #[Groups(['target.read', 'target.write', 'source.export'])]
        #[Field(sortable: true, filterable: true, widget: Widget::Select, facet: true, order: 30, width: '7rem')]
        public string $engine = 'libre',

        #[ORM\Id]
        #[ORM\Column(length: 32)]
        #[Field(searchable: true, sortable: true, order: 10, width: '14rem')]
        public ?string $key = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        #[Groups(['target.read', 'target.write', 'source.export'])]
        #[Field(searchable: true, visible: false, order: 70)]
        public ?string $targetText = null,

        #[ORM\Column(nullable: false)]
        #[Field(sortable: true, visible: false, order: 90, format: 'datetime')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable('now'),

        #[ORM\Column(nullable: true)]
        #[Field(sortable: true, order: 80, format: 'datetime')]
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ($this->source) {
            $this->source->addTarget($this);

            $computedKey = self::calcKey($this->source, $this->targetLocale ?? '', $this->engine);

            if ($this->key === null) {
                $this->key = $computedKey;
            } else {
                \assert(
                    $this->key === $computedKey,
                    sprintf('Target key mismatch: "%s" != "%s"', $this->key, $computedKey)
                );
            }
        }

        // default marking if not set by MarkingTrait
        $this->marking ??= TargetWorkflowInterface::PLACE_UNTRANSLATED;
    }

    /**
     * The locale this row was translated FROM, when that was not the source locale.
     *
     * Null = translated directly from Source::$text. 'en' = produced by the English-hub
     * route: source→en first, then en→this locale, reusing the stored English rather than
     * making the engine pivot through an English we never see.
     *
     * A column rather than a suffix on `engine`, for two reasons. A pivoted row is produced by
     * TWO engine calls — potentially a paid one for →en and a free one for en→here — so
     * `engine` alone can no longer describe it. And `engine` is validated against
     * TranslatorManager::names() (TargetWorkflow's Assert::inArray), which a composite value
     * like "libre:via-en" would fail.
     *
     * NOT part of the key: {@see calcKey()} stays (sourceHash, targetLocale, engine), so a row
     * remains findable by exactly what the client asked for. How it was produced is provenance,
     * not identity.
     */
    #[ORM\Column(name: 'pivot_locale', length: 6, nullable: true)]
    #[Groups(['target.read', 'source.export'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Select, facet: true, order: 35, width: '6rem')]
    public ?string $pivotLocale = null;

    #[Field(searchable: true, order: 50)]
    public $snippet {
        get => \mb_substr($this->targetText ?? '', 0, 40, 'UTF-8');
    }

    #[Field(sortable: true, filterable: true, widget: Widget::Range, order: 60, width: '7rem')]
    public $length {
        get => \mb_strlen($this->targetText ?? '');
    }

    public bool $isTranslated { get => $this->marking === TargetWorkflowInterface::PLACE_TRANSLATED; }
    public bool $isIdentical { get => $this->marking === TargetWorkflowInterface::PLACE_IDENTICAL; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updatedTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public static function calcKey(Source $source, string $targetLocale, ?string $engine = null): string
    {
        return HashUtil::calcTranslationKey($source->hash, $targetLocale, $engine);
    }

    public function getId(): string
    {
        return $this->key ?? '';
    }
}
