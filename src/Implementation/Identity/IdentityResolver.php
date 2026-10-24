<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Identity;

use Dan\Harness\Implementation\Reference\Reference;
use Dan\Harness\Implementation\Reference\ReferenceType;

final class IdentityResolver
{
    public function resolve(Reference $reference): Identity
    {
        if ($reference->type === ReferenceType::Release) {
            $constraint = $reference->releaseConstraint();

            return new Identity(id: $constraint, label: sprintf('shopware/core %s', $constraint));
        }

        $checkout = $reference->checkoutPath();
        $fingerprint = ContentFingerprint::ofDirectory($checkout->join('src', 'Core'));

        return new Identity(
            id: $fingerprint,
            label: sprintf('%s @ %s', $checkout->basename(), substr($fingerprint, 0, 12)),
        );
    }
}
