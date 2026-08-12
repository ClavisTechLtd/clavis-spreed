<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\Chat\CommentsManager;
use OCA\Talk\Exceptions\ThreadProperty\AuthorityException;
use OCA\Talk\Exceptions\ThreadProperty\LockedException;
use OCA\Talk\Exceptions\ThreadProperty\StateException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\SelectHelper;
use OCA\Talk\Model\Thread;
use OCA\Talk\Model\ThreadAttendee;
use OCA\Talk\Model\ThreadAttendeeMapper;
use OCA\Talk\Model\ThreadMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;

class ThreadService {

	private readonly ICache $cache;
	private const CACHE_PREFIX = 'thread/';
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly ThreadMapper $threadMapper,
		private readonly ThreadAttendeeMapper $threadAttendeeMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly ICacheFactory $cacheFactory,
		private readonly CommentsManager $commentsManager,
	) {
		$this->cache = $this->cacheFactory->createDistributed('talk.threads');
	}

	/**
	 * The single implementation of Thread Manager authority (AD-3): the
	 * Thread Root Message author, or any moderator of the Conversation.
	 * Every state, featuring, tag and rename endpoint must call this (or
	 * {@see self::ensureThreadManager()}) rather than re-deriving the
	 * expression.
	 *
	 * Guests and bots never qualify, regardless of authorship or
	 * (guest-)moderator status (AC9, Consistency Conventions: "Guests and
	 * bots are never Thread Managers"). Participant::isGuest() covers both
	 * GUEST and GUEST_MODERATOR; hasModeratorPermissions(false) already
	 * excludes GUEST_MODERATOR from the moderator branch below, but a
	 * guest who authored the Thread Root Message must still be refused,
	 * which is why the guest/bot check is a separate, earlier guard.
	 *
	 * The moderator check runs before the root-comment lookup, and is
	 * therefore free of it: for a moderator viewing a list of Threads,
	 * this method never touches the comments table at all (see Dev Notes
	 * "Known tradeoff - per-row authority query" in the story file for the
	 * cost this does incur for a non-moderator viewing a large list).
	 */
	public function isThreadManager(Thread $thread, Participant $participant): bool {
		$attendee = $participant->getAttendee();
		if ($participant->isGuest() || $attendee->getActorType() === Attendee::ACTOR_BOTS) {
			return false;
		}

		if ($participant->hasModeratorPermissions(false)) {
			return true;
		}

		try {
			$comment = $this->getRootComment($thread);
		} catch (NotFoundException) {
			// Root comment can no longer be loaded - only moderators
			// qualify (AC3), and that branch was already evaluated above.
			return false;
		}

		return $comment->getActorType() === $attendee->getActorType()
			&& $comment->getActorId() === $attendee->getActorId();
	}

	/**
	 * Loads the Thread Root Message comment, mirroring
	 * {@see \OCA\Talk\Chat\ChatManager::getComment()}'s object-type/
	 * object-id check without depending on ChatManager - which already
	 * injects ThreadService, so the reverse dependency would be a
	 * container cycle (AD-3). A Thread's id *is* its root comment's id
	 * (AD-5).
	 *
	 * @throws NotFoundException When the root comment no longer exists, or
	 *                            does not belong to this Thread's room.
	 */
	private function getRootComment(Thread $thread): IComment {
		$comment = $this->commentsManager->get((string)$thread->getId());

		if ($comment->getObjectType() !== 'chat' || $comment->getObjectId() !== (string)$thread->getRoomId()) {
			throw new NotFoundException('Message not found in the right context');
		}

		return $comment;
	}

	/**
	 * Throwing counterpart of {@see self::isThreadManager()}, the seam
	 * every management endpoint (this story's renameThread(), and Story
	 * 1.4's four state-transition endpoints) calls to refuse a request
	 * with a typed, distinguishable exception (AD-4) rather than each
	 * re-deriving its own inline check-and-refuse.
	 *
	 * @throws AuthorityException with {@see AuthorityException::REASON_PERMISSION}
	 *                            when $participant is not a Thread Manager
	 *                            for $thread.
	 */
	public function ensureThreadManager(Thread $thread, Participant $participant): void {
		if (!$this->isThreadManager($thread, $participant)) {
			throw new AuthorityException(AuthorityException::REASON_PERMISSION);
		}
	}

	public function createThread(Room $room, int $threadId, string $title): Thread {
		if (mb_strlen($title) > 203) {
			$title = mb_substr($title, 0, 200) . '…';
		}
		$thread = new Thread();
		$thread->setId($threadId);
		$thread->setName($title);
		$thread->setRoomId($room->getId());
		$thread->setLastActivity($this->timeFactory->getDateTime());
		$thread = $this->threadMapper->insert($thread);

		$this->cache->set(self::CACHE_PREFIX . $room->getId() . '/' . $threadId, $thread->toJson(), 60 * 15);

		return $thread;
	}

	/**
	 * Guards a Thread state value ahead of any write to it.
	 *
	 * Used by {@see self::changeState()} (Story 1.4) so the state-change
	 * endpoint calls this rather than re-deriving the valid-value check.
	 *
	 * @throws StateException with {@see StateException::REASON_VALUE} when
	 *                         $state is not one of Thread::STATE_*
	 */
	public function validateState(int $state): void {
		if (!in_array($state, [Thread::STATE_ONGOING, Thread::STATE_CLOSED, Thread::STATE_LOCKED], true)) {
			throw new StateException(StateException::REASON_VALUE);
		}
	}

	/**
	 * Moves a Thread to a new lifecycle state (Story 1.4: close, lock,
	 * reopen from Closed, reopen from Locked - AD-12's single endpoint for
	 * all four transitions). This method does not check authority itself;
	 * it has two distinct kinds of caller instead: Story 1.4's
	 * manager-initiated transitions, which must check authority via
	 * {@see self::ensureThreadManager()} before calling this, and Story
	 * 1.5's {@see self::reviveIfClosed()}, which deliberately does not -
	 * reviving a Closed Thread by posting into it requires no Thread
	 * Manager authority at all.
	 *
	 * Requesting the state the Thread already has (AC8, e.g. closing an
	 * already-Closed Thread) is a complete no-op: no mapper write, no
	 * cache invalidation, and - critically - no reason update even when
	 * $state is Locked and a different $reason is supplied (a reason
	 * update only happens on a genuine transition *into* Locked; see the
	 * story's Dev Notes "Assumption - reason update semantics").
	 *
	 * $reason is only inspected when $state is Thread::STATE_LOCKED; it is
	 * ignored for every other target state. Whitespace-only is trimmed
	 * down to null (AC11) rather than stored as a blank reason.
	 *
	 * @throws StateException with {@see StateException::REASON_VALUE} when
	 *                         $state is not one of Thread::STATE_*
	 * @throws \InvalidArgumentException with message 'reason' when $reason
	 *                                    exceeds {@see Thread::LOCK_REASON_MAX_LENGTH}
	 */
	public function changeState(Thread $thread, int $state, ?string $reason = null): Thread {
		$this->validateState($state);

		// Widen to plain int: comparing $state against the narrow 0|1|2
		// literal-union Thread::getState() returns, then separately
		// against a single member of that same union below, otherwise
		// makes Psalm (incorrectly) treat the two comparisons as
		// contradictory (ParadoxicalCondition).
		/** @var int $previousState */
		$previousState = $thread->getState();
		if ($state === $previousState) {
			return $thread;
		}

		if ($state === Thread::STATE_LOCKED) {
			$reason = $reason !== null ? trim($reason) : null;
			if ($reason === '') {
				$reason = null;
			}
			if ($reason !== null && mb_strlen($reason) > Thread::LOCK_REASON_MAX_LENGTH) {
				throw new \InvalidArgumentException('reason');
			}
			$thread->setLockReason($reason);
		}

		$thread->setState($state);
		$this->threadMapper->update($thread);

		// AD-1, AC15: invalidate by removing the cache entry, never by
		// re-setting it from the entity this mutator happens to hold -
		// under AD-13's last-write-wins, doing so would republish a value
		// a concurrent writer may already have superseded and pin it for
		// the full 900s TTL.
		$this->cache->remove(self::CACHE_PREFIX . $thread->getRoomId() . '/' . $thread->getId());

		return $thread;
	}

	/**
	 * Posting into a Closed Thread revives it to Ongoing (Story 1.5,
	 * FR-3) - a side effect of the normal chat-post path, not a Thread
	 * Manager action. Called from
	 * {@see \OCA\Talk\Chat\ChatManager::sendMessage()} and
	 * {@see \OCA\Talk\Chat\ChatManager::addSystemMessage()} (AD-2) with
	 * the same $threadId those methods already resolved once for
	 * {@see self::updateLastMessageInfoAfterReply()}, so every path that
	 * posts content into a Thread revives it the same way, without a
	 * second, separate id resolution.
	 *
	 * Deliberately bypasses {@see self::ensureThreadManager()} - unlike
	 * Story 1.4's manager-initiated reopen, any participant who may post
	 * in the Conversation revives a Closed Thread just by posting (AC1).
	 *
	 * A Locked Thread is left untouched: the asymmetry with Closed is by
	 * design (AC5). Stories 1.6/1.7's write-refusal guard is what
	 * actually keeps new content out of a Locked Thread; this method only
	 * decides what a *successful* post does to the Thread's state
	 * afterward, and a Locked Thread is never treated as revivable.
	 *
	 * No system message is produced (AC2) - the reply itself is the
	 * record - and {@see self::changeState()}'s cache-remove-not-set
	 * behaviour (AC6) is inherited for free, since this delegates to it.
	 *
	 * An unresolvable $threadId (stale, fabricated, or belonging to a
	 * different room) is a silent no-op, never an error - callers pass
	 * whatever id they already resolved without validating it first.
	 */
	public function reviveIfClosed(int $roomId, int $threadId): void {
		try {
			$thread = $this->findByThreadId($roomId, $threadId);
		} catch (DoesNotExistException) {
			return;
		}

		if ($thread->getState() !== Thread::STATE_CLOSED) {
			return;
		}

		$this->changeState($thread, Thread::STATE_ONGOING);
	}

	/**
	 * Story 1.6, AC1, AC3, AD-2, AD-4: the single shared write-refusal
	 * guard for a Locked Thread. Called from
	 * {@see \OCA\Talk\Chat\ChatManager::sendMessage()} and
	 * {@see \OCA\Talk\Chat\ChatManager::addSystemMessage()} with each
	 * method's own already-resolved effective thread id, evaluated
	 * before their respective `commentsManager->save()` - a refusal that
	 * happens after the save is not a refusal. Also called, as a
	 * documented pre-flight exception, from a small number of controller
	 * call sites whose write is not itself a chat comment and therefore
	 * has a side effect (a poll entity, a moved file, a scheduled-message
	 * row) that would otherwise happen before either ChatManager method
	 * is ever reached (see Story 1.6 Dev Notes).
	 *
	 * Mirrors {@see self::reviveIfClosed()}'s tolerance for an
	 * unresolvable thread id: a stale, fabricated, or foreign id is a
	 * silent no-op, not an error - "not a real Thread here" is not this
	 * guard's concern, and callers that need existence enforced use
	 * {@see self::validateThread()} themselves. Deliberately performs no
	 * authority check - FR-5 refuses every write regardless of who is
	 * writing, unlike the Thread Manager-gated endpoints
	 * {@see self::ensureThreadManager()} protects.
	 *
	 * @throws LockedException with {@see LockedException::REASON_LOCKED}
	 *                          when the Thread is Locked.
	 */
	public function ensureNotLocked(int $roomId, int $threadId): void {
		try {
			$thread = $this->findByThreadId($roomId, $threadId);
		} catch (DoesNotExistException) {
			return;
		}

		if ($thread->getState() === Thread::STATE_LOCKED) {
			throw new LockedException(LockedException::REASON_LOCKED);
		}
	}

	/**
	 * @param non-negative-int $roomId
	 * @param non-negative-int $threadId
	 * @throws DoesNotExistException
	 */
	public function findByThreadId(int $roomId, int $threadId): Thread {
		$row = $this->cache->get(self::CACHE_PREFIX . $roomId . '/' . $threadId);
		if (!empty($row)) {
			return Thread::fromJson($row);
		}

		// We already looked for a thread with this id, and we didn't find anything
		if ($row === '') {
			throw new DoesNotExistException('No thread found');
		}

		try {
			$thread = $this->threadMapper->findById($roomId, $threadId);
			$this->cache->set(self::CACHE_PREFIX . $roomId . '/' . $threadId, $thread->toJson(), 60 * 15);
		} catch (DoesNotExistException $e) {
			$this->cache->set(self::CACHE_PREFIX . $roomId . '/' . $threadId, '', 60 * 15);
			throw $e;
		}
		return $thread;
	}

	/**
	 * @param non-negative-int $roomId
	 * @param list<non-negative-int> $threadIds
	 * @return array<int, Thread> Map with thread id as key
	 */
	public function findByThreadIds(int $roomId, array $threadIds): array {
		$threads = $this->threadMapper->findByIds($roomId, $threadIds);
		$result = [];
		foreach ($threads as $thread) {
			$result[$thread->getId()] = $thread;
		}
		return $result;
	}

	/**
	 * @internal Warning: does not check room memberships
	 * @param list<non-negative-int> $threadIds
	 * @return array<int, Thread> Map with room id as key
	 */
	public function preloadThreadsForConversationList(array $threadIds): array {
		if (empty($threadIds)) {
			return [];
		}
		$threads = $this->threadMapper->getForIds($threadIds);
		$result = [];
		foreach ($threads as $thread) {
			$result[$thread->getRoomId()] = $thread;
		}
		return $result;
	}

	/**
	 * @throws \InvalidArgumentException When the title is empty
	 */
	public function renameThread(Thread $thread, string $title): Thread {
		if ($title === '') {
			throw new \InvalidArgumentException('name');
		}
		if (mb_strlen($title) > 203) {
			$title = mb_substr($title, 0, 200) . '…';
		}
		$thread->setName($title);
		$this->threadMapper->update($thread);

		$this->cache->set(self::CACHE_PREFIX . $thread->getRoomId() . '/' . $thread->getId(), $thread->toJson(), 60 * 15);
		return $thread;
	}

	/**
	 * @param int<1, 50> $limit
	 * @return list<Thread>
	 */
	public function getRecentByRoomId(Room $room, int $limit): array {
		$limit = min(50, max(1, $limit));
		return $this->threadMapper->getRecentByRoomId($room->getId(), $limit);
	}

	/**
	 * @param int<1, 100> $limit
	 * @param non-negative-int $offset
	 */
	public function getRecentByActor(string $actorType, string $actorId, int $limit, int $offset): array {
		$limit = min(100, max(1, $limit));

		$query = $this->connection->getQueryBuilder();
		$query->select('a.*');
		// Row-key convention: always hydrate Thread rows through SelectHelper's
		// th_-prefixed aliases, so this direct-query path and the joined-read
		// path in ScheduledMessageMapper::findByRoomAndActor() stay reconcilable
		// with Thread::createFromRow() (AD-1).
		(new SelectHelper())->selectThreadsTable($query, 't', aliasAll: true);
		$query->from('talk_thread_attendees', 'a')
			->join('a', 'talk_threads', 't', $query->expr()->andX(
				$query->expr()->eq('a.thread_id', 't.id'),
				$query->expr()->eq('a.room_id', 't.room_id'),
			))
			->where($query->expr()->eq('a.actor_type', $query->createNamedParameter($actorType)))
			->andWhere($query->expr()->eq('a.actor_id', $query->createNamedParameter($actorId)))
			->andWhere($query->expr()->neq('a.notification_level', $query->createNamedParameter(Participant::NOTIFY_NEVER)))
			// FIXME ORDER BY last_activity and subscription moment of the user for better sorting?
			->orderBy('t.last_activity', 'DESC')
			->setMaxResults($limit);

		if ($offset > 0) {
			$query->setFirstResult($offset);
		}

		$results = [];
		$result = $query->executeQuery();
		while ($row = $result->fetchAssociative()) {
			$roomId = (int)$row['room_id'];
			$results[$roomId][] = [
				'thread' => Thread::createFromRow($row),
				'attendee' => ThreadAttendee::createFromRow($row),
			];
		}
		$result->closeCursor();

		return $results;
	}

	/**
	 * @param list<int> $threadIds
	 * @return array<int, ThreadAttendee> Key is the thread id
	 */
	public function findAttendeeByThreadIds(Attendee $attendee, array $threadIds): array {
		$attendees = $this->threadAttendeeMapper->findAttendeeByThreadIds($attendee->getActorType(), $attendee->getActorId(), $attendee->getRoomId(), $threadIds);
		$threadAttendees = [];
		foreach ($attendees as $threadAttendee) {
			$threadAttendees[$threadAttendee->getThreadId()] = $threadAttendee;
		}

		return $threadAttendees;
	}

	/**
	 * @return array<int, ThreadAttendee> Key is the attendee id
	 */
	public function findAttendeesForNotificationByThreadId(int $roomId, int $threadId): array {
		$attendees = $this->threadAttendeeMapper->findAttendeesForNotification($roomId, $threadId);
		$threadAttendees = [];
		foreach ($attendees as $threadAttendee) {
			$threadAttendees[$threadAttendee->getAttendeeId()] = $threadAttendee;
		}

		return $threadAttendees;
	}

	public function setNotificationLevel(Attendee $attendee, int $threadId, int $level): ThreadAttendee {
		try {
			$threadAttendee = $this->threadAttendeeMapper->findAttendeeByThreadId($attendee->getActorType(), $attendee->getActorId(), $attendee->getRoomId(), $threadId);
			$threadAttendee->setNotificationLevel($level);
			$this->threadAttendeeMapper->update($threadAttendee);
		} catch (DoesNotExistException) {
			$threadAttendee = new ThreadAttendee();
			$threadAttendee->setThreadId($threadId);
			$threadAttendee->setRoomId($attendee->getRoomId());

			$threadAttendee->setAttendeeId($attendee->getId());
			$threadAttendee->setActorType($attendee->getActorType());
			$threadAttendee->setActorId($attendee->getActorId());
			$threadAttendee->setNotificationLevel($level);
			$this->threadAttendeeMapper->insert($threadAttendee);
		}

		return $threadAttendee;
	}

	public function ensureIsThreadAttendee(Attendee $attendee, int $threadId): void {
		try {
			$this->threadAttendeeMapper->findAttendeeByThreadId($attendee->getActorType(), $attendee->getActorId(), $attendee->getRoomId(), $threadId);
		} catch (DoesNotExistException) {
			$threadAttendee = new ThreadAttendee();
			$threadAttendee->setThreadId($threadId);
			$threadAttendee->setRoomId($attendee->getRoomId());

			$threadAttendee->setAttendeeId($attendee->getId());
			$threadAttendee->setActorType($attendee->getActorType());
			$threadAttendee->setActorId($attendee->getActorId());
			$threadAttendee->setNotificationLevel(Participant::NOTIFY_DEFAULT);
			$this->threadAttendeeMapper->insert($threadAttendee);
		}
	}

	/**
	 * Used e.g. when a user or group is removed from a conversation
	 * @param list<int> $attendeeIds
	 */
	public function removeThreadAttendeesByAttendeeIds(array $attendeeIds): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('talk_thread_attendees')
			->where($query->expr()->in(
				'attendee_id',
				$query->createNamedParameter($attendeeIds, IQueryBuilder::PARAM_INT_ARRAY)
			));
		$query->executeStatement();
	}

	public function updateLastMessageInfoAfterReply(int $threadId, int $lastMessageId, int $roomId): bool {
		$dateTime = $this->timeFactory->getDateTime();

		$query = $this->connection->getQueryBuilder();
		$query->update('talk_threads')
			->set('num_replies', $query->func()->add('num_replies', $query->expr()->literal(1)))
			->set('last_message_id', $query->createNamedParameter($lastMessageId))
			->set('last_activity', $query->createNamedParameter($dateTime, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($threadId)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($roomId)));
		$this->cache->remove(self::CACHE_PREFIX . $roomId . '/' . $threadId);
		return (bool)$query->executeStatement();
	}

	public function deleteByRoom(Room $room): void {
		$this->cache->clear(self::CACHE_PREFIX . $room->getId() . '/');
		$this->threadMapper->deleteByRoomId($room->getId());
		$this->threadAttendeeMapper->deleteByRoomId($room->getId());
	}

	public function validateThread(int $roomId, int $potentialThreadId): bool {
		try {
			$this->findByThreadId($roomId, $potentialThreadId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * Reaps Threads whose root comment no longer exists (Story 1.10, AC2,
	 * AD-5: a Thread's id *is* its root comment's id, so message expiry -
	 * which hard-deletes comment rows, unlike a tombstoning author/moderator
	 * delete, which does not - leaves the talk_threads row (and its
	 * talk_thread_attendees rows) with nothing that would otherwise ever
	 * remove them).
	 *
	 * Bounded per call (AC3): {@see ThreadMapper::findOrphanedThreadIds()}'s
	 * LIMIT means one call reaps at most $limit Threads; the caller
	 * ({@see \OCA\Talk\BackgroundJob\ReapOrphanedThreads}) loops calls until
	 * one returns fewer than $limit, mirroring
	 * {@see \OCA\Talk\BackgroundJob\CleanupStaleSessions}'s drain pattern.
	 *
	 * AC4: every reaped Thread's cache entry is removed - never re-set,
	 * per AD-1 - *before* its rows are deleted, in this same pass. That
	 * ordering means a reader racing this method can only ever observe
	 * "cache empty, mapper still has the row for a moment", never the
	 * reverse ("cache still warm, row already gone"), which is the one
	 * ordering that would let validateThread() keep answering true from a
	 * stale cache entry for a Thread that no longer exists.
	 *
	 * AC6: nextcloud/spreed#16739 ("Delete empty Talk threads") is the
	 * open upstream issue occupying this same ground. Its literal repro is
	 * a *soft*-deleted (tombstoned) root - which, per AD-5/AC1, this
	 * reaper deliberately never touches, since a tombstoned root still has
	 * a `comments` row and therefore never matches
	 * findOrphanedThreadIds()'s query. This method only ever fires on a
	 * *hard*-deleted (expired) root. If/when upstream lands its own fix
	 * for #16739, reconcile deliberately against that distinction rather
	 * than assuming the two problems are identical.
	 *
	 * @param positive-int $limit
	 * @return int Number of Threads reaped
	 */
	public function reapOrphanedThreads(int $limit): int {
		$orphaned = $this->threadMapper->findOrphanedThreadIds($limit);
		if ($orphaned === []) {
			return 0;
		}

		foreach ($orphaned as $row) {
			$this->cache->remove(self::CACHE_PREFIX . $row['room_id'] . '/' . $row['id']);
		}

		$ids = array_column($orphaned, 'id');
		$this->threadAttendeeMapper->deleteByThreadIds($ids);
		return $this->threadMapper->deleteByIds($ids);
	}
}
