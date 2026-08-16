<?php

declare(strict_types=1);

namespace App\RPC\V1\PullTranslations;

/**
 * result for the `pullTranslations` JSON-RPC method.
 *
 * Public promoted properties, no accessors: the bundle's serialiser exports a property that
 * the class makes public *or* exposes through a public getter, and says so explicitly --
 * requiring a getter on top "would protect nothing and would drop the promoted public
 * properties that are the shortest honest way to write a response DTO"
 * (Core/Serialization/SerialisesPublicSurface). Same shape as the contracts DTOs.
 *
 * Deliberately NOT the bare map that POST /babel/pull returns: `missing` is reported. The
 * REST response omits unknown hashes exactly the way it omits known-but-untranslated ones, so
 * a caller receiving 18 of 20 cannot tell which two to queue and which two to wait for. Same
 * reasoning as mediary's probeAssets, and the whole point of migrating this method first.
 *
 * KNOWN, NOT FIXED: an empty `translations` still serialises as `[]` rather than `{}`, so its
 * JSON type flips for a strictly-typed client -- the same wart the REST endpoint has. It is
 * not reachable from here: the bundle's serialiser normalises every value to a PHP array
 * before encoding (Core/Serialization/SerialisesPublicSurface::normaliseValue), and an empty
 * PHP array encodes as `[]` whether it started as an array or an object. Fixing it means
 * changing the shape -- a list of {hash, text} entries never flips -- which is a heavier
 * contract for callers and was not worth it just to settle the empty case. `missing` already
 * removes the ambiguity that mattered.
 */
final readonly class Response
{
    /**
     * @param array<string,string> $translations map[sourceHash => translatedText]
     * @param list<string>         $missing      requested hashes with no matching translation
     */
    public function __construct(
        public array $translations = [],
        public array $missing = [],
    ) {
    }
}
