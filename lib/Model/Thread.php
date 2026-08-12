<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Room;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setRoomId(int $roomId)
 * @method int getRoomId()
 * @method void setLastMessageId(int $lastMessageId)
 * @method int getLastMessageId()
 * @method void setNumReplies(int $numReplies)
 * @method int getNumReplies()
 * @method void setLastActivity(\DateTime $lastActivity)
 * @method \DateTime|null getLastActivity()
 * @method void setName(string $name)
 * @method void setState(int $state)
 * @method 0|1|2 getState()
 * @method void setLockReason(?string $lockReason)
 * @method ?string getLockReason()
 *
 * @psalm-import-type TalkThread from ResponseDefinitions
 */
class Thread extends Entity {
	public const THREAD_NONE = 0;
	public const THREAD_CREATE = -1;

	// Thread lifecycle state (PRD's Ongoing/Closed/Locked) - unrelated to the
	// THREAD_NONE/THREAD_CREATE sentinels above, which identify the thread id
	// itself, not its state.
	public const STATE_ONGOING = 0;
	public const STATE_CLOSED = 1;
	public const STATE_LOCKED = 2;

	// Story 1.4, AC11: bounded free-text reason for a Locked Thread, same
	// bound shape as the moderator-authored Ban::NOTE_MAX_LENGTH precedent.
	public const LOCK_REASON_MAX_LENGTH = 4000;

	protected int $roomId = 0;
	protected int $lastMessageId = 0;
	protected int $numReplies = 0;
	protected ?\DateTime $lastActivity = null;
	protected string $name = '';
	protected int $state = self::STATE_ONGOING;
	protected ?string $lockReason = null;

	public function __construct() {
		$this->addType('roomId', Types::BIGINT);
		$this->addType('lastMessageId', Types::BIGINT);
		$this->addType('numReplies', Types::BIGINT);
		$this->addType('lastActivity', Types::DATETIME);
		$this->addType('name', Types::STRING);
		$this->addType('state', Types::INTEGER);
		$this->addType('lockReason', Types::STRING);
	}

	/**
	 * Row-key convention: this expects the `th_`-prefixed shape produced by
	 * {@see SelectHelper::selectThreadsTable()} with `aliasAll: true` — the only
	 * variant of that helper with a caller today. Every direct query that hydrates
	 * a Thread via this method must select through that helper (or reproduce its
	 * `th_*` aliases exactly) rather than inventing another prefix.
	 */
	public static function createFromRow(array $row): Thread {
		$thread = new Thread();
		$thread->setId((int)$row['th_id']);
		$thread->setRoomId((int)$row['th_room_id']);
		$thread->setLastMessageId((int)$row['th_last_message_id']);
		$thread->setNumReplies((int)$row['th_num_replies']);
		$thread->setLastActivity(new \DateTime($row['th_last_activity']));
		$thread->setName($row['th_name']);
		$thread->setState((int)$row['th_state']);
		$thread->setLockReason($row['th_lock_reason'] !== null ? (string)$row['th_lock_reason'] : null);
		return $thread;
	}

	/**
	 * @param string $json
	 * @return Thread
	 * @throws \JsonException
	 */
	public static function fromJson(string $json): Thread {
		$row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		$thread = new Thread();
		$thread->setId((int)$row['id']);
		$thread->setRoomId((int)$row['room_id']);
		$thread->setLastMessageId((int)$row['last_message_id']);
		$thread->setNumReplies((int)$row['num_replies']);
		$thread->setLastActivity(new \DateTime('@' . $row['last_activity']));
		$thread->setName($row['name']);
		// Defensive fallback (unlike createFromRow() above): this reads the
		// distributed cache, which has a 900s TTL, so a cache entry written by
		// pre-upgrade code (no 'state' key) can still be read here for up to
		// 15 minutes after this field was deployed.
		$thread->setState((int)($row['state'] ?? self::STATE_ONGOING));
		$thread->setLockReason($row['lock_reason'] ?? null);
		return $thread;
	}

	/**
	 * @return string
	 * @throws \JsonException
	 */
	public function toJson(): string {
		return json_encode([
			'id' => $this->getId(),
			'room_id' => $this->getRoomId(),
			'last_message_id' => $this->getLastMessageId(),
			'num_replies' => $this->getNumReplies(),
			'last_activity' => $this->getLastActivity()?->getTimestamp() ?? 0,
			'name' => $this->getName(),
			'state' => $this->getState(),
			'lock_reason' => $this->getLockReason(),
		], flags: JSON_THROW_ON_ERROR);
	}

	public function getName(): string {
		if ($this->name !== '') {
			return $this->name;
		}

		// FIXME temporary workaround against empty titles
		return 'Thread #' . $this->getId();
	}

	/**
	 * @return TalkThread
	 */
	public function toArray(Room $room): array {
		return [
			'id' => max(1, $this->getId()),
			// 'roomId' => max(1, $this->getRoomId()),
			'roomToken' => $room->getToken(),
			'lastMessageId' => max(0, $this->getLastMessageId()),
			'numReplies' => max(0, $this->getNumReplies()),
			'lastActivity' => max(0, $this->getLastActivity()?->getTimestamp() ?? 0),
			'title' => $this->getName(),
			'state' => $this->getState(),
			'lockReason' => $this->getLockReason(),
		];
	}
}
