<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('pumukit_hard_video_editor');

        $rootNode
            ->children()
            ->scalarNode('default_set_role')
            ->defaultValue('Participant')
            ->info('Get the default role to add person on hard trimming')
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
