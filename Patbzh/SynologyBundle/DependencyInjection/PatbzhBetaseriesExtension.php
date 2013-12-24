<?php

namespace Patbzh\SynologyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * PatbzhBetaseriesExtension
 *
 * @author Patrick Coustans <patrick.coustans@gmail.com>
 */
class PatbzhSynologyExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('buzz.xml');
        $loader->load('client.xml');

        $container->setParameter('patbzh.synology.http_client_timeout', $config['http_client_timeout']);
        $container->setParameter('patbzh.synology.base_url', $config['base_url']);
    }
}

