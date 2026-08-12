<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Exceptions\ThreadProperty;

/**
 * Story 1.3, AC1, AC5: the typed, distinguishable refusal for "actor lacks
 * authority" (AD-3, AD-4). Reuses the OCS error identifier 'permission'
 * that ThreadController::renameThread() already returned before this
 * story's refactor, so the response shape on the wire is unchanged.
 * Distinct from StateException (an out-of-range state value) and from
 * \OCP\AppFramework\Db\DoesNotExistException (Thread not found) - the
 * three failure causes AD-4 requires to carry separate exception classes
 * and separate OCS error identifiers. Story 1.4 AC7 reuses this identifier
 * for its own four state-transition endpoints rather than re-deriving it.
 */
class AuthorityException extends \InvalidArgumentException {
	public const REASON_PERMISSION = 'permission';

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
