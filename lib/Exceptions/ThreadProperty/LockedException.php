<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Exceptions\ThreadProperty;

/**
 * Story 1.6, AC1, AC3, AD-4: the typed, distinguishable refusal for "the
 * Thread is Locked". This is the third of AD-4's three named failure causes
 * for a write into a Thread - Thread is Locked, actor lacks authority,
 * Thread does not exist - each carrying its own exception class and its own
 * OCS error identifier, so a client can tell them apart. Distinct from
 * AuthorityException ('permission', an authority refusal) and from
 * StateException ('value', an out-of-range *state value* submitted to the
 * state-change endpoint - a different, FR-1/AC4 concern, not this one) and
 * from \OCP\AppFramework\Db\DoesNotExistException (Thread not found).
 *
 * Thrown by {@see \OCA\Talk\Service\ThreadService::ensureNotLocked()}, the
 * single shared write-refusal guard called from
 * {@see \OCA\Talk\Chat\ChatManager::sendMessage()} and
 * {@see \OCA\Talk\Chat\ChatManager::addSystemMessage()} (AD-2) before every
 * write, and - as a documented, necessary pre-flight exception for paths
 * whose write is not a chat comment (poll creation, attachment upload,
 * message scheduling) - from a small number of controller call sites that
 * would otherwise create an orphaned side effect before ever reaching
 * either of those two methods.
 */
class LockedException extends \InvalidArgumentException {
	public const REASON_LOCKED = 'locked';

	/**
	 * @param self::REASON_* $reason
	 */
	public function __construct(
		private readonly string $reason,
	) {
		parent::__construct($reason);
	}

	/**
	 * @return self::REASON_*
	 */
	public function getReason(): string {
		return $this->reason;
	}
}
