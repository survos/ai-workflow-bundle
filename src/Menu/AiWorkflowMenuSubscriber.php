<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class AiWorkflowMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string { return 'AI Workflow'; }
    protected function getResourceClasses(): array { return []; }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel());
        $this->add($submenu, 'survos_ai_workflow_subjects', [], 'Subjects');
        $this->add($submenu, 'survos_ai_workflow_tasks', [], 'Tasks');
    }
}
