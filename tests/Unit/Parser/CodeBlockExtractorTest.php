<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Parser\CodeBlockExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeBlockExtractorTest extends TestCase
{
    private CodeBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CodeBlockExtractor();
    }

    #[Test]
    public function extractSinglePhpCodeBlock(): void
    {
        $rst = <<<'RST'
            Some text before.

            ..  code-block:: php

                $foo = new Bar();
                $foo->run();

            Some text after.
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('php', $blocks[0]->language);
        self::assertSame("\$foo = new Bar();\n\$foo->run();", $blocks[0]->code);
        self::assertNull($blocks[0]->label);
    }

    #[Test]
    public function extractMultipleCodeBlocks(): void
    {
        $rst = <<<'RST'
            First block:

            ..  code-block:: php

                $a = 1;

            Second block:

            ..  code-block:: php

                $b = 2;
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(2, $blocks);
        self::assertSame('$a = 1;', $blocks[0]->code);
        self::assertSame('$b = 2;', $blocks[1]->code);
    }

    #[Test]
    public function extractDifferentLanguages(): void
    {
        $rst = <<<'RST'
            ..  code-block:: yaml

                foo:
                  bar: baz
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('yaml', $blocks[0]->language);
        self::assertSame("foo:\n  bar: baz", $blocks[0]->code);
    }

    #[Test]
    public function extractStripsCommonIndentation(): void
    {
        $rst = <<<'RST'
            ..  code-block:: php

                class Foo
                {
                    public function bar(): void
                    {
                    }
                }
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);

        $expected = "class Foo\n{\n    public function bar(): void\n    {\n    }\n}";
        self::assertSame($expected, $blocks[0]->code);
    }

    #[Test]
    public function extractReturnsEmptyArrayForNoCodeBlocks(): void
    {
        $rst = <<<'RST'
            This is plain text without any code blocks.

            Just some description here.
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function extractReturnsEmptyArrayForNullInput(): void
    {
        $blocks = $this->extractor->extract(null);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function extractPreservesBlankLinesWithinCodeBlock(): void
    {
        $rst = <<<'RST'
            ..  code-block:: php

                $a = 1;

                $b = 2;

                $c = 3;
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame("\$a = 1;\n\n\$b = 2;\n\n\$c = 3;", $blocks[0]->code);
    }

    #[Test]
    public function extractHandlesSingleDotSpaceDirective(): void
    {
        $rst = <<<'RST'
            .. code-block:: php

                $x = 42;
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('php', $blocks[0]->language);
        self::assertSame('$x = 42;', $blocks[0]->code);
    }

    #[Test]
    public function extractDetectsExplicitBeforeAfterSubsections(): void
    {
        $rst = <<<'RST'
            Migration
            =========

            Before
            ------

            ..  code-block:: php

                $old = OldClass::method();

            After
            -----

            ..  code-block:: php

                $new = NewClass::method();
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(2, $blocks);
        self::assertSame('Before', $blocks[0]->label);
        self::assertSame('$old = OldClass::method();', $blocks[0]->code);
        self::assertSame('After', $blocks[1]->label);
        self::assertSame('$new = NewClass::method();', $blocks[1]->code);
    }

    #[Test]
    public function extractResetsLabelAfterNonBeforeAfterSubsection(): void
    {
        $rst = <<<'RST'
            Before
            ------

            ..  code-block:: php

                $old = 1;

            Details
            -------

            ..  code-block:: php

                $details = 2;
            RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(2, $blocks);
        self::assertSame('Before', $blocks[0]->label);
        self::assertNull($blocks[1]->label);
    }
}
