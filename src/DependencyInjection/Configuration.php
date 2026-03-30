<?php

declare(strict_types=1);

namespace InSquare\OpendxpLinkedinBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('in_square_opendxp_linkedin');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line Symfony returns NodeBuilder, but the interface misses scalarNode().
        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('object_folder')
                    ->defaultValue('/LinkedIn')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('assets_folder')
                    ->defaultValue('/linkedin')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('items_limit')
                    ->defaultValue(3)
                    ->min(1)
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
