<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Implementation;

use Dan\Harness\Implementation\Identity\IdentityResolver;
use Dan\Harness\Implementation\Reference\Reference;
use PHPUnit\Framework\TestCase;

final class IdentityResolverTest extends TestCase
{
    public function testReleaseIdentityUsesVersionWithoutReadingAWorkingTree(): void
    {
        $identity = (new IdentityResolver())->resolve(Reference::fromString('v6.6.10.0'));

        self::assertSame('v6.6.10.0', $identity->id);
        self::assertSame('shopware/core v6.6.10.0', $identity->label);
    }

    public function testCheckoutIdentityChangesWithItsCoreContents(): void
    {
        $checkout = $this->checkout();

        try {
            $resolver = new IdentityResolver();
            $reference = Reference::fromString($checkout);
            $initial = $resolver->resolve($reference);

            file_put_contents($checkout . '/src/Core/Feature.php', '<?php return 2;');
            $changed = $resolver->resolve($reference);

            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $initial->id);
            self::assertNotSame($initial->id, $changed->id);
            self::assertSame(basename($checkout) . ' @ ' . substr($changed->id, 0, 12), $changed->label);
        } finally {
            $this->removeCheckout($checkout);
        }
    }

    public function testCheckoutIdentityIncludesFileNames(): void
    {
        $firstCheckout = $this->checkout();
        $secondCheckout = $this->checkout();

        try {
            rename($secondCheckout . '/src/Core/Feature.php', $secondCheckout . '/src/Core/RenamedFeature.php');
            $resolver = new IdentityResolver();

            $first = $resolver->resolve(Reference::fromString($firstCheckout));
            $second = $resolver->resolve(Reference::fromString($secondCheckout));

            self::assertNotSame($first->id, $second->id);
        } finally {
            $this->removeCheckout($firstCheckout);
            $this->removeCheckout($secondCheckout);
        }
    }

    private function checkout(): string
    {
        $checkout = sys_get_temp_dir() . '/dan-identity-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($checkout . '/src/Core', 0777, true));
        file_put_contents($checkout . '/src/Core/composer.json', '{}');
        file_put_contents($checkout . '/src/Core/Feature.php', '<?php return 1;');

        return $checkout;
    }

    private function removeCheckout(string $checkout): void
    {
        foreach (
            [
                'Feature.php',
                'RenamedFeature.php',
                'composer.json',
            ] as $file
        ) {
            $path = $checkout . '/src/Core/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($checkout . '/src/Core');
        rmdir($checkout . '/src');
        rmdir($checkout);
    }
}
