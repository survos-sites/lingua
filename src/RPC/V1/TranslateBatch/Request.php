<?php

declare(strict_types=1);

namespace App\RPC\V1\TranslateBatch;

/**
 * params for the `translateBatch` JSON-RPC method -- the write half of the lingua contract.
 *
 * Mirrors Survos\Lingua\Contracts\Dto\BatchRequest, which POST /batch-translate takes via
 * #[MapRequestPayload]. Kept as its own class rather than reusing the contracts DTO because
 * the bundle imposes a shape on request DTOs (see below) that the framework-agnostic
 * contracts package should not have to adopt.
 *
 * Accessor boilerplate is the library's contract, not a style choice: CompilerPass requires
 * a getter AND a setter per property or the container will not build. Raised upstream as
 * https://github.com/OtezVikentiy/symfony-jsonrpc-api-bundle/issues/11 -- if that lands,
 * this class and PullTranslations\Request both collapse to promoted public properties.
 *
 * Required (constructor) vs optional (setters) is the bundle's rule, and it maps cleanly
 * here: you cannot translate without a source locale, target locales and texts. Everything
 * else has a sane default.
 *
 * Element types are checked by hand for the two list params. The bundle derives validators
 * from property types only, so `array` is as deep as its check goes -- exactly the hole that
 * made a nested array in pullTranslations surface as -32603 instead of -32602.
 */
final class Request
{
    /** @var list<string> */
    private array $target;

    /** @var list<string> */
    private array $texts;

    private ?string $engine;

    // Defaults live on the PROPERTIES, not just on the constructor parameters. The bundle
    // decides required-vs-optional from the property declaration
    // (`$propertyType->allowsNull() || $property->hasDefaultValue()`), so a bool that was
    // defaulted only in the constructor signature came back as
    // "[insertNewStrings] - This field is missing." for every caller that omitted it.
    private bool $insertNewStrings = true;
    private bool $forceDispatch = false;
    private ?string $transport;

    /** Where to POST translation.completed. Null keeps the old poll-with-lingua:pull behaviour. */
    private ?string $callbackUrl;

    /** @var list<string> caller's own key per text, aligned with $texts */
    private array $refs = [];

    /**
     * @param string       $source source locale, e.g. "en"
     * @param list<string> $target target locales, e.g. ["es","fr"]
     * @param list<string> $texts  source strings
     */
    public function __construct(
        private string $source,
        array $target = [],
        array $texts = [],
        ?string $engine = null,
        bool $insertNewStrings = true,
        bool $forceDispatch = false,
        ?string $transport = null,
        ?string $callbackUrl = null,
        array $refs = [],
    ) {
        $this->setTarget($target);
        $this->setTexts($texts);
        $this->engine = $engine;
        $this->insertNewStrings = $insertNewStrings;
        $this->forceDispatch = $forceDispatch;
        $this->transport = $transport;
        $this->callbackUrl = $callbackUrl;
        $this->setRefs($refs);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    /** @return list<string> */
    public function getTarget(): array
    {
        return $this->target;
    }

    /**
     * @param  list<string> $target
     * @throws \TypeError   which the bundle converts to -32602 Invalid params
     */
    public function setTarget(array $target): void
    {
        $this->assertAllStrings('target', $target);
        $this->target = $target;
    }

    /** @return list<string> */
    public function getTexts(): array
    {
        return $this->texts;
    }

    /**
     * @param  list<string> $texts
     * @throws \TypeError   which the bundle converts to -32602 Invalid params
     */
    public function setTexts(array $texts): void
    {
        $this->assertAllStrings('texts', $texts);
        $this->texts = $texts;
    }

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function setEngine(?string $engine): void
    {
        $this->engine = $engine;
    }

    public function getInsertNewStrings(): bool
    {
        return $this->insertNewStrings;
    }

    public function setInsertNewStrings(bool $insertNewStrings): void
    {
        $this->insertNewStrings = $insertNewStrings;
    }

    public function getForceDispatch(): bool
    {
        return $this->forceDispatch;
    }

    public function setForceDispatch(bool $forceDispatch): void
    {
        $this->forceDispatch = $forceDispatch;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function setTransport(?string $transport): void
    {
        $this->transport = $transport;
    }

    public function getCallbackUrl(): ?string
    {
        return $this->callbackUrl;
    }

    public function setCallbackUrl(?string $callbackUrl): void
    {
        $this->callbackUrl = $callbackUrl;
    }

    /** @return list<string> */
    public function getRefs(): array
    {
        return $this->refs;
    }

    /**
     * @param  list<string> $refs
     * @throws \TypeError   which the bundle converts to -32602 Invalid params
     */
    public function setRefs(array $refs): void
    {
        $this->assertAllStrings('refs', $refs);
        $this->refs = $refs;
    }

    /** @param array<mixed> $values */
    private function assertAllStrings(string $field, array $values): void
    {
        foreach ($values as $i => $value) {
            if (!is_string($value)) {
                throw new \TypeError(sprintf(
                    '%s[%s] must be of type string, %s given.',
                    $field,
                    (string) $i,
                    get_debug_type($value),
                ));
            }
        }
    }
}
