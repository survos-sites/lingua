<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TranslationJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

/**
 * One request to an out-of-process translation worker, keyed by the wire `request_id`.
 *
 * Written in the same DB transaction as the outbox message that publishes it, so a job either
 * exists AND will be published, or neither. The input is snapshotted here rather than re-read
 * from Source at publish time: the wire job is self-contained, and the result is checked
 * against exactly what was sent (see {@see self::$sourceHash}).
 *
 * The Target stays the per-string fact (and translation memory). This row is per-attempt:
 * a forceDispatch supersedes the previous active job, and a late result for a superseded job
 * is recorded as stale instead of overwriting the newer outcome.
 */
#[ORM\Entity(repositoryClass: TranslationJobRepository::class)]
#[ORM\Table(name: 'translation_job')]
#[ORM\Index(name: 'translation_job_target_status_idx', columns: ['target_key', 'status'])]
#[EntityMeta(
    icon: 'tabler:list-check',
    order: 40,
    group: 'Translation',
    label: 'Worker jobs',
    description: 'Translation requests sent to queue workers, with their results and provenance.'
)]
class TranslationJob
{
    public const string STATUS_PENDING = 'pending';       // committed, outbox not yet published
    public const string STATUS_DISPATCHED = 'dispatched'; // broker confirmed the publish
    public const string STATUS_COMPLETED = 'completed';   // result applied to the Target
    public const string STATUS_FAILED = 'failed';         // worker failure, or empty translation
    public const string STATUS_SUPERSEDED = 'superseded'; // a newer job replaced this one

    public const array ACTIVE = [self::STATUS_PENDING, self::STATUS_DISPATCHED];
    public const array TERMINAL = [self::STATUS_COMPLETED, self::STATUS_FAILED];

    #[ORM\Column(length: 16)]
    #[Field(sortable: true, filterable: true, facet: true, order: 30)]
    public string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $dispatchedAt = null;

    #[ORM\Column(nullable: true)]
    #[Field(sortable: true, order: 60, format: 'datetime')]
    public ?\DateTimeImmutable $completedAt = null;

    /** Raw worker output, kept even when identical to the source. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $translatedText = null;

    /** translated | identical | failed | stale — what the result meant for the Target. */
    #[ORM\Column(length: 16, nullable: true)]
    #[Field(sortable: true, filterable: true, facet: true, order: 40)]
    public ?string $outcome = null;

    /** @var array<string, string|int|float|null> worker provenance, snake_case as on the wire */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $provenance = [];

    /** @var array<string, int|float> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $metrics = [];

    /** @var array{code?:string, message?:string, retryable?:bool} */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $error = [];

    /** Results received after this job already reached a terminal state. */
    #[ORM\Column(options: ['default' => 0])]
    public int $duplicateResults = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        #[Field(searchable: true, order: 10)]
        public string $requestId,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'target_key', referencedColumnName: 'key', nullable: false, onDelete: 'CASCADE')]
        public Target $target,

        #[ORM\Column(length: 100)]
        #[Field(sortable: true, filterable: true, facet: true, order: 20)]
        public string $profile,

        #[ORM\Column(length: 6)]
        public string $sourceLocale,

        #[ORM\Column(length: 6)]
        public string $targetLocale,

        #[ORM\Column(type: Types::TEXT)]
        public string $inputText,

        /** Source::$hash at dispatch time; a mismatch on result means the input changed. */
        #[ORM\Column(length: 32)]
        public string $sourceHash,

        #[ORM\Column]
        #[Field(sortable: true, order: 50, format: 'datetime')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable('now'),
    ) {
    }

    public bool $isActive { get => \in_array($this->status, self::ACTIVE, true); }
    public bool $isTerminal { get => \in_array($this->status, self::TERMINAL, true); }
}
