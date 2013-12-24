<?php

namespace Patbzh\SynologyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This class contains the configuration information for the bundle
 *
 * This information is solely responsible for how the different configuration
 * sections are normalized, and merged.
 *
 * @author Patrick Coustans <patrick.coustans@gmail.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('patbzh_synology');

        $rootNode
            ->children()
                ->scalarNode('http_client_timeout')
                    ->defaultValue(5)
                    ->end()
                ->scalarNode('base_url')
                    ->isRequired()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

