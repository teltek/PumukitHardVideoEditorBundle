<?php

declare(strict_types=1);

namespace Pumukit\HardVideoEditorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pumukit_hard_video_editor');

        $treeBuilder->getRootNode()
            ->children()
            ->booleanNode('enable')
            ->defaultTrue()
            ->info('If false, the editor is not shown in the menu and its pages return 404')
            ->end()
            ->scalarNode('default_set_role')
            ->defaultValue('Participant')
            ->info('Code of the role given to the person chosen in the editor')
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
