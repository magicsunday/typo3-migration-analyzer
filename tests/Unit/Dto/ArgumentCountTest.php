<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ArgumentCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgumentCount::class)]
final class ArgumentCountTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $count = new ArgumentCount(
            numberOfMandatoryArguments: 2,
            maximumNumberOfArguments: 5,
        );

        self::assertSame(2, $count->numberOfMandatoryArguments);
        self::assertSame(5, $count->maximumNumberOfArguments);
    }

    #[Test]
    public function toConfigArrayReturnsExpectedFormat(): void
    {
        $count = new ArgumentCount(
            numberOfMandatoryArguments: 1,
            maximumNumberOfArguments: 3,
        );

        self::assertSame([
            'numberOfMandatoryArguments' => 1,
            'maximumNumberOfArguments'   => 3,
        ], $count->toConfigArray());
    }
}
