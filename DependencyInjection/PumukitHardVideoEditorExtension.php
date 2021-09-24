<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

class PumukitHardVideoEditorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_hard_video_editor.yaml');

        $bundleConfiguration = Yaml::parse(file_get_contents(__DIR__.'/../Resources/config/encoders.yml'));

        $config['profiles'] = $bundleConfiguration['pumukit_hard_video_editor']['profiles'];
        $container->setParameter('pumukit_hard_video_editor.profilelist', $config['profiles']);
        $container->setParameter('pumukit_hard_video_editor.default_set_role', $config['default_set_role']);

        $encoderBundleProfiles = $container->getParameter('pumukitencode.profilelist');
        $profilesToMerge = [];
        foreach ($config['profiles'] as $name => $profile) {
            if (!isset($encoderBundleProfiles[$name])) {
                $profilesToMerge[$name] = $profile;
            }
        }
        $newProfiles = array_merge($encoderBundleProfiles, $profilesToMerge);
        $container->setParameter('pumukitencode.profilelist', $newProfiles);
    }
}
