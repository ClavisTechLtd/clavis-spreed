<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Reaps `talk_threads` (and `talk_thread_attendees`) rows orphaned by
 * message expiry (Story 1.10, AD-5).
 *
 * A Thread survives its root message being soft-deleted by its author or a
 * moderator - the comment row survives as a tombstone (Story 1.10, AC1) -
 * but not its root expiring: {@see ExpireChatMessages} hard-deletes the
 * comment row out from under it every five minutes, and since a Thread's id
 * *is* its root comment's id (AD-5), nothing else in this codebase ever
 * removes the now-orphaned `talk_threads` row.
 *
 * Deliberately a sibling job, not logic folded into ExpireChatMessages
 * itself - runs on the same five-minute interval (Story 1.10, AC3: "alongside
 * the existing five-minute expiry job"), so a root can still be looked up for
 * up to one more interval's worth of time after it expires before this job
 * removes the Thread row. Every read path already tolerates that window
 * (Story 1.10, AC5 - see {@see \OCA\Talk\Service\ThreadService::reapOrphanedThreads()}
 * for the cache-invalidation half of AC4).
 *
 * NOTE (AC6): nextcloud/spreed#16739 ("Delete empty Talk threads") is the
 * open upstream issue occupying this same ground. Its literal repro is a
 * *soft*-deleted (tombstoned) root, which this reaper deliberately never
 * touches (AC1) - this job only ever fires on a *hard*-deleted (expired)
 * root. If/when upstream lands its own fix for #16739, reconcile
 * deliberately against that distinction rather than assuming the two
 * problems are identical.
 *
 * @package OCA\Talk\BackgroundJob
 */
class ReapOrphanedThreads extends TimedJob {
	/**
	 * Bounded per run (AC3): matches
	 * {@see \OCA\Talk\Model\SessionMapper::findSessionIdsWithoutAttendee()}'s
	 * chunk size, the closest existing precedent in this codebase for a
	 * LEFT-JOIN-derived orphan sweep.
	 */
	private const CHUNK_SIZE = 1000;

	public function __construct(
		ITimeFactory $timeFactory,
		private readonly ThreadService $threadService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);

		// Every 5 minutes, alongside ExpireChatMessages (Story 1.10, AC3)
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	protected function run($argument): void {
		$numReaped = 0;

		do {
			$reaped = $this->threadService->reapOrphanedThreads(self::CHUNK_SIZE);
			$numReaped += $reaped;
		} while ($reaped === self::CHUNK_SIZE);

		if ($numReaped > 0) {
			$this->logger->info('Reaped {numReaped} threads orphaned by message expiry', [
				'numReaped' => $numReaped,
			]);
		}
	}
}
