<?php

namespace App\Menu;

use App\Workflow\TargetWorkflowInterface;
use Survos\FieldBundle\Enum\Purpose;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

final class AppMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        #[Autowire('%kernel.enabled_locales%')] private array $enabled_locales,
        private EntityMetaRegistry $entityMetaRegistry,
        private RouteMetaRegistry $routeMetaRegistry,
        private Security $security,
        protected readonly ?RouterInterface $router = null,
        protected readonly ?RouteAliasService $routeAliasService = null,
        protected readonly ?IconService $iconService = null,
    ) {
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_LANGUAGE)]
    public function languageMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        foreach ($this->enabled_locales as $locale) {
            $this->add($menu, 'app_test_api', ['locale' => $locale], label: strtoupper($locale), icon: 'language');
        }
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Dashboard', icon: 'dashboard');

        $browseMenu = $this->addSubmenu($menu, 'Browse', icon: 'list');
        foreach ($this->entityMetaRegistry->getByGroup('Translation') as $entityMeta) {
            $route = $this->routeMetaRegistry->forEntityPurpose($entityMeta->class, Purpose::List);
            if ($route === null) {
                continue;
            }

            $this->add($browseMenu, $route->name, label: $entityMeta->label, icon: $entityMeta->icon);
        }

        $sourcesMenu = $this->addSubmenu($browseMenu, 'Sources by Locale', icon: 'language');
        $this->add($sourcesMenu, 'app_browse_source', label: 'All Sources');
        foreach ($this->enabled_locales as $locale) {
            $this->add($sourcesMenu, 'app_browse_source', ['locale' => $locale], label: strtoupper($locale));
        }

        $targetsMenu = $this->addSubmenu($browseMenu, 'Targets by State', icon: 'workflow');
        $this->add($targetsMenu, 'app_browse_target', label: 'All Targets');
        foreach (TargetWorkflowInterface::PLACES as $place) {
            $this->add($targetsMenu, 'app_browse_target', ['marking' => $place], label: $place);
        }

        $engineMenu = $this->addSubmenu($browseMenu, 'Targets by Engine', icon: 'settings');
        foreach (['libre', 'bing'] as $engine) {
            $this->add($engineMenu, 'app_browse_target', ['engine' => $engine], label: ucfirst($engine));
        }
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU_END)]
    public function toolsMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, 'admin', label: 'Admin', icon: 'settings');

        $commandsMenu = $this->addSubmenu($menu, 'Commands', icon: 'terminal');
        $this->add($commandsMenu, 'survos_commands', label: 'All Commands');
        foreach (['app:dispatch', 'app:export', 'lingua:demo'] as $command) {
            $this->add($commandsMenu, 'survos_command', ['commandName' => $command], label: $command);
        }
    }

    #[AsEventListener(event: MenuEvent::FOOTER_END)]
    public function footerMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Lingua');
        if ($this->env === 'dev' || $this->security->isGranted('ROLE_ADMIN')) {
            $this->add($menu, 'survos_commands', label: 'Commands');
        }
    }
}
