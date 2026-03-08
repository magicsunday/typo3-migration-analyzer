<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Dto\VersionRange;
use App\Service\DocumentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_array;
use function is_int;

/**
 * Reads the selected migration path from the session and applies it
 * to DocumentService before any controller action runs.
 */
final readonly class VersionRangeSubscriber implements EventSubscriberInterface
{
    private const string SESSION_KEY = 'selected_version_range';

    public function __construct(
        private DocumentService $documentService,
    ) {
    }

    /**
     * Returns the events this subscriber listens to.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    /**
     * Applies the version range from query parameters or session to the DocumentService.
     */
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Check for explicit range change via query parameters
        $source = $request->query->getInt('migration_source');
        $target = $request->query->getInt('migration_target');

        if ($source > 0 && $target > 0 && $source < $target) {
            $range = new VersionRange($source, $target);
            $session->set(self::SESSION_KEY, [
                'source' => $range->sourceVersion,
                'target' => $range->targetVersion,
            ]);
            $this->documentService->setVersionRange($range);

            return;
        }

        // Restore from session
        $stored = $session->get(self::SESSION_KEY);

        if (is_array($stored)
            && isset($stored['source'], $stored['target'])
            && is_int($stored['source'])
            && is_int($stored['target'])
        ) {
            $this->documentService->setVersionRange(
                new VersionRange($stored['source'], $stored['target']),
            );
        }
    }
}
