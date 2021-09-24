<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuService implements ItemInterface
{
    public function getName(): string
    {
        return 'Cut multimedia object';
    }

    public function getUri(): string
    {
        return 'pumukit_videocut';
    }

    public function getAccessRole(): string
    {
        return 'ROLE_ACCESS_MULTIMEDIA_SERIES';
    }

    public function isFullscreen(): bool
    {
        return true;
    }

    public function getIcon(): string
    {
        return 'mdi-content-content-cut';
    }

    public function getServiceTag(): string
    {
        return 'mmobjmenu';
    }
}
