<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Generator\RectorConfigRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RectorConfigRenderer.
 */
#[CoversClass(RectorConfigRenderer::class)]
final class RectorConfigRendererTest extends TestCase
{
    private RectorConfigRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new RectorConfigRenderer();
    }

    #[Test]
    public function renderEmptyEntriesReturnsEmptyString(): void
    {
        self::assertSame('', $this->renderer->render([]));
    }

    #[Test]
    public function renderClassRenameConfig(): void
    {
        $entries = [
            [
                'type' => 'rename_class',
                'old'  => 'TYPO3\CMS\Core\OldClass',
                'new'  => 'TYPO3\CMS\Core\NewClass',
            ],
        ];

        $output = $this->renderer->render($entries);

        self::assertStringContainsString('RenameClassRector', $output);
        self::assertStringContainsString("'TYPO3\\\\CMS\\\\Core\\\\OldClass' => 'TYPO3\\\\CMS\\\\Core\\\\NewClass'", $output);
        self::assertStringContainsString('RectorConfig::configure()', $output);
    }

    #[Test]
    public function renderMethodRenameConfig(): void
    {
        $entries = [
            [
                'type'      => 'rename_method',
                'className' => 'TYPO3\CMS\Core\Foo',
                'oldMethod' => 'oldMethod',
                'newMethod' => 'newMethod',
            ],
        ];

        $output = $this->renderer->render($entries);

        self::assertStringContainsString('RenameMethodRector', $output);
        self::assertStringContainsString('MethodCallRename', $output);
    }

    #[Test]
    public function renderStaticMethodRenameConfig(): void
    {
        $entries = [
            [
                'type'      => 'rename_static_method',
                'oldClass'  => 'TYPO3\CMS\Core\Old',
                'oldMethod' => 'calc',
                'newClass'  => 'TYPO3\CMS\Core\New',
                'newMethod' => 'compute',
            ],
        ];

        $output = $this->renderer->render($entries);

        self::assertStringContainsString('RenameStaticMethodRector', $output);
        self::assertStringContainsString('RenameStaticMethod(', $output);
    }

    #[Test]
    public function renderConstantRenameConfig(): void
    {
        $entries = [
            [
                'type'        => 'rename_class_constant',
                'oldClass'    => 'TYPO3\CMS\Core\Conf',
                'oldConstant' => 'OLD_CONST',
                'newClass'    => 'TYPO3\CMS\Core\Conf',
                'newConstant' => 'NEW_CONST',
            ],
        ];

        $output = $this->renderer->render($entries);

        self::assertStringContainsString('RenameClassConstFetchRector', $output);
        self::assertStringContainsString('RenameClassAndConstFetch(', $output);
    }

    #[Test]
    public function renderMultipleRuleTypes(): void
    {
        $entries = [
            [
                'type' => 'rename_class',
                'old'  => 'Old\A',
                'new'  => 'New\A',
            ],
            [
                'type' => 'rename_class',
                'old'  => 'Old\B',
                'new'  => 'New\B',
            ],
            [
                'type'      => 'rename_method',
                'className' => 'TYPO3\Foo',
                'oldMethod' => 'bar',
                'newMethod' => 'baz',
            ],
        ];

        $output = $this->renderer->render($entries);

        self::assertStringContainsString('RenameClassRector', $output);
        self::assertStringContainsString('RenameMethodRector', $output);
        self::assertStringContainsString("'Old\\\\A' => 'New\\\\A'", $output);
        self::assertStringContainsString("'Old\\\\B' => 'New\\\\B'", $output);
    }

    #[Test]
    public function renderSkipsUnknownEntryTypes(): void
    {
        $entries = [
            ['type' => 'unknown_type', 'old' => 'A', 'new' => 'B'],
        ];

        self::assertSame('', $this->renderer->render($entries));
    }
}
