---
title: 'Story 4.3: Push payloads carry the same identifiers as in-app notifications, and fit'
type: 'feature'
created: '2026-08-12'
status: 'ready-for-dev'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
warnings: ['oversized']
---

<intent-contract>

## Intent

**Problem:** One guard skips **both** `setObject()` calls whenever the payload being built is a push payload, so a push object id is the bare room token — no message identifier and no thread identifier. A mobile client must therefore fetch the notification over the API to learn where to route, and when that fetch fails it falls back to the main chat. The message preview is separately truncated to a fixed 100 characters, a number unrelated to the real payload limit.

**Approach:** Separate the two concerns the guard currently conflates. Message *content* stays withheld from push payloads and from sensitive conversations; routing *identifiers* travel in both, because an identifier names a destination without disclosing what is in it. Then replace the fixed preview truncation, on the push path only, with a budget computed from the platform's actual encryption limit, shortening the preview rather than the Thread Title.

## Boundaries & Constraints

**Always:**
- A push payload for in-thread activity carries **both** the message identifier and the thread identifier, in the same composed form the in-app notification uses, so a client parses one shape.
- A push payload for activity outside a Thread carries the message identifier.
- The composed identifier is append-only and absence-significant: a trailing position may be appended, never reordered or shortened; a position is either present with a real value or absent entirely — never padded, never a sentinel, and `0` is never valid at any position.
- Thread Titles and lock reasons never enter a push payload for a conversation the recipient marked sensitive; identifiers still do.
- The push size budget is derived from the platform notifications app's **actual** limit, never from the existing 100-character preview truncation.
- When the title and the preview cannot both fit, the **Thread Title is preserved and the preview is shortened**.
- Truncation falls on character boundaries valid for Vietnamese, never mid-character.
- The in-app composed identifier is unchanged.

**Block If:**
- Making identifiers travel on push would also make message content travel.
- The measured budget cannot accommodate a composed identifier plus a minimally useful subject, so the change would make push notifications empty rather than merely shorter.

**Never:**
- Do not claim or assume any on-device verification. The push proxy's APNs/FCM hop is unimplemented and blocked on an Apple `.p8` key and a Firebase project; acceptance is at payload construction only.
- Do not change the value asserted at `tests/integration/features/chat-4/threads.feature:223` — `room1/Message 2/Thread 1` is the in-app regression guard.
- Do not alter the deep link built for the notification; Story 1.9 pins navigation.
- Do not change how the Thread Title itself is bounded — Story 4.1 owns that, and it is a character bound.
- Do not modify the notifications app or assume it can be changed; it is not part of this repository.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Push, in-thread | Push payload, `threadId` present | Object id is `{token}/{messageId}/{threadId}` | No error expected |
| Push, outside a Thread | Push payload, no `threadId` | Object id is `{token}/{messageId}` — two positions, not padded | No error expected |
| Push, sensitive | Attendee `isSensitive()`, in-thread | Object id carries both identifiers; subject names neither Thread nor reason; no message preview | No error expected |
| In-app, in-thread | Not a push payload | Object id unchanged from today | No error expected |
| Oversized subject | Max-length title and max-length preview | Whole payload fits the measured budget; the preview is what was shortened | No error expected |
| Vietnamese preview | Multibyte preview exceeding the budget | Shortened on a character boundary with an ellipsis | No error expected |
| Budget already exhausted | Composed id and header alone consume the budget | Preview reduced to nothing rather than the title being cut | Never produces a negative length |

</intent-contract>

## Code Map

- `lib/Notification/Notifier.php:648` (at commit `16a89dda9`) -- `if (!$this->notificationManager->isPreparingPushNotification() && !$participant->getAttendee()->isSensitive()) {` — the guard that wrongly covers the identifiers as well as the content.
- `lib/Notification/Notifier.php:649-650` -- `setParsedMessage()` / `setRichMessage()`; these are content and **stay** behind the guard.
- `lib/Notification/Notifier.php:653` -- appends `/{messageId}`; must run for push and for sensitive.
- `lib/Notification/Notifier.php:654-656` -- appends `/{threadId}` when present; same.
- `lib/Notification/Notifier.php` (push branch of the subject ladder) -- the fixed `Util::shortenMultibyteString($parsedMessage, 100)` and its `'message'` rich parameter; this is what the measured budget replaces.
- `lib/Notification/Notifier.php` (end of `parseChatMessage()`) -- `strtr()` fallback then `setParsedSubject()`/`setRichSubject()`; the final assembled subject is the right place to enforce the budget.
- `lib/Chat/Notifier.php::createNotification()` -- already guarantees `threadId > 0`, so no sentinel can reach a position (added by Story 4.1).
- `tests/php/Notification/NotifierTest.php` -- `dataPrepareChatMessage()` already carries an `$isPushNotification` flag and thread cases.
- `tests/integration/features/chat-4/threads.feature:223` -- the in-app regression guard.

**Measured platform limit (verified in the running container, not inferred):**
- `apps/notifications/lib/Push.php:960-972` -- `encryptAndSign()` calls `encodeNotif($id, $notification, 200)`, then `openssl_public_encrypt()` with PKCS1 padding (RSA-2048 tops out at 245 plaintext bytes, hence the 200).
- `apps/notifications/lib/Push.php:877-894` -- `encodeNotif()` builds `['nid', 'app', 'subject' => '', 'type', 'id']`, computes `$maxDataLength = $maxLength - strlen(json_encode($data)) - 2`, and shortens the **parsed subject** to it.
- `apps/notifications/lib/Push.php:364` then `:464` -- `prepare()` runs before encoding, so the object id spreed composes is inside that 200-byte JSON.
- Consequence: **every byte added to the object id is taken directly out of the subject budget.** A bare token leaves roughly 130 bytes of subject; appending `/{messageId}/{threadId}` reduces that to roughly 118.
- The platform shortens from the **end**, so whatever must survive belongs on the first line of the parsed subject. Story 4.1 puts the thread fragment there.

