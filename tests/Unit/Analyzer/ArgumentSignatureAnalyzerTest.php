<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\ArgumentSignatureAnalyzer;
use App\Dto\ArgumentCount;
use App\Dto\CodeBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

#[CoversClass(ArgumentSignatureAnalyzer::class)]
final class ArgumentSignatureAnalyzerTest extends TestCase
{
    private ArgumentSignatureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ArgumentSignatureAnalyzer();
    }

    #[Test]
    public function analyzeFindsMethodWithThreeMandatoryArguments(): void
    {
        $code = <<<'PHP'
            public function handleRequest(
                ServerRequestInterface $request,
                MfaProviderPropertyManager $propertyManager,
                MfaViewType $type
            ): ResponseInterface
            PHP;

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'handleRequest',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(3, $result->numberOfMandatoryArguments);
        self::assertSame(3, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeDetectsOptionalParametersWithDefaults(): void
    {
        $code = 'public function foo(string $a, int $b = 0, bool $c = true): void {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'foo',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(3, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeDetectsNullableDefaultAsOptional(): void
    {
        $code = 'public function bar(string $a, ?string $b = null): void {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'bar',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(2, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeDetectsVariadicParameter(): void
    {
        $code = 'public function logicalAnd(QueryConstraint ...$constraints): QueryConstraint {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'logicalAnd',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(0, $result->numberOfMandatoryArguments);
        self::assertSame(PHP_INT_MAX, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeDetectsVariadicWithMandatoryBefore(): void
    {
        $code = 'public function format(string $pattern, mixed ...$values): string {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'format',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(PHP_INT_MAX, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeReturnsArgumentCountForMethodWithNoParameters(): void
    {
        $code = 'public function getErrorParams(): array {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'getErrorParams',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(0, $result->numberOfMandatoryArguments);
        self::assertSame(0, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeReturnsNullWhenMethodNotFound(): void
    {
        $code = 'public function otherMethod(int $a): void {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'nonExistent',
        );

        self::assertNull($result);
    }

    #[Test]
    public function analyzeReturnsNullForEmptyCodeBlocks(): void
    {
        $result = $this->analyzer->analyzeCodeBlocks([], 'foo');

        self::assertNull($result);
    }

    #[Test]
    public function analyzeSkipsNonPhpCodeBlocks(): void
    {
        $code = 'function foo(a, b, c) { }';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('yaml', $code, null)],
            'foo',
        );

        self::assertNull($result);
    }

    #[Test]
    public function analyzeSearchesMultipleCodeBlocks(): void
    {
        $blocks = [
            new CodeBlock('php', '$obj->oldMethod();', 'Before'),
            new CodeBlock('php', 'public function newMethod(string $a, int $b): void {}', 'After'),
        ];

        $result = $this->analyzer->analyzeCodeBlocks($blocks, 'newMethod');

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(2, $result->numberOfMandatoryArguments);
        self::assertSame(2, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeHandlesConstructorPromotion(): void
    {
        $code = <<<'PHP'
            public function __construct(
                private readonly string $name,
                private readonly int $age = 0,
            ) {
            }
            PHP;

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            '__construct',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(2, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeHandlesArrayDefaultValues(): void
    {
        $code = 'public function setOptions(string $name, array $options = []): void {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'setOptions',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(2, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function analyzeHandlesStaticMethodSignature(): void
    {
        $code = 'public static function create(string $name, int $type): self {}';

        $result = $this->analyzer->analyzeCodeBlocks(
            [new CodeBlock('php', $code, null)],
            'create',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(2, $result->numberOfMandatoryArguments);
        self::assertSame(2, $result->maximumNumberOfArguments);
    }
}
