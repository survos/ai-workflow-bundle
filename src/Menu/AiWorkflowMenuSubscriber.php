<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class AiWorkflowMenuSubscriber
{
    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $event->getMenu()->addChild('AI Workflow');
        $submenu->addChild('Tasks', ['route' => 'survos_ai_workflow_tasks']);
    }
}
