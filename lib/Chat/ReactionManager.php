<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Chat;

use OCA\Talk\Events\BeforeReactionAddedEvent;
use OCA\Talk\Events\BeforeReactionRemovedEvent;
use OCA\Talk\Events\ReactionAddedEvent;
use OCA\Talk\Events\ReactionRemovedEvent;
use OCA\Talk\Exceptions\ReactionAlreadyExistsException;
use OCA\Talk\Exceptions\ReactionNotSupportedException;
use OCA\Talk\Exceptions\ReactionOutOfContextException;
use OCA\Talk\Exceptions\ThreadProperty\LockedException;
use OCA\Talk\Participant;
use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Room;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\PreConditionNotMetException;

/**
 * @psalm-import-type TalkReaction from ResponseDefinitions
 */
class ReactionManager {

	public function __construct(
		private readonly ChatManager $chatManager,
		private readonly CommentsManager $commentsManager,
		private readonly IL10N $l,
		private readonly MessageParser $messageParser,
		private readonly Notifier $notifier,
		private readonly IEventDispatcher $dispatcher,
		private readonly ITimeFactory $timeFactory,
		private readonly ThreadService $threadService,
	) {
	}

	/**
	 * Add reaction
	 *
	 * @throws NotFoundException
	 * @throws ReactionAlreadyExistsException
	 * @throws ReactionNotSupportedException
	 * @throws ReactionOutOfContextException
	 * @throws LockedException with {@see LockedException::REASON_LOCKED} when the Thread is Locked (Story 1.7, AC1, AC5).
	 */
	public function addReactionMessage(Room $chat, string $actorType, string $actorId, string $actorDisplayName, int $messageId, string $reaction): IComment {
		$parentMessage = $this->getCommentToReact($chat, (string)$messageId);

		// Story 1.7, AC1, AC5, AC7: the third enforcement seam - this
		// method reaches commentsManager->save() directly (a new reaction
		// comment), never through sendMessage()/addSystemMessage(), so it
		// needs its own call to the single shared guard, evaluated before
		// any write - but after getCommentToReact()'s own pre-existing
		// structural validation of the target message.
		$threadId = (int)$parentMessage->getTopmostParentId() ?: (int)$parentMessage->getId();
		$this->threadService->ensureNotLocked($chat->getId(), $threadId);

		try {
			// Check if the user already reacted with the same reaction
			$this->commentsManager->getReactionComment(
				(int)$parentMessage->getId(),
				$actorType,
				$actorId,
				$reaction
			);
			throw new ReactionAlreadyExistsException();
		} catch (NotFoundException) {
		}

		/** @var IComment $comment */
		$comment = $this->commentsManager->create(
			$actorType,
			$actorId,
			'chat',
			(string)$chat->getId()
		);
		$comment->setParentId($parentMessage->getId());
		$comment->setMessage($reaction);
		$comment->setVerb(ChatManager::VERB_REACTION);
		$comment->setExpireDate($parentMessage->getExpireDate());

		$event = new BeforeReactionAddedEvent($chat, $parentMessage, $actorType, $actorId, $actorDisplayName, $reaction);
		$this->dispatcher->dispatchTyped($event);

		$this->commentsManager->save($comment);

		$event = new ReactionAddedEvent($chat, $parentMessage, $actorType, $actorId, $actorDisplayName, $reaction, $comment);
		$this->dispatcher->dispatchTyped($event);

		$this->notifier->notifyReacted($chat, $parentMessage, $comment);
		return $comment;
	}

