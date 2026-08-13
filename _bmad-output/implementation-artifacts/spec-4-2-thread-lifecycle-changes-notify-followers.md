---
title: "Story 4.2: Thread lifecycle changes notify a Thread's followers"
type: 'feature'
created: '2026-08-12'
status: 'done'
review_loop_iteration: 0
followup_review_recommended: true
baseline_revision: '16a89dda9'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: ['oversized']
---

<intent-contract>

## Intent

**Problem:** Locking, closing or reopening a Thread emits a system message and nothing else. A participant following the Thread learns it is shut only by writing a reply and having it refused, and never learns why even when the person who locked it supplied a reason.

**Approach:** Where the lifecycle system message is already emitted, also notify the Thread's subscribed followers, reusing the recipient computation that already exists and reading the reason from the same parameter data the system message carries. Render the four transitions through a new notification parser that withholds the Thread Title and the reason under the shipped sensitive-conversation mechanism.

## Boundaries & Constraints

**Always:**
- Recipients come from `ThreadService::findAttendeesForNotificationByThreadId()`, narrowed to `Participant::NOTIFY_ALWAYS` — the same rule `notifyOtherParticipant()` already applies to thread attendees. No second recipient query, no new mapper method.
- The participant who performed the transition is never notified of their own action.
- The lock reason is read from the **parameter array already assembled for the system message**, never from a rendered message string, and never re-derived by re-reading the Thread.
- A notification is emitted only where a lifecycle **system message** is emitted, so anything that changes state without emitting one stays silent.
- Thread Title and lock reason are withheld together whenever the recipient's attendee row `isSensitive()`, reusing the shipped mechanism rather than paralleling it.
- Both are user-authored content: rendered as rich-object parameters, never passed through `IL10N::t()`.
- Only `Attendee::ACTOR_USERS` receive these notifications.

**Block If:**
- The recipient set cannot be derived from `findAttendeesForNotificationByThreadId()` without adding a query.
- Emitting the notification would require `ThreadService::changeState()` to know about notifications.

**Never:**
- Do not modify the subscription logic at `lib/Chat/Notifier.php:253-293`.
- Do not emit a notification from `ThreadService::changeState()` — `reviveIfClosed()` routes through it, and revival by reply must stay silent.
- Do not give the notification a composed object id of the form `{token}/{threadId}`. Shipped clients parse that shape positionally and would read the thread id as a message id (AD-12).
- Do not store these notifications under the `chat` object type — `markMentionNotificationsRead()` marks every `chat` notification for the user in that room as processed, which would silently erase them.
- Do not change the four system messages, their parameters, or `lib/Chat/Parser/SystemMessage.php`.
- Do not touch the nine chat subjects or `parseChatMessage()`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lock with reason | Follower at `NOTIFY_ALWAYS`, non-sensitive | Notification naming the Thread, the actor and the reason | No error expected |
| Lock without reason | Same, no reason supplied | Notification naming the Thread and the actor, no reason parameter | No error expected |
| Close and reopen | Same, state changes to Closed then Ongoing | One notification per transition, same terms | No error expected |
| Unlock | Locked → Ongoing | Notification on the same terms | No error expected |
| Muted follower | Thread notification level `NOTIFY_MENTION` or `NOTIFY_NEVER` | No notification | No error expected |
| Non-follower | No `talk_thread_attendees` row, even if a room participant | No notification | No error expected |
| Actor | The Thread Manager performing the transition | No notification to themselves | No error expected |
| Revival by reply | Closed Thread revived via `reviveIfClosed()` | Ordinary reply notification only, no state-change notification | No error expected |
| Sensitive conversation | Follower whose attendee row `isSensitive()` | Generic subject naming neither Thread nor reason | No error expected |
| No-op transition | Requested state equals current state | No system message and no notification | No error expected |
| Root message expired | Thread row present, root comment gone | Notification still emitted | Missing comment is not an error |

</intent-contract>

## Code Map

