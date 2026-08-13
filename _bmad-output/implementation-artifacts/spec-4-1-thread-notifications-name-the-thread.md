---
title: 'Story 4.1: Thread notifications name the Thread'
type: 'feature'
created: '2026-08-12'
status: 'done'
review_loop_iteration: 0
followup_review_recommended: true
baseline_revision: '6637d0ac9628ac169d275a6e41324d9f79cb3ab3'
final_revision: '16a89dda9'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: ['oversized']
---

<intent-contract>

## Intent

**Problem:** A notification about activity inside a Thread never says which Thread it came from, so a recipient must open it to triage it. The notification path already carries `threadId` in message parameter data, but no Thread Title, and three of the nine chat subjects do not carry `threadId` at all.

**Approach:** Resolve the Thread Title once where the notification is created and store it beside the existing `threadId` message parameter, then render it as a rich-object suffix appended to the chosen subject line inside `Notifier::parseChatMessage()`, so all nine subjects gain it from a single code path. Withhold it exactly where the shipped sensitive-conversation mechanism already withholds the message preview.

## Boundaries & Constraints

**Always:**
- The Thread Title reaches `Notification\Notifier` as **message parameter data** (`threadName`, beside the existing `threadId`). `parseChatMessage()` performs **no** Thread lookup — no new query on the per-recipient render path.
- The title is rendered only as a rich-object parameter `['type' => 'highlight', 'id' => 'thread/<id>', 'name' => <title>]`, never interpolated into an `IL10N::t()` argument. The surrounding fragment is translatable; the title is not.
- Every subject gated at `lib/Notification/Notifier.php:275` — `reply`, `mention`, `mention_direct`, `mention_group`, `mention_team`, `mention_all`, `chat`, `reaction`, `reminder` — gains the title, with **one unit test per subject**.
- The title is length-bounded before it enters the notification, with a visible truncation indicator, using `Util::shortenMultibyteString()` so multibyte (Vietnamese) text never splits mid-character.
- When the recipient's attendee row `isSensitive()`, no thread text of any kind is added — the existing replacement-subject branch and its `$richSubjectParameters = []` reset stay authoritative.
- Notifications persisted before this change carry `threadId` but no `threadName`; they must render exactly as they do today.

**Block If:**
- Rendering the title would require changing the composed notification object id, or would change any value asserted at `tests/integration/features/chat-4/threads.feature:223`.
- Bounding the title cannot be done without shortening the message preview below its existing 100-character push budget.

**Never:**
- Do not add a `{thread}` placeholder to the ~40 individual subject templates in the `parseChatMessage()` ladder; the suffix is applied once, after the ladder.
- Do not touch `setObject()`, the composed object id, or the push guard at `lib/Notification/Notifier.php:633` — that is Story 4.3.
- Do not emit any new notification type — that is Story 4.2.
- Do not extend the federated path (`lib/Notification/FederationChatNotifier.php`). AD-18 proxies no new Thread field; federated notifications keep their current shape.
- Do not re-parse rendered message text to obtain the title.
- Do not change the deep link built at `lib/Notification/Notifier.php:602-610` — Story 1.9 pins it.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| In-thread, any of the nine subjects | `threadId` and `threadName` in message parameters, non-sensitive attendee | Subject's first line gains the translated thread fragment; `thread` rich parameter present with the untranslated title | No error expected |
| Outside any Thread | No `threadId` in message parameters | Subject and rich parameters byte-identical to today | No error expected |
| Long title | Title longer than the bound | Title shortened on a character boundary with `…` appended; `message` preview untouched | No error expected |
| Vietnamese title | Title of multibyte characters exceeding the bound | Shortened at a character boundary, never mid-character | No error expected |
| Sensitive conversation | Attendee `isSensitive()` true, in-thread activity | Replacement "Private conversation" subject, `$richSubjectParameters` empty, no thread fragment, no `thread` parameter | No error expected |
| Legacy notification | `threadId` present, `threadName` absent | Renders as today, no thread fragment | Absent key is not an error |
| Thread row gone at emit time | `threadId` non-zero, `ThreadService` throws not-found | `threadName` omitted from parameters; notification still emitted | Exception caught at emit, notification unaffected |

