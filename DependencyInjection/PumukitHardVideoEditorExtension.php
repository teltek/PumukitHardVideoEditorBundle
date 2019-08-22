<?php

namespace Pumukit\HardVideoEditorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class PumukitHardVideoEditorExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $bundleConfiguration = Yaml::parse(file_get_contents(__DIR__.'/../Resources/config/encoders.yml'));
        //if ($config['profiles'] && $bundleConfiguration['pumukit_hard_video_editor']['profiles']) {
        $config['profiles'] = $bundleConfiguration['pumukit_hard_video_editor']['profiles'];
        //}
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
