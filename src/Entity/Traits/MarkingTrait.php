<?php

declare(strict_types=1);

namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/** The workflow marking: one place name, read and written by Symfony's method marking store. */
trait MarkingTrait
{
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    #[Groups(['transition', 'minimum', 'marking', 'searchable'])]
    public ?string $marking = null;

    public function getMarking(): ?string
    {
        return $this->marking;
    }

    public function setMarking(?string $marking, array $context = []): static
    {
        $this->marking = $marking;

        return $this;
    }
}
