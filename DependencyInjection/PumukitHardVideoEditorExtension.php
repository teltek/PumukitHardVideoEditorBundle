<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Yaml\Yaml;

class PumukitHardVideoEditorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_hard_video_editor.yaml');

        $profiles = Yaml::parseFile(__DIR__.'/../Resources/config/encoders.yml')['pumukit_hard_video_editor']['profiles'];
        $container->setParameter('pumukit_hard_video_editor.profilelist', $profiles);
        $container->setParameter('pumukit_hard_video_editor.default_set_role', $config['default_set_role']);

        $container->setParameter('pumukitencode.profilelist', $container->getParameter('pumukitencode.profilelist') + $profiles);
    }
}
