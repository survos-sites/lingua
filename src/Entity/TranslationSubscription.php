<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TranslationSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

/**
 * "Tell me when THIS target is translated, at THIS url, and call it THIS."
 *
 * ## Why this is its own table and not three columns on Target
 *
 * A {@see Target} is `(source, targetLocale, engine)` and nothing else, and {@see Source} is
 * deduplicated by a hash of the text — so when two apps ask lingua to translate "Untitled"
 * into Spanish, they get the SAME Target row. Hanging `callbackUrl` off Target would therefore
 * mean the second app to push silently steals the first app's notifications. That is not a
 * scaling concern to revisit later; with zm, bts, harvest and openfoto all pushing overlapping
 * UI strings it is the normal case on day one.
 *
 * The subscription is the per-client fact. Target stays the per-string fact.
 *
 * ## clientRef
 *
 * The subscriber's OWN identifier for the string — babel's `Str.code`. Echoing it back is what
 * lets the receiving app write `StrTranslation.strCode` directly instead of reversing lingua's
 * content hash: `Str.code` is app-assigned and is NOT `HashUtil::calcSourceKey()`, which is
 * exactly why `lingua:pull` has to rebuild a hash→code map for every row it wants. A webhook
 * that carries the client's own key skips that entirely, and follows the house rule about not
 * inventing an id when the source already has a permanent one.
 *
 * ## notifiedAt
 *
 * Set when the translation has been handed to the webhook queue — the delivery guarantee
 * belongs to Messenger from that point (see Survos\Kit\Webhook\VerifyingWebhookTransport).
 * Null means "translated but not yet announced", which is the flush query's whole selection
 * criterion, and clearing it is how you force a re-announce.
 */
#[ORM\Entity(repositoryClass: TranslationSubscriptionRepository::class)]
#[ORM\Table(name: 'translation_subscription')]
// One subscription per (target, subscriber). Re-pushing the same string is routine — it is how
// a client picks up a locale it did not ask for last time — so the intake upserts on this.
#[ORM\UniqueConstraint(name: 'subscription_uq', columns: ['target_key', 'callback_url'])]
// The flush query is "un-notified subscriptions for a translated target", in that order.
#[ORM\Index(name: 'subscription_pending_idx', columns: ['notified_at', 'callback_url'])]
#[EntityMeta(
    icon: 'tabler:webhook',
    order: 30,
    group: 'Translation',
    label: 'Subscriptions',
    description: 'Clients waiting to be told when a target string is translated.'
)]
class TranslationSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(fetch: 'EXTRA_LAZY')]
        #[ORM\JoinColumn(name: 'target_key', referencedColumnName: 'key', nullable: false, onDelete: 'CASCADE')]
        public ?Target $target = null,

        /** Absolute URL of the subscriber's /webhook/lingua endpoint. */
        #[ORM\Column(name: 'callback_url', length: 255)]
        #[Field(sortable: true, filterable: true, order: 20)]
        public string $callbackUrl = '',

        /** The subscriber's own key for this string (babel `Str.code`). */
        #[ORM\Column(name: 'client_ref', length: 64)]
        #[Field(searchable: true, order: 30)]
        public string $clientRef = '',

        #[ORM\Column(name: 'notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        #[Field(sortable: true, order: 40, format: 'datetime')]
        public ?\DateTimeImmutable $notifiedAt = null,

        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        #[Field(sortable: true, visible: false, order: 90, format: 'datetime')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable('now'),
    ) {
    }
}