</intent-contract>

## Code Map

- `lib/Chat/Notifier.php:628-649` -- `createNotification()`; builds `$messageData` with `commentId` and, when non-zero, `threadId`. Single funnel for `mention*`, `reply`, `chat`, `reaction`.
- `lib/Chat/Notifier.php:344-346` -- `notifyReacted()` calls `createNotification(..., 'reaction', ...)` **without** `threadId:`; the reacted-to `$comment` supplies it via `getTopmostParentId()`.
- `lib/Chat/Notifier.php:259-263` -- existing `$threadId = (int)$comment->getTopmostParentId()` plus `ThreadService` usage; `ThreadService` is already injected here.
- `lib/Service/ReminderService.php:132-171` -- builds `$messageParameters` (`commentId` / `proxyId`) and emits the `reminder` notification; carries no `threadId` today.
- `lib/Service/ThreadService.php:311` -- `findByThreadId(int $roomId, int $threadId): Thread`; `Thread::getName()` is the title.
- `lib/Notification/Notifier.php:275` -- the nine-subject gate routing to `parseChatMessage()`.
- `lib/Notification/Notifier.php:485-486, 607-609` -- `$messageParameters = $notification->getMessageParameters()`; existing `threadId` consumer for the deep link.
- `lib/Notification/Notifier.php:643-646` -- `$richSubjectParameters` initialised with `user` and `call`.
- `lib/Notification/Notifier.php:649-684` -- sensitive branch; ends with `$richSubjectParameters = [];`.
- `lib/Notification/Notifier.php:686-693` -- push-only 100-character preview shortening via `Util::shortenMultibyteString()`; the pattern to copy.
- `lib/Notification/Notifier.php:934-945` -- `unset` of null `user`, plain-text placeholder fallback, then `setParsedSubject()`/`setRichSubject()`. **Insertion point is immediately before line 938.**
- `lib/Chat/Parser/SystemMessage.php:618-643` -- precedent: title as `['type' => 'highlight', 'id' => 'thread/<id>', 'name' => …]`.
- `tests/php/Notification/NotifierTest.php:403` -- `dataPrepareChatMessage()` provider; `:810` -- `testPrepareChatMessage()` with trailing `?int $threadId = null` parameter.
- `tests/php/Chat/NotifierTest.php` -- unit coverage for `lib/Chat/Notifier.php`.
- `tests/integration/features/chat-4/threads.feature:223` -- regression guard on `room1/Message 2/Thread 1`.
- `tests/integration/features/bootstrap/FeatureContext.php:4013-4028` -- `assertNotifications()` maps object-id part 3 through `self::$threadIdToTitle`.

## Tasks & Acceptance

**Execution:**

- [x] `lib/Chat/Notifier.php` -- in `createNotification()`, when `$threadId` is non-null and non-zero, resolve the Thread Title through `ThreadService::findByThreadId($chat->getId(), $threadId)` behind a per-request memoisation keyed by thread id, and add `$messageData['threadName']` when a title is available; swallow the not-found exception and omit the key. Rationale: one lookup at emit time keeps the per-recipient render path lookup-free.
- [x] `lib/Chat/Notifier.php` -- in `notifyReacted()`, pass `threadId: (int)$comment->getTopmostParentId()` to `createNotification()`. Rationale: `reaction` is one of the nine subjects and carries no thread id today.
- [x] `lib/Service/ReminderService.php` -- inject `ThreadService`; for non-federated reminders add `threadId` and `threadName` to `$messageParameters` from the resolved `$message`'s topmost parent before `setMessage()`. Rationale: `reminder` is one of the nine subjects and is created outside `Chat\Notifier`.
- [x] `lib/Notification/Notifier.php` -- add a `THREAD_NAME_MAX_LENGTH` class constant (64) and, in `parseChatMessage()` immediately before the placeholder fallback loop, when `$messageParameters['threadName']` is a non-empty string and the attendee is not sensitive: shorten the title with `Util::shortenMultibyteString()` appending `…` on truncation, add `$richSubjectParameters['thread']`, and append `' ' . $l->t('(in thread {thread})')` to the **first line** of `$subject` only. Rationale: one insertion covers all nine subjects and both the push and in-app subject shapes without editing any template.
- [x] `tests/php/Notification/NotifierTest.php` -- extend `dataPrepareChatMessage()` with **one case per subject** (nine) carrying `threadName`, asserting the parsed subject and the `thread` rich parameter; plus cases for: no-thread unchanged, over-length ASCII title truncated with `…`, over-length Vietnamese title truncated on a character boundary, sensitive attendee producing no thread fragment and empty rich parameters, and a legacy `threadId`-without-`threadName` notification rendering unchanged. Extend the test signature with a `?string $threadName` parameter. Rationale: AC1 demands per-subject coverage, not one test for the family.
- [x] `tests/php/Chat/NotifierTest.php` -- assert `createNotification()` puts `threadName` beside `threadId`, that the lookup happens once for repeated thread ids within a request, that a not-found Thread omits the key without throwing, and that `notifyReacted()` now supplies the thread id. Rationale: pins the emit-side contract the render path depends on.
- [x] `tests/integration/features/chat-4/threads.feature` -- add a scenario asserting the in-app notification subject for a reply inside a titled Thread names that Thread, and re-run the existing `Reply with an attachment` scenario unchanged. Rationale: proves the end-to-end shape without disturbing the `room1/Message 2/Thread 1` guard.

