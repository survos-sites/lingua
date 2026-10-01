<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

return static function (ContainerConfigurator $container): void {
    // Symfony 7.4 requires this opt-in; Symfony 8.1 deprecates the option itself.
    if (Kernel::VERSION_ID < 80100 && in_array($container->env(), ['dev', 'test'], true)) {
        $container->extension('framework', ['profiler' => ['collect_serializer_data' => true]]);
    }
};
