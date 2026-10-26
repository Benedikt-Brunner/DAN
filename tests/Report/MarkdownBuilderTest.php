<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Report;

use Dan\Harness\Report\MarkdownBuilder;
use PHPUnit\Framework\TestCase;

final class MarkdownBuilderTest extends TestCase
{
    public function testFencesVerbatimTextWithoutItsTrailingNewline(): void
    {
        $markdown = (new MarkdownBuilder())->codeBlock("-> Table scan on `product`\n")->build();

        self::assertSame("```\n-> Table scan on `product`\n```", $markdown);
    }

    public function testTheFenceOutgrowsAnyBacktickRunInTheContent(): void
    {
        $markdown = (new MarkdownBuilder())->codeBlock("a ``` b\nc ```` d")->build();

        self::assertSame("`````\na ``` b\nc ```` d\n`````", $markdown);
    }
}
