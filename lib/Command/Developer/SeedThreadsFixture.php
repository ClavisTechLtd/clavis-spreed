<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Command\Developer;

use OC\Core\Command\Base;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Exceptions\RoomProperty\CreationException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Thread;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCA\Talk\Service\ThreadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Story 1.1 (Epic 1, AC4): builds the NFR-1 scale fixture — a Conversation with
 * at least 1000 Threads and 200 participants, activity dates spread across a
 * year — as an engineering deliverable, not a test convenience. The same
 * fixture backs the AC5 pre-work response-time baseline and the AC6 pre-read
 * baseline, and is meant to be reused by later Epic 2/3 stories rather than
 * rebuilt.
 *
 * Follows the ChatController::sendMessage() thread-creation orchestration
 * exactly (ChatManager::sendMessage() with threadId: Thread::THREAD_CREATE,
 * followed by ThreadService::createThread() with the resulting comment id)
 * rather than writing rows directly, so the fixture is structurally identical
 * to what real usage produces. It intentionally skips the system-message and
 * notification side effects a real HTTP request would also trigger, since
 * they are not required by any of this story's acceptance criteria and would
 * multiply the write volume for 1000+ Threads with no benefit to the AC4/AC5
 * measurements this fixture exists for.
 */
class SeedThreadsFixture extends Base {
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly ISecureRandom $secureRandom,
		private readonly Manager $manager,
		private readonly RoomService $roomService,
		private readonly ParticipantService $participantService,
		private readonly ThreadService $threadService,
		private readonly ChatManager $chatManager,
		private readonly ITimeFactory $timeFactory,
	) {
		parent::__construct();
	}

	public function isEnabled(): bool {
		return $this->config->getSystemValue('debug', false) === true;
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('talk:developer:seed-threads')
			->setDescription('Seeds a Conversation with a deterministic, re-runnable set of Threads and participants at the NFR-1 scale target (>=1000 Threads, >=200 participants, activity spread across a year), for scale testing and the AC-2/AC-5 response-time baseline (Epic 1, Story 1.1)')
			->addOption(
				'token',
				null,
				InputOption::VALUE_REQUIRED,
				'Token of an existing room to top up instead of creating a new one',
			)
			->addOption(
				'threads',
				null,
				InputOption::VALUE_REQUIRED,
				'Number of additional Threads to create',
				'1000',
			)
			->addOption(
				'participants',
				null,
				InputOption::VALUE_REQUIRED,
				'Number of participants to ensure exist in the Conversation',
				'200',
			)
			->addOption(
				'user-prefix',
				null,
				InputOption::VALUE_REQUIRED,
				'Prefix for the synthetic fixture user ids this command creates or reuses',
				'talk-fixture-user-',
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$targetThreads = max(1, (int)$input->getOption('threads'));
		$targetParticipants = max(1, (int)$input->getOption('participants'));
		$userPrefix = (string)$input->getOption('user-prefix');
		$token = $input->getOption('token');

		if ($token !== null) {
			try {
				$room = $this->manager->getRoomByToken($token);
			} catch (RoomNotFoundException) {
				$output->writeln('<error>Room not found: ' . $token . '</error>');
				return 1;
			}
			$output->writeln('Reusing existing room "' . $room->getName() . '" (' . $room->getToken() . ')');
		} else {
			$owner = $this->ensureUser($userPrefix . '0000', $output);
			try {
				$room = $this->roomService->createConversation(
					Room::TYPE_GROUP,
					'Talk Scale Fixture ' . $this->timeFactory->getDateTime()->format('Y-m-d H:i:s'),
					$owner,
				);
			} catch (CreationException $e) {
				$output->writeln('<error>Could not create fixture Conversation: ' . $e->getMessage() . '</error>');
				return 1;
			}
			$output->writeln('Created new room "' . $room->getName() . '" (' . $room->getToken() . ')');
		}

		$output->writeln('Ensuring ' . $targetParticipants . ' participants (prefix "' . $userPrefix . '")...');
		$userIds = [];
		for ($i = 1; $i <= $targetParticipants; $i++) {
			$userId = $userPrefix . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
			$userIds[] = $userId;
			$this->ensureUser($userId, $output);
		}

		$existingParticipantIds = [];
		foreach ($this->participantService->getParticipantsForRoom($room) as $existingParticipant) {
			if ($existingParticipant->getAttendee()->getActorType() === Attendee::ACTOR_USERS) {
				$existingParticipantIds[$existingParticipant->getAttendee()->getActorId()] = true;
			}
		}

		$toAdd = [];
		foreach ($userIds as $userId) {
			if (!isset($existingParticipantIds[$userId])) {
				$toAdd[] = [
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $userId,
				];
			}
		}
		if (!empty($toAdd)) {
			$this->participantService->addUsers($room, $toAdd);
			$output->writeln('Added ' . count($toAdd) . ' new participant(s); ' . count($existingParticipantIds) . ' already present.');
		} else {
			$output->writeln('All ' . count($userIds) . ' target participants already present.');
		}

		$output->writeln('Creating ' . $targetThreads . ' Threads with activity deterministically spread across the last year...');
		$now = $this->timeFactory->getDateTime();
		$yearAgo = (clone $now)->modify('-365 days');
		$totalSeconds = max(1, $now->getTimestamp() - $yearAgo->getTimestamp());

		for ($i = 0; $i < $targetThreads; $i++) {
			$userId = $userIds[$i % count($userIds)];
			$participant = $this->participantService->getParticipantByActor($room, Attendee::ACTOR_USERS, $userId);

			// Deterministic, not random: spreads thread indices evenly across
			// the year so re-running this command against the same room with
			// the same options always produces the same activity distribution.
			$offsetSeconds = intdiv($i * $totalSeconds, $targetThreads);
			$creationDateTime = (clone $yearAgo)->modify('+' . $offsetSeconds . ' seconds');
			$threadTitle = 'Fixture Thread ' . ($i + 1);

			$comment = $this->chatManager->sendMessage(
				$room,
				$participant,
				Attendee::ACTOR_USERS,
				$userId,
				'Root message for ' . $threadTitle,
				$creationDateTime,
				null,
				'',
				true,
				true,
				Thread::THREAD_CREATE,
				$threadTitle,
			);

			$thread = $this->threadService->createThread($room, (int)$comment->getId(), $threadTitle);
			$this->threadService->setNotificationLevel($participant->getAttendee(), $thread->getId(), Participant::NOTIFY_DEFAULT);

			if (($i + 1) % 100 === 0 || $i + 1 === $targetThreads) {
				$output->writeln('  ...' . ($i + 1) . '/' . $targetThreads . ' Threads created');
			}
		}

		$output->writeln('<info>Done. Room token: ' . $room->getToken() . '</info>');
		$output->writeln('Re-run with --token ' . $room->getToken() . ' to add more Threads/participants to this same fixture.');

		return 0;
	}

	private function ensureUser(string $userId, OutputInterface $output): IUser {
		$user = $this->userManager->get($userId);
		if ($user instanceof IUser) {
			return $user;
		}

		$password = $this->secureRandom->generate(32, ISecureRandom::CHAR_HUMAN_READABLE);
		$user = $this->userManager->createUser($userId, $password);
		if (!$user instanceof IUser) {
			throw new RuntimeException('Failed to create fixture user "' . $userId . '"');
		}
		$output->writeln('Created fixture user "' . $userId . '"', OutputInterface::VERBOSITY_VERBOSE);

		return $user;
	}
}