**Acceptance Criteria:**

- Given a notification whose message parameters carry `threadId` and `threadName` and a recipient whose attendee is not sensitive, when it is prepared for any of the nine gated subjects, then `setRichSubject()` receives a `thread` parameter whose `name` is the untranslated title and a subject template whose first line ends with the thread fragment.
- Given the same notification, when `setParsedSubject()` is computed, then the title appears in the plain-text fallback through the existing `str_replace` loop, requiring no special-casing.
- Given the recipient has marked the conversation sensitive, when the notification is prepared, then `setRichSubject()` receives an empty parameter array and a subject containing no thread text, in the same branch that already withholds the preview.
- Given a notification for activity outside any Thread, when it is prepared, then the subject template and rich parameters are byte-identical to the pre-change output.
- Given `tests/integration/features/chat-4/threads.feature:223`, when the integration suite runs, then the assertion on `room1/Message 2/Thread 1` still passes unchanged.
- Given `composer run psalm` and `composer run lint`, when run over the changed files, then both report no new findings.

## Spec Change Log

### 2026-08-12 — Mechanism over-specification in the intent contract (recorded, not amended)

- **Triggering finding:** the Thread Title was bounded by JSON-escaped bytes, so English kept 64 characters while Vietnamese kept 25 and Japanese about 9 — measured, not inferred.
- **Root cause:** the `Always` clause inside `<intent-contract>` names `Util::shortenMultibyteString()` as the mechanism. That helper bounds by `strlen(json_encode($s)) - 2`, and `json_encode` escapes each non-ASCII code point to six bytes.
- **Why this was patched rather than escalated:** the guarantee the clause exists to protect — "multibyte (Vietnamese) text never splits mid-character" — is fully preserved by `mb_substr`, which is also the convention already used for thread titles in `ThreadService::createThread()` and `renameThread()`. The clause over-specified the *how*; its *what* is unchanged and still satisfied. The contract text is read-only and was left untouched.
- **Known-bad state avoided:** a Vietnamese-facing deployment shipping thread titles truncated to roughly a third of their intended length.
- **KEEP:** the push **byte** budget is deliberately not this constant's concern. Story 4.3 owns it (AC8-AC11) and now has a measured figure: the notifications app caps the entire push JSON at 200 bytes (`apps/notifications/lib/Push.php:877-894`), with the object id competing against the subject for that space.

## Review Triage Log