- `lib/Controller/ThreadController.php:321-362` -- the `$previousState !== $thread->getState()` block that picks the verb, assembles `$parameters` (`thread`, `title`, and `reason` only for `thread_locked`), and emits the system message. **The notification is emitted here, immediately after, reusing that same `$parameters` array.**
- `lib/Controller/ThreadController.php:45-59` -- constructor; `Chat\Notifier` is not injected yet.
- `lib/Service/ThreadService.php:187` -- `changeState()`; emits no system message and must stay notification-free.
- `lib/Service/ThreadService.php:254-266` -- `reviveIfClosed()` calls `changeState()`; this is the AC6 path.
- `lib/Service/ThreadService.php:450` -- `findAttendeesForNotificationByThreadId()`, returning `array<int, ThreadAttendee>` keyed by attendee id.
- `lib/Model/ThreadAttendeeMapper.php:123-141` -- backing query; already excludes `NOTIFY_DEFAULT`, so only explicit overrides come back.
- `lib/Chat/Notifier.php:286-292` -- the existing `NOTIFY_ALWAYS` filter on thread attendees; the precedent to mirror.
- `lib/Chat/Notifier.php:353-380` -- `removePendingNotificationsForRoom()`; its `$objectTypes` list needs `thread` so room deletion cleans these up.
- `lib/Chat/Notifier.php:412-425` -- `markMentionNotificationsRead()`; the reason the `chat` object type is unusable here.
- `lib/Service/ParticipantService.php:1925` -- `getParticipantsByAttendeeId()`, for turning attendee ids into participants.
- `lib/Notification/Notifier.php:252-287` -- subject dispatch; the four new subjects branch **before** the nine-subject gate.
- `lib/Notification/Notifier.php:1063-1100` -- `parseInvitation()`, the structural model for a small parser.
- `lib/Notification/Notifier.php:649-684` -- the sensitive branch to mirror for withholding.
- `lib/Chat/Parser/SystemMessage.php:618-643` -- how title and reason are already rendered as rich objects.
- `tests/php/Notification/NotifierTest.php` -- parser tests.
- `tests/php/Chat/NotifierTest.php` -- recipient-selection tests.
- `tests/integration/features/chat-4/threads.feature` -- lifecycle scenarios already exist here.

## Tasks & Acceptance

**Execution:**

- [x] `lib/Chat/Notifier.php` -- add `notifyThreadStateChange(Room $chat, Participant $actor, int $threadId, string $verb, array $parameters): void`: fetch thread attendees via `findAttendeesForNotificationByThreadId()`, keep only `NOTIFY_ALWAYS`, resolve them with `getParticipantsByAttendeeId()`, skip the actor and every non-`ACTOR_USERS` attendee, and emit one notification per recipient with object type `thread`, object id the room token, and subject `$verb` carrying the actor plus the supplied `thread`/`title`/`reason` parameters. Rationale: one recipient rule, reusing the existing query.
- [x] `lib/Chat/Notifier.php` -- add `thread` to the `$objectTypes` list in `removePendingNotificationsForRoom()`. Rationale: without it these notifications outlive the conversation.
- [x] `lib/Controller/ThreadController.php` -- inject `Chat\Notifier`; inside the existing `$previousState !== $thread->getState()` block, after `addSystemMessage()`, call `notifyThreadStateChange()` with the **same** `$parameters` array. Rationale: binds the notification to the system message, which is what keeps revival-by-reply silent.
- [x] `lib/Notification/Notifier.php` -- dispatch `thread_locked`, `thread_closed`, `thread_unlocked`, `thread_reopened` to a new `parseThreadStateChange()` placed before the nine-subject gate; render one translatable template per verb, with a separate with-reason template for `thread_locked` (mirroring `SystemMessage.php`); expose `thread` and `reason` as `highlight` rich objects; set the link to the Thread; and, when the attendee `isSensitive()`, emit a generic subject with no thread and no reason parameters. Rationale: AD-20 withholding and NFR-10 non-translation in one place.
- [x] `tests/php/Chat/NotifierTest.php` -- cover recipient selection: `NOTIFY_ALWAYS` follower notified; `NOTIFY_MENTION` and `NOTIFY_NEVER` not; absent row not; actor not; non-user attendee not; and that the reason parameter is passed through untouched. Rationale: AC3-AC5 are all recipient rules.
- [x] `tests/php/Notification/NotifierTest.php` -- cover parsing of all four verbs, `thread_locked` with and without a reason, and the sensitive case withholding both title and reason. Rationale: AC1, AC2, AC7.
- [x] `tests/integration/features/chat-4/threads.feature` -- scenarios for: a follower notified on lock with a reason, on close and on reopen; a muted follower and a non-follower receiving nothing; and a Closed Thread revived by a reply producing only the ordinary reply notification. Rationale: AC6 and AC9 are end-to-end orderings that unit tests cannot express.

