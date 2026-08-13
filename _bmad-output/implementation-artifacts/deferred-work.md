# Deferred Work

Pre-existing issues surfaced incidentally during review. Not caused by the story that found them.

- source_spec: `spec-4-1-thread-notifications-name-the-thread.md`
  summary: `Chat\Notifier::notifyOtherParticipant()` derives its thread id from a raw `getTopmostParentId()` without the `validateThread()` call every other producer performs.
  evidence: `lib/Chat/Notifier.php:269` sets `$threadId = (int)$comment->getTopmostParentId()` with no validation, while `ChatManager::sendMessage()` at `lib/Chat/ChatManager.php:456-457` zeroes the id when `ThreadService::validateThread()` fails. A reply chain with no `talk_threads` row therefore produces a `chat`-subject notification carrying a thread id that names no Thread, which reaches both the deep link (`lib/Notification/Notifier.php:607-609`) and the composed object id. The `reply` subject on the same message correctly carries none, so two notifications about one message disagree.

- source_spec: `spec-4-1-thread-notifications-name-the-thread.md`
  summary: A message that creates a Thread and mentions someone produces a mention notification that can never name the Thread, because the `talk_threads` row does not exist yet when the notification is dispatched.
  evidence: `ChatController::sendMessage()` calls `ThreadService::createThread()` at `lib/Controller/ChatController.php:431`, i.e. only after `ChatManager::sendMessage()` has returned — but notifications are dispatched inside that call at `lib/Chat/ChatManager.php:560-578`. Any title lookup at notify time misses. Fixing this means reordering thread-row creation ahead of notification dispatch, which touches Epic 1 territory and is too large to fold into a notification story.

- source_spec: `spec-4-1-thread-notifications-name-the-thread.md`
  summary: Mentions added by *editing* an existing message never carry a thread id, so an edit-triggered mention notification inside a Thread cannot name it.
  evidence: `lib/Chat/ChatManager.php:832` calls `$this->notifier->notifyMentionedUsers($chat, $comment, $usersToNotifyBefore, silent: false)` without the `threadId:` named argument that the other two call sites (`:293`, `:568`) pass. The parameter defaults to null, so the notification is built without thread context even when the edited message sits inside a Thread.
