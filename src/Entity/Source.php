<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\SourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Survos\Lingua\Core\Identity\HashUtil;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\UniqueConstraint(
    name: 'source_hash',
    columns: ['hash'],
)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource]
#[Get]
#[GetCollection]
#[EntityMeta(
    icon: 'tabler:file-text',
    order: 10,
    group: 'Translation',
    label: 'Sources',
    description: 'Original source strings submitted for translation.'
)]
class Source implements RouteParametersInterface, \Stringable
{
    use RouteParametersTrait;

    const UNIQUE_PARAMETERS=['sourceId' => 'id'];
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    #[Groups(['source.read', 'source.export'])]
    private ?int $id = null;

    public function getId(): int
    {
        return $this->id;
    }

    #[ORM\Column(nullable: true, options: ['jsonb' => true])]
    #[Groups(['source.read'])]
    #[Field(visible: false, order: 80)]
    public ?array $localeStatuses = null;

    public function __construct(
        #[ORM\Column(type: Types::TEXT)]
        #[Groups(['source.read', 'source.write'])]
        #[Field(searchable: true, visible: false, order: 50)]
        private(set) ?string $text = null,

        #[ORM\Column(length: 6)]
        #[Groups(['source.read', 'source.write'])]
        #[Field(sortable: true, filterable: true, widget: Widget::Select, facet: true, order: 20, width: '6rem')]
        private(set) ?string $locale = null,

        #[ORM\Column(length: 18)] // 16 chars + 2 for locale
        #[Groups(['source.read'])]
        #[ApiProperty(identifier: true)]
        #[Field(searchable: true, sortable: true, order: 10, width: '12rem')]
        private(set) ?string $hash = null,


    ) {
        if (null === $this->hash) {
            if ($this->text) {
                $this->hash = HashUtil::calcSourceKey( $this->text, $this->locale );
            }
        }
        $this->targets = new ArrayCollection();
    }

    public function hasTranslationFor($loc): bool {
        return $this->localeStatuses ? array_key_exists($loc, $this->localeStatuses) : false;
    }


    #[Field(searchable: true, order: 30)]
    public $snippet {
        get => mb_substr($this->text, 0, 40, 'UTF-8');
    }
    #[Field(sortable: true, filterable: true, widget: Widget::Range, order: 40, width: '7rem')]
    public $length {
        get => mb_strlen($this->text);
    }




    /**
     * @var Collection<int, Target>
     */
    #[ORM\OneToMany(targetEntity: Target::class, mappedBy: 'source',
        orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['engine' => \SortDirection::Descending])] // so bing will overwrite libre
    #[Groups(['source.export'])]
    private Collection $targets;

    #[ORM\Column(nullable: true, type: Types::JSON, options: ['jsonb' => true])]
    #[Groups(['source.read'])]
//    #[Groups(['source.translations'])]
    #[Field(visible: false, order: 90)]
    public ?array $translations = null;


    public function getText(?int $trim=null): ?string
    {
        return $trim ? substr($this->text, 0, $trim): $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /**
     * @return Collection<int, Target>
     */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    public function addTarget(Target $target): static
    {
        if (!$this->targets->contains($target)) {
            $this->targets->add($target);
            // hmm. Could also be a key to save the lookup?
            if (!in_array($target->targetLocale, $this->translations ?? [])) {
                $this->translations[] = $target->targetLocale;
            }
            $target->source = $this;
        }

        return $this;
    }

    public function removeTarget(Target $target): static
    {
        if ($this->targets->removeElement($target)) {
            // set the owning side to null (unless already changed)
            if ($target->source === $this) {
                $target->source = null;
            }
        }

        return $this;
    }

    #[ORM\PreFlush]
    public function updateTranslations(): array
    {
        $translations = [];
        // bing is first, since engines are alphabetical.  hackish
//        $translations[$this->getLocale()] = $this->getText();
        foreach ($this->targets as $target) {
            // ordered by engine desc, okay for now...
            // bing overrides libre, but we could also have custom or deepl, so not very elegant
            if ($target->targetText) {
                $translations[$target->targetLocale] = $target->targetText;
            }
//            if (empty($translations[$target->getTargetLocale()])) {
//                $translations[$target->getTargetLocale()] = $target->getTargetText();
//            }
        }
        $this->translations = $translations;
        return $translations;

    }


    public function __toString(): string
    {
        return $this->snippet;
    }
}