**Acceptance Criteria:**

- Given a Thread with one follower at `NOTIFY_ALWAYS` and one at `NOTIFY_NEVER`, when a Thread Manager locks it with a reason, then exactly one notification is emitted, to the first follower, carrying the Thread Title, the actor and the reason.
- Given a Closed Thread, when a participant posts into it and it revives through `reviveIfClosed()`, then no state-change notification is emitted and only the ordinary reply notification is produced.
- Given a follower whose attendee row is sensitive, when any transition occurs, then the notification names neither the Thread nor the reason.
- Given a requested state equal to the current state, when the endpoint is called, then neither a system message nor a notification is emitted.
- Given the four new subjects, when `prepare()` runs, then none of them reaches `parseChatMessage()`.
- Given `composer run psalm` and `composer run lint`, when run over the changed files, then both report no new findings.

## Spec Change Log

### 2026-08-12 — The `thread` object type was wrong; corrected to `room`

- **Triggering finding:** the shipped mobile clients hard-code the set of notification object types they accept. Android (`NotificationWorker.kt:184-189`) dispatches `chat|room|call|recording|remote_talk_share|reminder` and sends anything else to `else -> Log.e(...)`, discarding the push; iOS (`NCPushNotification.m:53-67`) leaves it `NCPushNotificationTypeUnknown` and cannot route the deep link. A `thread` object type therefore made this feature invisible on Android and untappable on iOS.
- **What was amended:** nothing inside `<intent-contract>`. Its `Never` clause forbids the `chat` object type and does not mandate `thread`, so `room` satisfies it as written. The Design Notes rationale was rewritten.
- **Known-bad state avoided:** shipping a notification feature that produces nothing on the most common client, with a green unit suite.
- **KEEP:** the constraint that made `chat` unusable is real and must survive — `Chat\Notifier::markMentionNotificationsRead()` marks every `chat` notification for that user in the room as processed. `room` is untouched by it. Also keep the AD-12 reasoning that the object id stays the bare token, with the thread id in subject parameters: a composed `{token}/{threadId}` would be read positionally by shipped clients as `{token}/{messageId}`.

## Review Triage Log

