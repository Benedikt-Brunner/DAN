<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Reference;

enum ReferenceType: string
{
    case Checkout = 'checkout';
    case Release = 'release';
}
