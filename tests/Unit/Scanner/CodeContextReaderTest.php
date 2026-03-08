<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\CodeContextReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function implode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(CodeContextReader::class)]
final class CodeContextReaderTest extends TestCase
{
    private CodeContextReader $reader;

    private string $tmpFile;

    protected function setUp(): void
    {
        $this->reader  = new CodeContextReader();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'ctx-');

        file_put_contents($this->tmpFile, implode("\n", [
            '<?php',
            '',
            'class Foo',
            '{',
            '    public function bar()',
            '    {',
            '        doSomething();',
            '    }',
            '}',
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->tmpFile);
    }

    #[Test]
    public function readContextReturnsThreeLinesBeforeAndAfter(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 5, 3);

        self::assertCount(7, $context);
        self::assertSame(2, $context[0]->number);
        self::assertSame(8, $context[6]->number);
        self::assertTrue($context[3]->isHighlighted);
        self::assertFalse($context[0]->isHighlighted);
    }

    #[Test]
    public function readContextClampsAtFileStart(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 1, 3);

        self::assertSame(1, $context[0]->number);
        self::assertTrue($context[0]->isHighlighted);
    }

    #[Test]
    public function readContextClampsAtFileEnd(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 9, 3);

        self::assertNotEmpty($context);

        $lastIndex = array_key_last($context);
        self::assertNotNull($lastIndex);

        self::assertSame(9, $context[$lastIndex]->number);
        self::assertTrue($context[$lastIndex]->isHighlighted);
    }

    #[Test]
    public function readContextReturnsEmptyForNonexistentFile(): void
    {
        $context = $this->reader->readContext('/nonexistent/file.php', 5, 3);

        self::assertSame([], $context);
    }
}
