<?php

declare(strict_types=1);

// Minimal project shell for the kernel integration tests: when the bundle's
// test suite boots Shopware, the bundle directory acts as the project root.
// Real DAN runs never use this file - provisioned skeleton apps have their
// own bundles.php.
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Shopware\Core\Framework\Framework::class => ['all' => true],
    Shopware\Core\System\System::class => ['all' => true],
    Shopware\Core\Content\Content::class => ['all' => true],
    Shopware\Core\Checkout\Checkout::class => ['all' => true],
    Shopware\Core\Maintenance\Maintenance::class => ['all' => true],
    Shopware\Core\DevOps\DevOps::class => ['all' => true],
    Shopware\Core\Profiling\Profiling::class => ['all' => true],
    Dan\Probe\DanProbeBundle::class => ['all' => true],
];
