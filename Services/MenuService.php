<?php

namespace Pumukit\HardVideoEditorBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuService implements ItemInterface
{
    public function getName()
    {
        return 'Cut multimedia object';
    }

    public function getUri()
    {
        return 'pumukit_videocut';
    }

    public function getAccessRole()
    {
        return 'ROLE_ACCESS_MULTIMEDIA_SERIES';
    }

    public function isFullscreen()
    {
        return true;
    }
}