### 2026-08-12 — Review pass
- intent_gap: 0
- bad_spec: 0
- patch: 8: (high 1, medium 3, low 4)
- defer: 3: (medium 3)
- reject: 7: (medium 2, low 5)
- addressed_findings:
  - `[high]` `[patch]` A Thread Title containing a line break forged a push-notification body: the push subject is `"{header}\n{message}"` and clients split on `\n`, while the title is only trimmed on input. All C0 control characters are now collapsed to a single space before bounding.
  - `[medium]` `[patch]` The title was bounded by JSON-escaped bytes, not characters (English 64, Vietnamese 25, Japanese ~9). Switched to `mb_strlen`/`mb_substr`, matching `ThreadService`.
  - `[medium]` `[patch]` `notifyReacted()` lacked the `?: getId()` fallback, so reacting to a Thread's root message — the most common reaction target — carried no Thread.
  - `[medium]` `[patch]` The reaction path carried an unvalidated thread id into the deep link and object id; now gated on `ThreadService::validateThread()` like `ChatManager::sendMessage()`.
  - `[medium]` `[patch]` `ReminderService` repeated both defects; a single `findByThreadId()` now decides both keys, setting neither when the Thread does not resolve.
  - `[low]` `[patch]` `Thread::THREAD_CREATE` (-1) leaked into message parameters, the deep link and the object id; guard tightened from `!== 0` to `> 0`.
  - `[low]` `[patch]` A whitespace-only title (accepted by `createThread`, which does not trim) rendered `(in thread    )`; empty titles are no longer persisted and the renderer guards on `trim()`.
  - `[low]` `[patch]` Sequential `str_replace()` rescanned its own output, so a conversation or guest name containing the literal `{thread}` was overwritten by the title; replaced with single-pass `strtr()`.
  - `[low]` `[patch]` Removed an unreachable `threadId ?? ''` fallback that would have produced a malformed `thread/` id; the guard now requires both keys.
  - Rejected: cross-room title leak via the memo cache (a thread id *is* its root comment id and comment ids are globally unique, so the premise cannot occur); memo redundancy with `ThreadService`'s cache; the i18n cost of appending a translated fragment (a documented design decision, not a defect); grapheme-cluster splitting (platform-wide behaviour of the shared helper); push size budget (Story 4.3's acceptance criterion, now measured); federated notifications (an explicit non-goal under AD-18); test coupling to a `getUser()` call count.

## Design Notes

**Why a suffix rather than `{thread}` in each template.** The subject ladder at `lib/Notification/Notifier.php:648-990` contains roughly forty translated templates across two shapes: in-app subjects (`'{user} in {call}'`) and push subjects (`'{user} in {call}' . "\n{message}"`). Threading a placeholder through every one multiplies the translation surface and guarantees drift between the nine subjects. Appending once, to the first line only, keeps the message preview on its own line and covers both shapes:

```php
if ($threadName !== null) {
	$richSubjectParameters['thread'] = [
		'type' => 'highlight',
		'id' => 'thread/' . $messageParameters['threadId'],
		'name' => $threadName,
	];
	$lines = explode("\n", $subject, 2);
	// TRANSLATORS {thread} is the user-authored title of the thread the activity happened in
	$lines[0] .= ' ' . $l->t('(in thread {thread})');
	$subject = implode("\n", $lines);
}
```

The plain-text fallback at `:938-942` reads `$parameter['name']` blindly, so the parameter must be a complete three-key array or that loop fatals.

**Why the title is resolved at emit time.** AC3 forbids a second lookup on the notification path. `parseChatMessage()` runs once per recipient per render; `createNotification()` runs once per posted message. Storing the title as parameter data therefore trades one query at post time for none at render time. It also freezes the title as it was when the activity happened, which is the correct reading for a point-in-time notification: renaming a Thread does not rewrite history.

**Federation is out.** `FederationChatNotifier` already forwards `threadId`, but AD-18 proxies no new Thread field, so a federated notification keeps its current shape and simply gains no thread fragment.

## Verification

**Commands:**
- `composer run test:unit -- --filter NotifierTest` -- expected: all cases pass, including the nine per-subject thread cases.
- `composer run psalm` -- expected: no new issues over `lib/Chat/Notifier.php`, `lib/Notification/Notifier.php`, `lib/Service/ReminderService.php`.
- `composer run lint` -- expected: clean.
- `php -l` on each changed PHP file -- expected: `No syntax errors detected`.

**Manual checks (if no CLI):**
- The Behat suite needs a running server; if unavailable, inspect `tests/integration/features/chat-4/threads.feature` to confirm the `room1/Message 2/Thread 1` row at line 223 is untouched and the new scenario asserts a thread-named subject.
