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

- source_spec: `spec-4-2-thread-lifecycle-changes-notify-followers.md`
  summary: Lifecycle notifications are not removed when the Thread itself is reaped, so they outlive their target and deep-link into a Thread that no longer exists.
  evidence: `ThreadService::reapOrphanedThreads()` (`lib/Service/ThreadService.php:574-587`) hard-deletes `talk_threads` and `talk_thread_attendees` rows when the root comment expires and never touches notifications. `Chat\Notifier::removePendingNotificationsForRoom()` only clears at room granularity. `parseThreadStateChange()` performs no existence check, and `ThreadService` is not injected into `Notification\Notifier`, so adding one is a structural change rather than a patch.

- source_spec: `spec-4-2-thread-lifecycle-changes-notify-followers.md`
  summary: Repeated lifecycle transitions accumulate one notification each, with no coalescing or superseding, so any Thread Manager can flood every follower's inbox by toggling state.
  evidence: The notification object id is deliberately the bare room token (AD-12 forbids a composed `{token}/{threadId}`, which shipped clients would read positionally as `{token}/{messageId}`). `markProcessed()` on that object would therefore erase every Thread's notifications in the room, not just the one being superseded. Note the Thread Manager role includes the ordinary participant who authored the root message (`ThreadService::isThreadManager()`, `lib/Service/ThreadService.php:69-89`), so this is not moderator-only. Resolving it needs a per-Thread notification identity, which is a design decision rather than a fix.

- source_spec: `spec-4-2-thread-lifecycle-changes-notify-followers.md`
  summary: No capability flag announces the four new lifecycle notification subjects, so clients cannot feature-detect them or degrade deliberately.
  evidence: `lib/Capabilities.php` is untouched by this work, in contrast to Story 1.4 which published `threads.lock-reason-length`. AD-12 calls for additive API surfacing via capability flags.
