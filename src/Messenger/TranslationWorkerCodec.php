<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\TranslationWorker\TranslationJobRequest;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\Message\TranslationWorker\TranslationStatus;
use App\Message\TranslationWorker\WireShape;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The ONE mapping between the worker DTOs and wire JSON.
 *
 * Mechanical on purpose, so the Python side can be generated rather than hand-kept in sync:
 * every constructor property becomes its snake_case name (PropertyNormalizer, so a method such
 * as isCompleted() never leaks onto the wire), nested DTOs become objects, enums their value,
 * nulls are omitted. The envelope fields `type` and `schema_version` come from each
 * DTO's #[WireShape] and are checked on decode.
 *
 * Its own Serializer instance, not the app's: app-wide normalizer or name-converter config must
 * never be able to change bytes on a cross-language wire.
 */
final class TranslationWorkerCodec
{
    public const int MAX_BODY_BYTES = 1_048_576;
    public const int MAX_TEXT_LENGTH = 200_000;
    private const string ID_PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/D';

    private readonly Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer(
            [new BackedEnumNormalizer(), new PropertyNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter(), propertyTypeExtractor: new ReflectionExtractor())],
            [new JsonEncoder()],
        );
    }

    public function encode(TranslationJobRequest|TranslationResultMessage $message): string
    {
        $shape = WireShape::of($message::class);
        $data = [
            'schema_version' => $shape->version,
            'type' => $shape->type,
            ...$this->serializer->normalize($message, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]),
        ];

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @template T of TranslationJobRequest|TranslationResultMessage
     *
     * @param class-string<T> $class
     *
     * @return T
     *
     * @throws \InvalidArgumentException for anything that is not a valid message of that class
     */
    public function decode(string $body, string $class): object
    {
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Body exceeds '.self::MAX_BODY_BYTES.' bytes.');
        }

        try {
            $data = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Body is not JSON: '.$e->getMessage(), 0, $e);
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Body is not a JSON object.');
        }
        $shape = WireShape::of($class);
        if (($data['type'] ?? null) !== $shape->type) {
            throw new \InvalidArgumentException('type must be '.$shape->type.'.');
        }
        if (($data['schema_version'] ?? null) !== $shape->version) {
            throw new \InvalidArgumentException('Unsupported schema_version for '.$shape->type.' (expected '.$shape->version.').');
        }
        unset($data['schema_version'], $data['type']);

        try {
            $message = $this->serializer->denormalize($data, $class, 'json');
        } catch (SerializerException $e) {
            throw new \InvalidArgumentException('Body does not match '.$shape->type.': '.$e->getMessage(), 0, $e);
        }

        $this->check($message);

        return $message;
    }

    /** Rules a type system cannot express. Keep in sync with contract-v1.md. */
    private function check(TranslationJobRequest|TranslationResultMessage $m): void
    {
        foreach (['request_id' => $m->requestId, 'profile' => $m->profile, 'source_locale' => $m->sourceLocale, 'target_locale' => $m->targetLocale] as $field => $value) {
            if (!preg_match(self::ID_PATTERN, $value)) {
                throw new \InvalidArgumentException($field.' must be a bounded identifier string.');
            }
        }

        if ($m instanceof TranslationJobRequest) {
            return;
        }

        if ($m->status === TranslationStatus::Completed) {
            if ($m->translatedText === null) {
                throw new \InvalidArgumentException('A completed result needs translated_text.');
            }
            if (mb_strlen($m->translatedText) > self::MAX_TEXT_LENGTH) {
                throw new \InvalidArgumentException('translated_text is too long.');
            }
            if ($m->provenance === null) {
                throw new \InvalidArgumentException('A completed result needs provenance.');
            }
        } elseif ($m->error === null) {
            throw new \InvalidArgumentException('A failed result needs error.');
        }
    }
}
