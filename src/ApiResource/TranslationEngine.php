<?php

declare(strict_types=1);
namespace App\ApiResource;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, GetCollection};
use App\State\TranslationEngineProvider;

#[ApiResource(operations: [new GetCollection(uriTemplate: '/translation-engines', provider: TranslationEngineProvider::class)], paginationEnabled: false)]
final readonly class TranslationEngine
{
    public function __construct(
        #[ApiProperty(identifier: true)] public string $engine,
        public array $profile,
        public array $languagePairs,
    ) {}
}