	/**
	 * Delete reaction
	 *
	 * @param Room $chat
	 * @param string $actorType
	 * @param string $actorId
	 * @param integer $messageId
	 * @param string $reaction
	 * @return IComment
	 * @throws NotFoundException
	 * @throws ReactionNotSupportedException
	 * @throws ReactionOutOfContextException
	 * @throws LockedException with {@see LockedException::REASON_LOCKED} when the Thread is Locked (Story 1.7, AC1, AC5).
	 */
	public function deleteReactionMessage(Room $chat, string $actorType, string $actorId, string $actorDisplayName, int $messageId, string $reaction): IComment {
		// Just to verify that messageId is part of the room and throw error if not.
		$parentComment = $this->getCommentToReact($chat, (string)$messageId);

		// Story 1.7, AC1, AC5, AC7: the third enforcement seam - evaluated
		// before the event dispatch and before the reaction comment's own
		// commentsManager->save() below.
		$threadId = (int)$parentComment->getTopmostParentId() ?: (int)$parentComment->getId();
		$this->threadService->ensureNotLocked($chat->getId(), $threadId);

		$event = new BeforeReactionRemovedEvent($chat, $parentComment, $actorType, $actorId, $actorDisplayName, $reaction);
		$this->dispatcher->dispatchTyped($event);

		$comment = $this->commentsManager->getReactionComment(
			$messageId,
			$actorType,
			$actorId,
			$reaction
		);
		$comment->setMessage(
			json_encode([
				'deleted_by_type' => $actorType,
				'deleted_by_id' => $actorId,
				'deleted_on' => $this->timeFactory->getDateTime()->getTimestamp(),
			])
		);
		$comment->setVerb(ChatManager::VERB_REACTION_DELETED);
		$this->commentsManager->save($comment);

		$revokedComment = $this->chatManager->addSystemMessage(
			$chat,
			null,
			$actorType,
			$actorId,
			json_encode(['message' => 'reaction_revoked', 'parameters' => ['message' => (int)$comment->getId()]]),
			$this->timeFactory->getDateTime(),
			false,
			null,
			$parentComment,
			true
		);

		$event = new ReactionRemovedEvent($chat, $parentComment, $actorType, $actorId, $actorDisplayName, $reaction, $revokedComment);
		$this->dispatcher->dispatchTyped($event);

		return $comment;
	}

	/**
	 * @return array<string, list<TalkReaction>>
	 * @throws PreConditionNotMetException
	 */
	public function retrieveReactionMessages(Room $chat, Participant $participant, int $messageId, ?string $reaction = null): array {
		if ($reaction) {
			$comments = $this->commentsManager->retrieveAllReactionsWithSpecificReaction($messageId, $reaction);
		} else {
			$comments = $this->commentsManager->retrieveAllReactions($messageId);
		}

		$reactions = [];
		foreach ($comments as $comment) {
			$message = $this->messageParser->createMessage($chat, $participant, $comment, $this->l);
			$this->messageParser->parseMessage($message);

			$reactions[$comment->getMessage()][] = [
				'actorType' => $comment->getActorType(),
				'actorId' => $comment->getActorId(),
				'actorDisplayName' => $message->getActorDisplayName(),
				'timestamp' => $comment->getCreationDateTime()->getTimestamp(),
			];
		}
		return $reactions;
	}

	/**
	 * @param Participant $participant
	 * @param array $messageIds
	 * @return array[]
	 * @psalm-return array<int, string[]>
	 */
	public function getReactionsByActorForMessages(Participant $participant, array $messageIds): array {
		return $this->commentsManager->retrieveReactionsByActor(
			$participant->getAttendee()->getActorType(),
			$participant->getAttendee()->getActorId(),
			$messageIds
		);
	}

	/**
	 * @param Room $chat
	 * @param string $messageId
	 * @return IComment
	 * @throws NotFoundException
	 * @throws ReactionNotSupportedException
	 * @throws ReactionOutOfContextException
	 */
	public function getCommentToReact(Room $chat, string $messageId): IComment {
		if (!$this->commentsManager->supportReactions()) {
			throw new ReactionNotSupportedException();
		}
		$comment = $this->commentsManager->get($messageId);

		if ($comment->getObjectType() !== 'chat'
			|| $comment->getObjectId() !== (string)$chat->getId()
			|| !in_array($comment->getVerb(), [
				ChatManager::VERB_MESSAGE,
				ChatManager::VERB_OBJECT_SHARED,
			], true)) {
			throw new ReactionOutOfContextException();
		}

		return $comment;
	}
}
