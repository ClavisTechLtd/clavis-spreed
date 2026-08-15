<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Model;

use OCA\Talk\Model\Thread;
use OCA\Talk\Room;
use Test\TestCase;

/**
 * Locks in the row-key convention Story 1.1 reconciles: Thread::createFromRow()
 * must hydrate from the same `th_`-prefixed keys that
 * SelectHelper::selectThreadsTable(..., aliasAll: true) produces, since that is
 * the only branch of that helper with a live caller
 * (ThreadService::getRecentByActor() and ScheduledMessageMapper::findByRoomAndActor()
 * both rely on it). A regression back to the old `t_id`/bare-field mix, or to the
 * hardcoded `th_` prefix drifting, would silently break Thread hydration on one of
 * those two paths without failing to compile.
 */
class ThreadTest extends TestCase {
	public function testCreateFromRowHydratesFromThPrefixedKeys(): void {
		$row = [
			'th_id' => '123',
			'th_room_id' => '45',
			'th_last_message_id' => '6789',
			'th_num_replies' => '3',
			'th_last_activity' => '2026-01-15 10:30:00',
			'th_name' => 'Some Thread Title',
			'th_state' => (string)Thread::STATE_CLOSED,
			'th_lock_reason' => 'Repeated off-topic discussion',
			// Columns from a joined table (e.g. talk_scheduled_msg or
			// talk_thread_attendees) must be ignored, not just absent.
			'unrelated_joined_column' => 'ignore me',
		];

		$thread = Thread::createFromRow($row);

		$this->assertSame(123, $thread->getId());
		$this->assertSame(45, $thread->getRoomId());
		$this->assertSame(6789, $thread->getLastMessageId());
		$this->assertSame(3, $thread->getNumReplies());
		$this->assertSame('2026-01-15 10:30:00', $thread->getLastActivity()?->format('Y-m-d H:i:s'));
		$this->assertSame('Some Thread Title', $thread->getName());
		$this->assertSame(Thread::STATE_CLOSED, $thread->getState());
		$this->assertSame('Repeated off-topic discussion', $thread->getLockReason());
	}

	/**
	 * Story 1.4: a Thread that was never locked has a null lock reason,
	 * not an empty string, and createFromRow() must read a NULL database
	 * column back as null rather than the string "".
	 */
	public function testCreateFromRowReadsNullLockReason(): void {
		$row = [
			'th_id' => '123',
			'th_room_id' => '45',
			'th_last_message_id' => '6789',
			'th_num_replies' => '3',
			'th_last_activity' => '2026-01-15 10:30:00',
			'th_name' => 'Some Thread Title',
			'th_state' => (string)Thread::STATE_ONGOING,
			'th_lock_reason' => null,
		];

		$thread = Thread::createFromRow($row);

		$this->assertNull($thread->getLockReason());
	}

	public function testCreateFromRowMatchesSelectThreadsTableAliasAllKeys(): void {
		// SelectHelper::selectThreadsTable($query, $alias, aliasAll: true) always
		// aliases its output to exactly these eight keys, regardless of the source
		// table alias passed in. Thread::createFromRow() must read precisely this
		// set — no more, no fewer — or the reconciliation AC2 requires has drifted.
		$expectedKeys = ['th_room_id', 'th_last_message_id', 'th_num_replies', 'th_last_activity', 'th_name', 'th_state', 'th_lock_reason', 'th_id'];

		$row = array_combine($expectedKeys, [
			'1', '2', '3', '2026-06-01 00:00:00', 'Title', (string)Thread::STATE_LOCKED, 'Reason', '4',
		]);

		$thread = Thread::createFromRow($row);

		$this->assertSame(4, $thread->getId());
		$this->assertSame(1, $thread->getRoomId());
		$this->assertSame(Thread::STATE_LOCKED, $thread->getState());
		$this->assertSame('Reason', $thread->getLockReason());
	}

	/**
	 * Locks in Story 1.2's AC1: a freshly constructed Thread (as
	 * ThreadService::createThread() builds it, before any explicit state is
	 * set) reports Ongoing.
	 */
	public function testNewThreadDefaultsToOngoingState(): void {
		$thread = new Thread();

		$this->assertSame(Thread::STATE_ONGOING, $thread->getState());
	}

	/**
	 * Locks in Story 1.2's AC5: state survives the distributed-cache round
	 * trip (toJson()/fromJson()), and the field the API actually serializes
	 * (toArray()) carries it too.
	 */
	public function testStateSurvivesJsonRoundTrip(): void {
		$thread = new Thread();
		$thread->setId(55);
		$thread->setRoomId(6);
		$thread->setName('Round trip');
		$thread->setLastActivity(new \DateTime('@1700000000'));
		$thread->setState(Thread::STATE_LOCKED);

		$restored = Thread::fromJson($thread->toJson());

		$this->assertSame(Thread::STATE_LOCKED, $restored->getState());
	}

	/**
	 * Story 1.4, AC10/AD-1: lockReason survives the distributed-cache
	 * round trip the same way state does.
	 */
	public function testLockReasonSurvivesJsonRoundTrip(): void {
		$thread = new Thread();
		$thread->setId(55);
		$thread->setRoomId(6);
		$thread->setName('Round trip');
		$thread->setLastActivity(new \DateTime('@1700000000'));
		$thread->setState(Thread::STATE_LOCKED);
		$thread->setLockReason('Repeated off-topic discussion');

		$restored = Thread::fromJson($thread->toJson());

		$this->assertSame('Repeated off-topic discussion', $restored->getLockReason());
	}

	/**
	 * Story 1.2 Task 2.6: fromJson() must default to Ongoing, not throw or
	 * emit an undefined-array-key warning, when reading a cache entry written
	 * by pre-upgrade code that has no 'state' key at all — the exact shape a
	 * still-warm (up to 900s TTL) cache entry has right after this field is
	 * deployed.
	 */
	public function testFromJsonDefaultsToOngoingWhenStateKeyIsMissing(): void {
		$preUpgradeJson = json_encode([
			'id' => 7,
			'room_id' => 8,
			'last_message_id' => 9,
			'num_replies' => 0,
			'last_activity' => 1700000000,
			'name' => 'Pre-upgrade cache entry',
		], flags: JSON_THROW_ON_ERROR);

		$thread = Thread::fromJson($preUpgradeJson);

		$this->assertSame(Thread::STATE_ONGOING, $thread->getState());
		$this->assertNull($thread->getLockReason());
	}

	public function testToArrayIncludesState(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('tkn123');

		$thread = new Thread();
		$thread->setId(1);
		$thread->setName('T');
		$thread->setState(Thread::STATE_CLOSED);

		$array = $thread->toArray($room);

		$this->assertSame(Thread::STATE_CLOSED, $array['state']);
	}

	public function testToArrayIncludesLockReason(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('tkn123');

		$thread = new Thread();
		$thread->setId(1);
		$thread->setName('T');
		$thread->setState(Thread::STATE_LOCKED);
		$thread->setLockReason('Repeated off-topic discussion');

		$array = $thread->toArray($room);

		$this->assertSame('Repeated off-topic discussion', $array['lockReason']);
	}

	public function testToArrayLockReasonIsNullByDefault(): void {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('tkn123');

		$thread = new Thread();
		$thread->setId(1);
		$thread->setName('T');

		$array = $thread->toArray($room);

		$this->assertNull($array['lockReason']);
	}
}
