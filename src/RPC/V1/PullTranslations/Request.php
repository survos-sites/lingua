<?php

declare(strict_types=1);

namespace App\RPC\V1\PullTranslations;

/**
 * params for the `pullTranslations` JSON-RPC method.
 *
 * The REST equivalent (POST /babel/pull) hand-parses $request->toArray(), checks `hashes` by
 * hand, and answers with three different ad-hoc {"error": "..."} bodies at HTTP 400. Here the
 * bundle deserialises and type-checks params before the method body runs, and a violation
 * comes back as -32602 Invalid params naming the offending field.
 *
 * ## Why this carries boilerplate accessors, against CONVENTIONS.md
 *
 * The accessors are the library's contract, not a style choice, and the build fails without
 * them: `CompilerPass::extractAttributeMetadata()` throws
 * "Property hashes ... has no accessible getter" for any property lacking one, and the
 * hydrator applies optional params through a public `set<Name>()` looked up by name. Tried
 * public promoted properties first and the container would not compile. Same shape as
 * mediary's `App\RPC\V1\ProbeAssets\Request`, so the two RPC implementations match.
 *
 * The *Response* has no such constraint and is written the conventional way -- public
 * promoted readonly, no accessors.
 *
 * Required vs optional is also the bundle's rule: a required param is passed to the
 * constructor (`RequestHandler::instantiateRequest()`), an optional one -- nullable or
 * defaulted -- is applied afterwards via its setter. So `hashes` is required and the two
 * filters are nullable.
 *
 * Element types are checked by hand, because the bundle compiles validators from *property
 * types* only (docs/validation.md) and ignores Assert attributes on the DTO. `array` is as
 * deep as it looks: `{"hashes":[{"a":1}]}` satisfies it and then strval() throws downstream,
 * which surfaced as -32603 Internal error instead of -32602. Verified live before and after.
 */
final class Request
{
    /** @var list<string> 16-hex source hashes (Source.hash, NOT Target.key) */
    private array $hashes;

    /** Restrict to one target locale, e.g. "es". Null means every locale lingua has. */
    private ?string $locale;

    /** Restrict to one engine, e.g. "libre"/"deepl". Null means any. */
    private ?string $engine;

    /** @param list<string> $hashes */
    public function __construct(array $hashes = [], ?string $locale = null, ?string $engine = null)
    {
        $this->setHashes($hashes);
        $this->locale = $locale;
        $this->engine = $engine;
    }

    /** @return list<string> */
    public function getHashes(): array
    {
        return $this->hashes;
    }

    /**
     * @param  list<string> $hashes
     * @throws \TypeError   which the bundle converts to -32602 Invalid params
     */
    public function setHashes(array $hashes): void
    {
        foreach ($hashes as $i => $hash) {
            if (!is_string($hash)) {
                throw new \TypeError(sprintf(
                    'hashes[%s] must be of type string, %s given.',
                    (string) $i,
                    get_debug_type($hash),
                ));
            }
        }

        $this->hashes = $hashes;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function setEngine(?string $engine): void
    {
        $this->engine = $engine;
    }
}
