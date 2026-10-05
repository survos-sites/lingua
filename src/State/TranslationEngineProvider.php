<?php

declare(strict_types=1);
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TranslationEngine;
use App\Service\TranslationEngineCatalog;
use Survos\TranslatorBundle\Service\TranslatorManager;

/** @implements ProviderInterface<TranslationEngine> */
final readonly class TranslationEngineProvider implements ProviderInterface
{
    public function __construct(private TranslatorManager $engines, private TranslationEngineCatalog $catalog) {}
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        foreach ($this->engines->names() as $name) {
            yield new TranslationEngine($name, $this->catalog->describe($name), $this->catalog->languages($name));
        }
    }
}
