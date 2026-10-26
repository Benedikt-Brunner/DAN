<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Progress;

/**
 * The kinds of rows the dataset seeder writes, in the order it writes them.
 * The value is how progress output names them.
 */
enum SeededRows: string
{
    case Categories = 'categories';
    case Products = 'products';
    case ProductVariants = 'product variants';
    case ProductReviews = 'product reviews';
    case Media = 'media';
    case SyntheticBlobs = 'synthetic blobs';
}
