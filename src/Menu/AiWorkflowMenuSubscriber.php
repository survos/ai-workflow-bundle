<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;

final class AiWorkflowMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string
    {
        return 'AI Workflow';
    }

    protected function getResourceClasses(): array
    {
        return [];
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel());
        $this->add($submenu, 'survos_ai_workflow_subjects', [], 'Subjects');
        $this->add($submenu, 'survos_ai_workflow_tasks', [], 'Tasks');
    }
}