## Tasks & Acceptance

**Execution:**

- [ ] `lib/Notification/Notifier.php` -- split the guard: keep `setParsedMessage()` and `setRichMessage()` behind `!isPreparingPushNotification() && !isSensitive()`, and move both `setObject()` calls out so they always run. Add a comment stating that identifiers are routing data, not content (AD-20). Rationale: AC1, AC2, AC6 — the whole defect in one edit.
- [ ] `lib/Notification/Notifier.php` -- add a `PUSH_PAYLOAD_MAX_LENGTH = 200` constant documenting the measured origin (`Push::encodeNotif`), plus a private helper that computes the subject budget as `PUSH_PAYLOAD_MAX_LENGTH - strlen(json_encode(<scaffold>)) - 2`, where the scaffold mirrors the platform's `['nid', 'app', 'subject' => '', 'type', 'id']` using the notification's real object type and **composed** object id and a conservatively wide `nid`. Rationale: AC8 demands a measured budget, and the object id is now part of it.
- [ ] `lib/Notification/Notifier.php` -- on the push path only, after the subject is fully assembled and before `setParsedSubject()`, measure the parsed subject against that budget; if it overflows, shorten **only** the `message` rich parameter (never the `thread` parameter) and rebuild the parsed subject. Retain the existing 100-character cap as an upper bound so no preview grows. Reuse `Util::shortenMultibyteString()` here — its JSON-escaped-byte measure is exactly right for a byte budget, and it cuts on character boundaries. Rationale: AC9, AC10, AC11.
- [ ] `tests/php/Notification/NotifierTest.php` -- assert: push in-thread yields `{token}/{messageId}/{threadId}`; push outside a Thread yields `{token}/{messageId}`; push in a sensitive conversation yields the same identifiers with no title, no reason and no preview; in-app object ids are unchanged; a max-length title plus max-length preview produces a JSON payload within 200 bytes when encoded the way the platform encodes it; and a Vietnamese preview is cut on a character boundary. Rationale: acceptance is at payload construction, so these tests *are* the acceptance.
- [ ] `tests/integration/features/chat-4/threads.feature` -- confirm by inspection that line 223's `room1/Message 2/Thread 1` is untouched, and add no push scenario: the Behat suite exercises the in-app API only. Rationale: AC3, and AC12's prohibition on implying device coverage.

**Acceptance Criteria:**

- Given a push payload for activity inside a Thread, when it is constructed, then its object id has three positions and matches the in-app value for the same activity.
- Given a push payload for activity outside a Thread, when it is constructed, then its object id has exactly two positions, with no padding and no sentinel.
- Given a recipient who marked the conversation sensitive, when a push payload is constructed for in-thread activity, then it carries both identifiers and discloses neither the Thread Title nor any lock reason.
- Given a Thread with a maximum-length title and a maximum-length preview, when the payload is encoded as `Push::encodeNotif()` encodes it, then the result is at most 200 bytes and the Thread Title is intact while the preview is the part that was shortened.
- Given the in-app path, when notifications are prepared, then every composed object id is byte-identical to the pre-change output.
- Given `composer run psalm` and `composer run lint`, when run over the changed files, then both report no new findings.

## Spec Change Log

## Review Triage Log

## Design Notes

**Why the guard is wrong rather than merely incomplete.** The two `setObject()` calls accumulate: the first rewrites the object id from `{token}` to `{token}/{messageId}`, and the second reads that result and appends `/{threadId}`. Both sit inside a guard whose purpose is to withhold *message content*. Identifiers were swept up by proximity, not by intent. AD-20 draws the line explicitly: routing identifiers name a destination without disclosing what is in it, so they travel where a title or a reason may not.

**Why the budget must be computed, not constant.** The platform's budget is `200 - strlen(json_encode(scaffold)) - 2`, and the scaffold contains the object id. This story lengthens that id, so a fixed preview cap cannot be correct before and after the change. Computing the budget from the same scaffold the platform builds is the only way to make AC9 a statement about the real limit rather than about a number someone chose.

**Why the Thread Title survives.** The platform shortens the parsed subject from the end. Story 4.1 appends the thread fragment to the *first* line, and the preview occupies the second, so ordinary platform truncation already eats the preview first. Pre-shortening the preview to the computed budget turns that from a lucky ordering into a guarantee, and means the payload arrives intact rather than merely arriving.

**What acceptance does not cover.** The Clavis push proxy's APNs/FCM hop is unimplemented and blocked on an Apple `.p8` key and a Firebase project. Nothing here is verified on a device, and nothing downstream may assume a device test exists.

## Verification

**Commands:**
- `composer run test:unit -- --filter NotifierTest` -- expected: all pass, including the new push identifier and budget cases.
- `composer run psalm` -- expected: no new issues over the changed files.
- `composer run lint` -- expected: clean.
- `php -l` on each changed PHP file -- expected: `No syntax errors detected`.

**Manual checks (if no CLI):**
- Confirm `tests/integration/features/chat-4/threads.feature:223` still reads `room1/Message 2/Thread 1`.
