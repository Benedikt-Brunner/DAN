<?php

declare(strict_types=1);

namespace Dan\Probe;

use Dan\Lib\Filesystem\Path;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Deliberately extends the plain Symfony Bundle (not Shopware's) to keep the
 * coupling surface to the DAL version under test as small as possible - the
 * probe must load against every DAL version DAN can point at.
 */
final class DanProbeBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $configDirectory = Path::fromString(__DIR__)->join('Resources', 'config');
        $loader = new YamlFileLoader($container, new FileLocator($configDirectory->toString()));
        $loader->load('services.yaml');
    }
}