### 2026-08-12 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 11: (high 2, medium 4, low 5)
- defer: 3: (medium 3)
- reject: 4: (medium 2, low 2)
- addressed_findings:
  - `[high]` `[patch]` The new `thread` object type is discarded by the shipped Android client and unroutable on iOS, so the feature emitted nothing on mobile. Changed to `room`, which both clients handle and which `markMentionNotificationsRead()` does not touch.
  - `[high]` `[patch]` All four new Behat scenarios omitted the `invitation` notification that being added to a group room produces, and `assertNotifications()` asserts an exact count — every scenario would have failed. Rows added.
  - `[medium]` `[patch]` Sanitisation stripped only C0 controls, leaving U+0085, U+2028/U+2029 and the bidi overrides U+202A-U+202E and U+2066-U+2069 to reach a lock screen — line-break and text-direction spoofing in fully attacker-controlled text.
  - `[medium]` `[patch]` The Story 4.1 bounding logic was duplicated and had already drifted; it now calls the shared `shortenThreadText()`, so the hardening above applies to Thread Titles in chat notifications too.
  - `[medium]` `[patch]` A single failing recipient aborted the loop and skipped `flush()`, stranding every remaining follower and all deferred notifications. Now logged and skipped per recipient.
  - `[medium]` `[patch]` Both unrenderable-notification throw sites raised `UnknownNotificationException` without marking processed, so the row re-threw and logged on every fetch forever. Now `markProcessed()` then `AlreadyProcessedException`.
  - `[medium]` `[patch]` The full 4000-character lock reason was persisted once per follower while only 128 are ever rendered; bounded at emit time, which AD-14 permits since it governs provenance rather than storage.
  - `[low]` `[patch]` A Thread legitimately titled `0` rendered as its numeric id, because `?:` treats the string `"0"` as falsy.
  - `[low]` `[patch]` Deleted actors rendered a raw account id as though it were a person's name; now uses the codebase's `A deleted user ...` templates.
  - `[low]` `[patch]` Federated Thread Managers rendered as a bare `highlight` on a raw cloud id; now the established `type: user` shape with `server`.
  - `[low]` `[patch]` The non-sensitive push subject was a single over-long line with an empty body while the sensitive branch used the correct `{header}\n{body}` shape; both now match.
  - `[low]` `[patch]` Actor exclusion compared actor type and id after the loop had already filtered non-user attendees; now compares attendee ids.
  - Added the regression scenario the object-type decision rests on: a follower is notified, reads the conversation, and the lifecycle notification survives.
  - Rejected: notifying room-level `NOTIFY_ALWAYS` participants who never subscribed to the Thread (AC3 and AC5 define following as an explicit thread subscription); notifying federated followers (the contract is `ACTOR_USERS` only, and AD-18 proxies no new Thread field); clearing these notifications when the conversation is read (deliberate — AC9 exists precisely so a lock is not missed); and renaming `{call}` in one-to-one conversations (the shipped lifecycle system messages read the same way).

## Design Notes

**Why the emission point is the controller, not the service.** `ThreadService::changeState()` is reached by two callers: the endpoint, and `reviveIfClosed()` when someone posts into a Closed Thread. AC6 requires the second to stay silent. The controller is the only place where "the state changed *and* a system message was emitted" is already known — it is guarded by `$previousState !== $thread->getState()` — so binding the notification to that guard satisfies AC6 and AC8 by construction rather than by a second condition that could drift.

**Why a new `thread` object type.** `markMentionNotificationsRead()` (`lib/Chat/Notifier.php:412-425`) marks *every* notification with object type `chat` and the room token as processed for that user. A lock notification stored under `chat` would therefore be erased the moment the user read any mention in that conversation — directly undermining AC9. A composed `{token}/{threadId}` object id is not an option either: AD-12's identifier is positional and append-only, so a two-part value would be parsed by shipped clients as `{token}/{messageId}`. The thread id travels in subject parameters instead, and the object id stays the bare token.

**Followers are `NOTIFY_ALWAYS` only.** `findAttendeesForNotificationByThreadId()` already excludes `NOTIFY_DEFAULT`, and `notifyOtherParticipant()` skips thread attendees whose level is not `NOTIFY_ALWAYS`. Applying the same filter keeps one definition of "following a Thread" in the codebase. A participant holding a `NOTIFY_DEFAULT` row — the state a Thread's creator and repliers land in — has not opted into thread-scoped notifications, and inventing a second, looser rule here is exactly what AC3 forbids.

## Verification

**Commands:**
- `composer run test:unit -- --filter NotifierTest` -- expected: all pass, including the new lifecycle parser and recipient cases.
- `composer run psalm` -- expected: no new issues over the changed files.
- `composer run lint` -- expected: clean.
- `php -l` on each changed PHP file -- expected: `No syntax errors detected`.

**Manual checks (if no CLI):**
- The Behat suite needs a running server. If unavailable, confirm by inspection that the new scenarios assert one notification per transition, none for the muted and non-following participants, and none for the revival-by-reply case.
