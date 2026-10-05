<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\TranslationWorker\MalformedTranslationResultMessage;
use App\Message\TranslationWorker\TranslationResultMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Messenger serializer for the `translation_results` transport: plain JSON in, via
 * {@see TranslationWorkerCodec}.
 *
 * Reads the body only — no PHP class names, no `type`/`X-Message-Stamp-*` headers, because the
 * producer is Python. A body that is not a valid v1 result decodes to
 * {@see MalformedTranslationResultMessage} instead of throwing: the transport would nack a throw
 * back onto the queue, and a malformed body never becomes valid.
 */
final class TranslationResultJsonSerializer implements SerializerInterface
{
    private const int EXCERPT_BYTES = 2_000;

    public function __construct(private readonly TranslationWorkerCodec $codec = new TranslationWorkerCodec())
    {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'];

        try {
            return new Envelope($this->codec->decode($body, TranslationResultMessage::class));
        } catch (\InvalidArgumentException $e) {
            return new Envelope(new MalformedTranslationResultMessage(mb_substr($e->getMessage(), 0, 500), mb_strcut($body, 0, self::EXCERPT_BYTES)));
        }
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof TranslationResultMessage) {
            throw new MessageDecodingFailedException(\sprintf('Cannot encode %s as a translation.result.', get_debug_type($message)));
        }

        return ['body' => $this->codec->encode($message), 'headers' => ['Content-Type' => 'application/json']];
    }
}
