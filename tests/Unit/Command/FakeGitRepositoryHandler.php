<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Scanner\GitRepositoryHandlerInterface;

/**
 * Test double standing in for a real `git clone`, returning a preconfigured
 * directory instead of cloning over the network and recording the path
 * passed to cleanup() instead of deleting it.
 */
final class FakeGitRepositoryHandler implements GitRepositoryHandlerInterface
{
    public ?string $cleanedUpPath = null;

    public function __construct(private readonly string $clonedPath)
    {
    }

    public function clone(string $url): string
    {
        return $this->clonedPath;
    }

    public function cleanup(string $path): void
    {
        $this->cleanedUpPath = $path;
    }

    public function validate(string $url): void
    {
    }
}
