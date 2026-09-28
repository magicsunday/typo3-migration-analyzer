<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Plus\RemoveDeadZeroAndOneOperationRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src/',
        __DIR__ . '/tests/',
    ]);

    // Keep the Rector caches inside the build directory rather than the repository root.
    $rectorCacheDirectory          = __DIR__ . '/.build/cache/.rector.cache';
    $rectorContainerCacheDirectory = __DIR__ . '/.build/cache/.rector.container.cache';

    foreach ([$rectorCacheDirectory, $rectorContainerCacheDirectory] as $cacheDirectory) {
        if (
            !is_dir($cacheDirectory)
            && !mkdir($cacheDirectory, 0o775, true)
            && !is_dir($cacheDirectory)
        ) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $cacheDirectory));
        }
    }

    $rectorConfig->cacheDirectory($rectorCacheDirectory);
    $rectorConfig->containerCacheDirectory($rectorContainerCacheDirectory);
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    // The shared rule sets and skips; 80400 is this application's PHP floor.
    (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($rectorConfig, 80400);

    // Skips on top of the shared ones (skip() merges, it does not replace).
    $rectorConfig->skip([
        // Explicit (new Foo())->method() parentheses kept for readability
        NewMethodCallWithoutParenthesesRector::class,
        // Intentional: $x * 1.0 casts int to float
        RemoveDeadZeroAndOneOperationRector::class,
        // The scanner fixtures stand in for third-party TYPO3 extension code; tests
        // assert findings by line number, so they are kept byte-for-byte.
        __DIR__ . '/tests/Fixtures/Extension',
    ]);
};
