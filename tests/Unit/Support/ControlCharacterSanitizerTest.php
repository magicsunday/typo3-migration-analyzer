<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\ControlCharacterSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ControlCharacterSanitizer::class)]
final class ControlCharacterSanitizerTest extends TestCase
{
    /**
     * Without an explicit replacement, a control character is removed entirely.
     */
    #[Test]
    public function stripRemovesControlCharactersByDefault(): void
    {
        self::assertSame('/test/ext', ControlCharacterSanitizer::strip("/test/\x1Bext"));
    }

    /**
     * An explicit replacement is used in place of the stripped control character.
     */
    #[Test]
    public function stripReplacesControlCharactersWithTheGivenReplacement(): void
    {
        self::assertSame('a b', ControlCharacterSanitizer::strip("a\x00b", ' '));
    }

    /**
     * A value without control characters is returned unchanged.
     */
    #[Test]
    public function stripLeavesCleanValueUnchanged(): void
    {
        self::assertSame('clean value', ControlCharacterSanitizer::strip('clean value'));
    }

    /**
     * 0x1F is the upper boundary of the stripped control-character range.
     */
    #[Test]
    public function stripRemovesTheUpperBoundaryControlCharacter(): void
    {
        self::assertSame('ab', ControlCharacterSanitizer::strip("a\x1Fb"));
    }

    /**
     * 0x20 (space) is the first byte outside the control-character range and
     * must survive untouched.
     */
    #[Test]
    public function stripPreservesTheFirstNonControlCharacter(): void
    {
        self::assertSame("a\x20b", ControlCharacterSanitizer::strip("a\x20b"));
    }

    /**
     * 0x7F (DEL) is a second, disjoint control character outside the
     * 0x00-0x1F range and must be stripped just like the others.
     */
    #[Test]
    public function stripRemovesTheDeleteCharacter(): void
    {
        self::assertSame('ab', ControlCharacterSanitizer::strip("a\x7Fb"));
    }

    /**
     * 0x7E is the byte immediately below 0x7F and must survive untouched.
     */
    #[Test]
    public function stripPreservesTheByteBelowDelete(): void
    {
        self::assertSame("a\x7Eb", ControlCharacterSanitizer::strip("a\x7Eb"));
    }
}
