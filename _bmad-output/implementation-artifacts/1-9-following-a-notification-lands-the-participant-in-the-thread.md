---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.9: Following a notification lands the participant in the Thread

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As someone tapping a notification,
I want to arrive in the Thread the notification was about,
So that acting on a notification does not mean hunting for what it referred to.

## Acceptance Criteria

**AC1**
**Given** a notification about a message inside a Thread
**When** the recipient follows it on web or desktop
**Then** the Conversation opens with the Thread open and the message in view
**And** this behaviour, which already works because the link is set outside the push guard, is now covered by a test. *(FR-36)*

**AC2**
**Given** a notification about a message outside any Thread
**When** the recipient follows it
**Then** the Conversation's main chat opens as it does today.

**AC3**
**Given** a notification about a message in a Thread that Story 1.4 has since Locked
**When** the recipient follows it
**Then** the Thread opens, the state Story 1.8 renders is shown, and the composer is disabled — rather than the navigation failing, or landing the participant on a composer that accepts text Story 1.6 will refuse.

**AC4**
**Given** a notification about a Thread the server no longer returns
**When** the recipient follows it
**Then** they land in the Conversation's main chat with an explanation, not an error page
**And** this holds for **any** absent Thread id, so the criterion is exercised without waiting for Story 1.10's reaper to have run.

**AC5**
**Given** notification navigation already works through the existing `/call/<token>?threadId=…` query scheme
**When** this story adds its coverage
**Then** it introduces **no new named route and no second routing mechanism** — it reads the thread id through the existing `useGetThreadId` composable rather than adding a route of its own. *(AD-16)*

## Tasks / Subtasks

- [x] Task 1: Regression-test the already-working link (AC1, AC2, AC5)
  - [x] 1.1 Extend `tests/php/Notification/NotifierTest.php::testPrepareChatMessage()` / `dataPrepareChatMessage()` to capture the arguments passed to `IURLGenerator::linkToRouteAbsolute()` (2 calls per case: `prepare()`'s generic link, then `parseChatMessage()`'s message-specific one, identified by its `_fragment` key) and assert the message-specific link carries `threadId` when `messageParameters['threadId']` is set, and omits it otherwise.
  - [x] 1.2 Add one new data-provider case with `$isPushNotification = true` and a `threadId` set, asserting the link still carries `threadId` — the direct regression proof for AC1's "link is set outside the push guard" claim (`isPreparingPushNotification()` only gates `setParsedMessage`/`setRichMessage`, never the `setLink()` call at `lib/Notification/Notifier.php:598-608`).
  - [x] 1.3 Add one new data-provider case (non-push, `subject = 'mention'`) with `threadId` set, for AC1's plain in-app case.
  - [x] 1.4 Confirm (no new test needed — regression net already exists across all ~30 pre-existing rows once 1.1's assertion is added) that every case *without* an explicit `threadId` continues to produce a link with no `threadId` key — this is AC2's server-side half.
  - [x] 1.5 `php -l` and `psalm --no-cache` the touched test file via the Docker container (read-only mount; edit on host).
- [x] Task 2: Disable the composer in a Locked Thread (AC3 — real gap, not pre-existing)
  - [x] 2.1 Add `isThreadLocked(threadInfo: ThreadInfo | undefined): boolean` to `src/utils/threadState.ts` (pure function, mirrors the file's existing `getThreadStateActor`/`getThreadStateSummary` precedent).
  - [x] 2.2 Unit-test it in `src/utils/__tests__/threadState.spec.js` (extend the existing `describe('threadState', ...)` block): undefined input, Ongoing, Closed, Locked.
  - [x] 2.3 In `src/components/NewMessage/NewMessage.vue`: add a computed that calls the new helper with `chatExtrasStore.getThread(token, threadId)`, and fold it into the existing `disabled` computed (single seam, same pattern as `isReadOnly`/`noChatPermission`/`isRecordingAudio`) so every control already gated by `disabled` in the template (send button, attachments, poll, audio recorder, thread-title field) is covered without per-control changes.
  - [x] 2.4 Add a `placeholderText` branch (`t('spreed', 'This thread has been locked')`), positioned after the existing `isReadOnly` branch, mirroring that branch's exact pattern.
  - [x] 2.5 Verify via `npx eslint`, `npm run ts:check`, and careful manual template/script review (no new component-mount test infra — see Testing standards below) that `disabled` now reads correctly for: main chat (threadId=0, always false regardless of any Thread's state), an open Ongoing/Closed Thread (false), an open Locked Thread (true).
- [x] Task 3: Land in the main chat, with an explanation, when the Thread is gone (AC4 — real gap, not pre-existing)
  - [x] 3.1 Add `isThreadNotFoundError(exception: unknown): boolean` to `src/types/guards.ts`, alongside `isAxiosErrorResponse` — checks `isAxiosErrorResponse<{ error?: string }>(exception) && exception.response?.status === 404 && exception.response?.data?.ocs?.data?.error === 'thread'`, matching the exact shape both `ChatController::getMessageContext()` and `::receiveMessages()` already return (`DataResponse(['error' => 'thread'], Http::STATUS_NOT_FOUND)` — server side needs no change, this identifier already exists).
  - [x] 3.2 Unit-test the new predicate in a new `src/types/__tests__/guards.spec.ts` (no prior test file for `guards.ts` — first coverage for this file): matches the exact shape, and rejects a 404 with a different `error` value, a non-404 status, a cancelled request, and a non-axios exception.
  - [x] 3.3 In `src/composables/useGetMessages.ts::getMessageContext()`, in the existing `catch` block (currently handles only `isCancel` and the 304 case — any other exception, including this one, is silently swallowed today): when `threadId !== 0` (the function's own parameter, not the reactive ref — guards against the one-shot retry re-triggering) and `isThreadNotFoundError(exception)`, call `showError(t('spreed', 'This thread no longer exists'))`, set `contextThreadId.value = 0` (drops the `threadId` query param via `useGetThreadId`'s existing set-transform — no manual URL/route manipulation), and retry once with `await getMessageContext(token, messageId, 0)` so the participant lands in the main chat at (or near) the same message rather than on a blank pane. Import `showError` from `@nextcloud/dialogs` (new import) and `isThreadNotFoundError` from `../types/guards.ts`.
  - [x] 3.4 Confirm by reading `ThreadHeader.vue`'s existing `watch(currentThread, ...)` that once `contextThreadId.value` is reset to 0 (the shared `useGetThreadId()` ref), the header's own `v-if="isSidebar && threadId"` (`ChatView.vue`) / `v-else-if="!isInCall && threadId"` (`TopBar.vue`) guards make it disappear on the same tick — no separate fix needed there, and its own (pre-existing, unrelated, out-of-scope) `fetchSingleThread()` 404 just console-errors harmlessly if it fires first.
  - [x] 3.5 `npm run ts:check` and `npx eslint` on every touched TS file.
- [x] Task 4: Confirm no new routing mechanism was introduced (AC5)
  - [x] 4.1 `git diff` / `git status` review: confirm no new route was added to `src/router/router.ts` or either memory-router factory, and that every AC1-AC4 change reads the thread id exclusively through the existing `useGetThreadId()` composable.
- [x] Task 5: Full-project verification
  - [x] 5.1 `npm run lint` (full project).
  - [x] 5.2 `npm run ts:check` (full project).
  - [x] 5.3 `npx vitest run` (full suite) — compare against Story 1.8's last known baseline (1441 tests, 1438 passed, 3 pre-existing `messagesStore.spec.js` timeout-flake failures) to confirm no new regressions; report the real new totals.
  - [x] 5.4 `php -l` and `vendor/bin/psalm --no-cache` (Docker container) on every touched PHP file.
  - [x] 5.5 Update this story's Dev Agent Record and Change Log; set Status to `review`.

## Dev Notes

### Why this story is a mix of test-hardening and two real gaps — read before starting

The epics.md migration note that flagged this as "a test-coverage story for already-working behaviour" is only correct for **AC1, AC2 and AC5**. Tracing the actual code (not assumed) found two genuine, unimplemented gaps in **AC3** and **AC4** — both are new production code, not just tests. Do not skip Tasks 2/3 on the assumption that everything here is already built.

### AC1/AC2/AC5 — confirmed already working, by tracing the real path

- **Server:** `lib/Notification/Notifier.php`, method `parseChatMessage()` (not `createNotification()` — that only stashes `threadId` into `$messageData` for the *object id*, a separate, already-correct mechanism at lines 636-640). The link is built at lines 598-608:
  ```php
  $urlParams = [
      'token' => $room->getToken(),
      '_fragment' => 'message_' . $message->getMessageId(),
  ];
  if (isset($messageParameters['threadId'])) {
      $urlParams['threadId'] = $messageParameters['threadId'];
  }
  $notification->setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', $urlParams));
  ```
  This runs **before** the `isPreparingPushNotification()` branch that gates `setParsedMessage`/`setRichMessage`/`setPriorityNotification` for content-privacy reasons (sensitive conversations, push payload size) — confirming the epics.md claim verbatim: "the link is set outside the push guard." `prepare()` (the public entry point, line ~239) also sets a *generic* link once (`setLink($this->url->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $room->getToken()]))`, no `threadId`, no fragment) before dispatching to `parseChatMessage()` — this is why the existing test mocks (`tests/php/Notification/NotifierTest.php`) already assert `setLink` is called `exactly(2)` times; the *second* call is the one Task 1 must inspect.
- **Client:** `src/composables/useGetThreadId.ts` reads the `threadId` query param directly (`useRouteQuery<..., number>('threadId', '0', {...})`, `createSharedComposable` — one shared instance app-wide). `ChatView.vue` (`v-if="isSidebar && threadId"`) and `TopBar.vue` (`v-else-if="!isInCall && threadId"`) render `ThreadHeader` whenever it is non-zero. `MessagesList.vue`/`src/composables/useGetMessages.ts` pass `threadId` through every message-fetch call (`getMessageContext`, `getOldMessages`, `getNewMessages`) to scope the list to the Thread, and `getMessageIdFromHash()`/`focusMessage()` (`MessagesList.vue`) scroll to and highlight `#message_<id>` from the URL fragment the same link sets. No client code change is needed for AC1/AC2 — only test coverage (Task 1, server-side, is where the actual conditional logic lives and is the cheaper, more valuable place to pin it; the client side is a thin, already-exercised-by-construction consumer of a query param, not new conditional logic worth a new mount-test harness for this story).
- **AC5:** confirmed by the above — one composable (`useGetThreadId`), one query param, no new route. Nothing to add; Task 4 is a verification pass, not an implementation task.

### AC3 — composer disable is a real, missing piece

Story 1.8 (already `review`) renders Thread state everywhere (`ThreadHeader.vue`'s `.description--state` line, driven by `chatExtrasStore.getThread(token, threadId)?.thread.state`). Story 1.6's guard (also `review`) refuses server-side writes into a Locked Thread and requires the refusal to "fail visibly" (its AC11) — it does **not** require the composer to be pre-emptively disabled; that is new to this story's AC3.

Traced `src/components/NewMessage/NewMessage.vue`'s `disabled` computed — it has no Thread-awareness at all:
```js
disabled() {
    return this.isReadOnly || this.noChatPermission || !this.currentConversationIsJoined || this.isRecordingAudio
},
```
Grepped the whole component (and `ChatView.vue`, which mounts it with no thread-related prop) for `locked`/`LOCKED` — the only hit is the unrelated `isReadOnly` conversation-lock placeholder string ("This conversation has been locked"). **Today, a participant who opens a Locked Thread — whether by notification or by clicking into it manually — sees a fully enabled composer** and only discovers the refusal after the server rejects the post (Story 1.6's already-correct backstop). AC3 requires closing this specific gap: pre-emptive, visible disabling, not just a reactive server error.

`chatExtrasStore` is already injected into `NewMessage.vue` (`chatExtrasStore: useChatExtrasStore()` in `setup()`), and `threadId`/`token` are already available — no new store wiring needed, only a new computed and folding it into `disabled`. When `threadId` is 0 (main chat, not inside any Thread), `chatExtrasStore.getThread(token, 0)` is `undefined` by construction, so the new check is a no-op outside a Thread — exactly the intended scope.

### AC4 — thread-not-found handling is a real, missing piece

Traced both server 404 sources and every client consumer:
- **Server** (`lib/Controller/ChatController.php`): `getMessageContext()` (line ~1298) and `receiveMessages()` (line ~923) both already do `if ($threadId !== 0 && !$this->threadService->validateThread(...)) { return new DataResponse(['error' => 'thread'], Http::STATUS_NOT_FOUND); }`. **No server change needed** — the distinguishable identifier AC4 needs already exists (OCS-wrapped as `response.data.ocs.data.error === 'thread'` client-side, confirmed against the exact precedent at `src/components/CalendarEventsDialog.vue:355` — `isAxiosErrorResponse<{ error: string } | null>(error)` then `error.response?.data?.ocs?.data?.error`).
- **Client, currently silently swallows this:**
  - `src/composables/useGetMessages.ts::getMessageContext()` (the function invoked by both `handleStartGettingMessagesPreconditions()` on a cold page load and `checkContextAndFocusMessage()` on an in-app route change to a message not already in the store — i.e., **both** ways a notification link can be followed) — its `catch` block only branches on `isCancel(exception)` and `exception.response?.status === 304`. Every other exception, including a 404 `error: 'thread'`, falls through with no handling at all; `isInitialisingMessages`/`loadingOldMessages` just reset to `false`.
  - `src/stores/chatExtras.ts::fetchSingleThread()` (called by `ThreadHeader.vue`'s `watch(currentThread, ...)` whenever `threadId` is set but not yet loaded) — its `catch` only does `console.error('Error fetching thread:', error)`. `currentThread` stays `undefined` forever; nothing resets `threadId` or informs the participant.
  - Net effect today: following a notification to a deleted/never-existed Thread leaves the participant on a broken, effectively-empty conversation view — not an "error page" in the literal sense, but not "the Conversation's main chat with an explanation" either. Real gap.
- **Fix, scoped to `getMessageContext()` only** (Task 3.3) — this is the single choke point both entry paths already funnel through, so fixing it once covers cold-load and warm in-app navigation without touching `fetchSingleThread()`'s separate, pre-existing, harmless-when-it-loses-the-race console-error path (see Task 3.4). Resetting the shared `contextThreadId` ref to 0 makes `ThreadHeader` disappear on the same reactive tick regardless of which fetch noticed the 404 first — one fix, not two, matching AD-16's "no second routing mechanism" spirit even though AD-16 itself is about a different surface.
- **Deliberately out of scope:** a message that is *itself* gone (not just its thread association) is a separate, pre-existing, unrelated failure mode this story does not touch — AC4's own text scopes this to "any absent Thread id," exercisable "without waiting for Story 1.10's reaper," i.e. a threadId that was simply never valid, not a compound deleted-message scenario.

### Testing standards summary

- **PHP (Task 1):** extend `tests/php/Notification/NotifierTest.php::testPrepareChatMessage()` / `dataPrepareChatMessage()` — the existing, only relevant test method. Do not write a new test class; the fixture cost (room/participant/comment/message mocks) is already paid there. `$this->url` is `createMock(IURLGenerator::class)` with **no** stubbed return for `linkToRouteAbsolute()` today (existing tests only assert call *count*, never arguments) — add a `willReturnCallback()` capturing `$parameters` per call, keyed by whichever call includes a `_fragment` entry (that is always the message-specific one; the generic `prepare()` call never has one).
- **TS/Vue (Tasks 2, 3):** follow Story 1.8's repeatedly-confirmed, three-times-stated codebase precedent: pull risky/non-trivial logic into small pure functions (`threadState.ts`, `guards.ts`) and unit-test *those* with `vitest`; verify the Vue component wiring (`NewMessage.vue`'s computed/template, `useGetMessages.ts`'s catch-block side effects) via `npx eslint` + `npm run ts:check` + careful manual trace, not new component-mount or composable-mount test infrastructure — `useGetMessagesProvider()` in particular has `onBeforeUnmount`/event-bus/interval side effects that require a full component-host + router + Vuex + Pinia harness to mount at all, which would be new, disproportionate infrastructure for one error-handling branch. State this plainly in the Dev Agent Record rather than claiming a mount test that wasn't written.
- `src/utils/__tests__/threadState.spec.js` and (new) `src/types/__tests__/guards.spec.ts` are the two files carrying real, executable regression coverage for this story's client-side logic.
- No browser is available. Composer-disable/placeholder correctness (Task 2.5) is verified by reading `disabled`'s existing consumers in the template (all pre-existing bindings, not new ones — folding a new boolean into an existing computed changes no template code) and by reasoning about `chatExtrasStore.getThread()`'s existing, already-tested reactivity — not by a screenshot.

### Environment constraints (same as Stories 1.1–1.8 — read before starting)

- No `php` binary on host. Use Docker: `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <path>` and `... php vendor/bin/psalm --no-cache <paths>` on every touched PHP file (the test file only, this story — no production PHP file changes are expected; if one turns out to be needed, that is a HALT-worthy discovery, not a silent scope expansion). Mount is read-only — edit on host paths, verify through the container.
- `vendor/bin/phpunit` genuinely cannot execute in this environment (missing `tests/autoload.php`) — write the real test code, verify by manual reasoning against the traced production code (this Dev Notes section did that tracing), and state plainly that execution was not possible, per every prior story's discipline.
- Node/npm work natively on host: `npm run lint` (eslint), `npm run ts:check` (`vue-tsc --noEmit`), `npx vitest run` (or `npx vitest run <file>` per-file) are all genuinely executable — run them for real, report real pass/fail counts.
- No browser available — state manual-reasoning verification plainly, as above.

### Project Structure Notes

- `src/utils/threadState.ts` — existing flat-file util (Story 1.8), extending it with one more pure function is the established pattern, not a new one.
- `src/types/guards.ts` — existing file for exactly this class of pure predicate (`isAxiosErrorResponse`); `isThreadNotFoundError` is a natural, co-located addition. First test file for this module (`src/types/__tests__/guards.spec.ts`) — no conflicts with the unified project structure; `src/types/__tests__/` mirrors the `src/utils/__tests__/`, `src/composables/__tests__/` convention already in use elsewhere.
- No new components, no new routes, no new store.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.9] — story statement and AC1-AC5, verbatim (lines 910-946).
- [Source: _bmad-output/planning-artifacts/epics.md line 86 (FR-36)] — "Acting on a notification about a Thread opens that Thread with the relevant message in view; non-thread notifications open the main chat as today; a Locked Thread opens with its state shown and the composer disabled; a deleted Thread lands in the main chat with an explanation rather than an error page."
- [Source: _bmad-output/planning-artifacts/epics.md line 321] — "FR-36's notification navigation is pinned under test here, not in Epic 4... Deep-linking into a Thread largely works today... It follows FR-8 because a Locked Thread must be able to render its state and disable its composer for two of the criteria to mean anything."
- [Source: _bmad-output/planning-artifacts/epics.md line 387, 2004] — Epic 4 Story 4.3 and its payload change deliberately come *after* this story so they cannot silently break navigation; this story is the guard, not the thing being guarded.
- [Source: .../ARCHITECTURE-SPINE.md#AD-16] — query-param addressing, one composable (`useGetThreadId`-mould), no new named route; "degraded by design... lands on the Conversation's main chat" is the same posture AC4 requires for a different failure mode (absent Thread, not unsupported surface).
- [Source: lib/Notification/Notifier.php:239-247 (`prepare()`), 478-608 (`parseChatMessage()`)] — the traced link-generation code; read in full during story creation.
- [Source: lib/Controller/ChatController.php:923-926, 1298-1301] — the existing `{error: 'thread'}` / 404 identifier both relevant endpoints already return.
- [Source: lib/Service/ThreadService.php:530-537] — `validateThread()`, the single source of the 404.
- [Source: src/composables/useGetThreadId.ts, src/composables/useGetMessages.ts] — read in full during story creation; the exact catch-block gap for AC4 is at `getMessageContext()`'s `catch` block.
- [Source: src/components/NewMessage/NewMessage.vue] — read in full during story creation; `disabled`/`placeholderText` computeds, existing `chatExtrasStore`/`threadId`/`token` wiring.
- [Source: src/components/RightSidebar/Threads/ThreadHeader.vue] — `watch(currentThread, ...)` → `fetchSingleThread()`, and the `v-if`/`v-else-if` guards in `ChatView.vue`/`TopBar.vue` that make it disappear once `threadId` resets to 0.
- [Source: src/stores/chatExtras.ts::fetchSingleThread(), getThread()] — read in full during story creation.
- [Source: src/types/guards.ts::isAxiosErrorResponse] — the existing generic predicate `isThreadNotFoundError` wraps; `src/components/CalendarEventsDialog.vue:355` is the exact `{error: string}` access-pattern precedent copied.
- [Source: tests/php/Notification/NotifierTest.php:403-976 (`dataPrepareChatMessage()`/`testPrepareChatMessage()`)] — read in full during story creation; no existing `threadId` assertion anywhere in this file today.
- [Source: _bmad-output/implementation-artifacts/1-8-thread-state-is-visible-wherever-a-thread-appears.md] — Thread state rendering this story's AC3 reuses (`chatExtrasStore.getThread()`, `THREAD.STATE.LOCKED`); its Dev Notes' repeated, explicit "no new component-mount test infrastructure for this class of change" judgment, reused here for the same reasons.
- [Source: _bmad-output/implementation-artifacts/1-4-a-thread-manager-moves-a-thread-between-states-with-an-optional-reason-on-locking.md] — `THREAD.STATE.LOCKED` origin, `chatExtrasStore` thread-management wiring.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-create-story and bmad-dev-story

### Debug Log References

- `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l tests/php/Notification/NotifierTest.php` — no syntax errors.
- `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php vendor/bin/psalm --no-cache tests/php/Notification/NotifierTest.php lib/Notification/Notifier.php lib/Controller/ChatController.php lib/Service/ThreadService.php` — run twice (once with only the test file, once including the three production files the new assertions trace through). Both runs: 1 error (`Class Test\TestCase does not exist` — the documented, pre-existing, ignorable psalm/PHPUnit-not-installed artifact every prior story in this epic also reports). 0 real errors introduced.
- `vendor/bin/phpunit` genuinely cannot execute in this environment (missing `tests/autoload.php`, confirmed, matching every prior story's Dev Notes). The new PHP test (`testPrepareChatMessage`'s two new data-provider rows plus the `linkToRouteAbsolute` capture/assertion logic) was verified by manual reasoning against the traced production code in `lib/Notification/Notifier.php` (this story's Dev Notes section quotes the exact lines) and against the existing, already-passing structure of the 30+ pre-existing rows in the same method — not by execution. Stated plainly, not claimed as run.
- `npx vitest run src/utils/__tests__/threadState.spec.js` — genuine red-green TDD for `isThreadLocked()`: wrote the 4 new test cases, ran (14 pre-existing + 4 new = 18 passed), then temporarily broke the implementation (`return false && ...`) and re-ran to confirm the "Locked" case fails as expected (1 failed, 17 passed), then restored the correct implementation and re-ran (18/18 passed again).
- `npx vitest run src/types/__tests__/guards.spec.ts` — same genuine red-green cycle for `isThreadNotFoundError()` (first-ever test file for `guards.ts`): 7/7 passed, then broke the implementation (`return false`) and confirmed 1 of 7 fails as expected, then restored and re-confirmed 7/7 passed.
- `npx eslint` run individually on every created/modified TS/Vue/JS file (`NewMessage.vue`, `threadState.ts`, `threadState.spec.js`, `useGetMessages.ts`, `guards.ts`, `guards.spec.ts`) — all clean, no errors, no fixes needed.
- `npm run ts:check` (`vue-tsc --noEmit`, full project) — run after Task 2 and again after Task 3 — zero errors both times.
- `npm run lint` (full project, plain `eslint`) — zero errors.
- `npx vitest run` (full suite) — 1452 tests (1441 pre-existing per Story 1.8's last recorded baseline + 11 new: 4 in `threadState.spec.js`, 7 in `guards.spec.ts`), 1451 passed, 1 failed — the failure is inside `messagesStore.spec.js`'s unrelated "eases list" test group (`Error: Test timed out in 5000ms`), the exact same full-suite resource-contention timeout flake Stories 1.3, 1.4 and 1.8's Dev Agent Records documented for that identical file under identical concurrency conditions (Story 1.8 saw 3 such failures in the same run; this run saw 1 — consistent with a load-dependent flake, not a regression). Confirmed non-regressive by isolated re-run: `npx vitest run src/store/messagesStore.spec.js` alone — 87/87 passed.
- No component-mount or composable-mount test infrastructure was introduced for `NewMessage.vue`'s computed/template change or `useGetMessages.ts`'s catch-block wiring, per the explicit, four-times-repeated precedent in Stories 1.3/1.4/1.8's Dev Notes (judged disproportionate for this class of change in this codebase — `useGetMessagesProvider()` in particular has `onBeforeUnmount`/event-bus/interval side effects requiring a full component-host+router+Vuex+Pinia harness just to mount). Instead: the risky/non-trivial logic was pulled into two small pure functions (`isThreadLocked()`, `isThreadNotFoundError()`) that *do* get real, genuinely-executed, red-green-verified unit tests; the Vue/composable wiring around them was verified via `npx eslint` + `npm run ts:check` + careful manual trace of the exact code paths (documented in Dev Notes). Stated plainly, not claimed as mount-tested.
- No browser is available in this environment. Composer-disable/placeholder correctness was verified by reading `disabled`'s and `placeholderText`'s existing template consumers (unchanged bindings — folding a new boolean into an existing computed changes no template code) and by reasoning about `chatExtrasStore.getThread()`'s existing, already-tested (Story 1.8) reactivity — not by a screenshot.
- `git status --porcelain -- '*.ts' '*.vue' '*.js' '*.php'` and `grep -n "threadId" src/router/router.ts` reviewed to confirm AC5: `src/router/router.ts` was not touched by this story, no new route was added anywhere, and every change reads/writes the thread id exclusively through the existing `useGetThreadId()` shared composable.
- No HALT condition was triggered.

### Completion Notes List

- **AC1/AC2/AC5 (already-working navigation, now under test):** confirmed by tracing `lib/Notification/Notifier.php` that the message-specific link (`parseChatMessage()`, lines ~598-608) is built and `setLink()`-ed *before* the `isPreparingPushNotification()` branch that gates message-preview content — i.e. genuinely outside the push guard, exactly as epics.md claimed. No production code change was needed for these three ACs. Added regression coverage in `tests/php/Notification/NotifierTest.php::testPrepareChatMessage()`: captured the arguments of both `linkToRouteAbsolute()` calls (`prepare()`'s generic link and `parseChatMessage()`'s message-specific one, disambiguated by the `_fragment` key only the latter carries) and asserted the message-specific link carries `threadId` when set and omits it otherwise, across two new data-provider rows (one push, one in-app) plus, for free, all ~30 pre-existing rows (which now double as AC2's "no threadId when there is no Thread" regression net, since none of them set `threadId`). AC5 needed no client change; verified by grep that `useGetThreadId()` remains the sole reader of the query param.
- **AC3 (composer disabled in a Locked Thread — real gap, closed):** `src/components/NewMessage/NewMessage.vue` had no Thread-awareness at all prior to this story. Added `isThreadLocked(threadInfo)` — a pure, unit-tested function in `src/utils/threadState.ts` — and a matching computed in `NewMessage.vue` that reads `chatExtrasStore.getThread(token, threadId)`, folded into the existing `disabled` computed (one seam, matching `isReadOnly`/`noChatPermission`/`isRecordingAudio`'s existing pattern) so every control the template already gates on `disabled` (send, attachments, poll, audio recorder, thread-title field) is covered without touching the template. Added a `placeholderText` branch ("This thread has been locked"), positioned after the existing conversation-lock branch. Outside a Thread (`threadId === 0`), `chatExtrasStore.getThread(token, 0)` is always `undefined`, so `isThreadLocked` is a guaranteed no-op there — main-chat composing is unaffected by construction, not by an extra condition.
- **AC4 (land in main chat with an explanation when the Thread is gone — real gap, closed):** confirmed the server already returns a distinguishable `{error: 'thread'}` / 404 from both `ChatController::getMessageContext()` and `::receiveMessages()` when `ThreadService::validateThread()` fails — no server change needed. The client silently swallowed this: `useGetMessages.ts::getMessageContext()`'s `catch` block only branched on `isCancel`/304, and `chatExtras.ts::fetchSingleThread()`'s catch only logged to console. Added `isThreadNotFoundError()` (pure, unit-tested, first test coverage for `src/types/guards.ts`) and wired it into `getMessageContext()`'s existing catch block: on detection, shows an explanatory toast (`showError(t('spreed', 'This thread no longer exists'))`), resets the shared `contextThreadId` ref to 0 (dropping the `threadId` query param via `useGetThreadId`'s existing set-transform — no manual URL manipulation), and retries once with `threadId=0` so the participant lands at (or near) the same message in the main chat rather than a blank pane. The guard is keyed on the function's own `threadId` parameter (not the reactive ref, which the branch itself clears) so the one-shot retry can never re-trigger the branch — no infinite loop risk. `ThreadHeader.vue` needed no separate fix: it shares the same `useGetThreadId()` ref, so it disappears on the same reactive tick once the ref resets to 0, regardless of which fetch (this one or its own pre-existing, unrelated `fetchSingleThread()`) noticed the 404 first.
- **No HALT condition was triggered.** No ambiguity required a documented assumption beyond what story creation already recorded (Task 3's deliberate scoping to `getMessageContext()` only, leaving `fetchSingleThread()`'s separate console-error path untouched, as planned). No new dependency was needed (`showError` is already a project dependency, `@nextcloud/dialogs`, already used identically elsewhere, e.g. `src/stores/chatExtras.ts`).

### File List

**Created:**
- `src/utils/threadState.ts` — extended (pre-existing from Story 1.8) with `isThreadLocked()` (AC3).
- `src/utils/__tests__/threadState.spec.js` — extended (pre-existing from Story 1.8) with 4 new tests for `isThreadLocked()`.
- `src/types/__tests__/guards.spec.ts` — new file, first test coverage for `src/types/guards.ts`: 7 tests for `isThreadNotFoundError()` (AC4).

**Modified:**
- `src/utils/threadState.ts` — new `isThreadLocked(threadInfo)` pure function (AC3).
- `src/utils/__tests__/threadState.spec.js` — new `describe('isThreadLocked (Story 1.9, AC3)', ...)` block: 4 tests.
- `src/components/NewMessage/NewMessage.vue` — new `isThreadLocked` computed, folded into `disabled`; new `placeholderText` branch (AC3).
- `src/types/guards.ts` — new `isThreadNotFoundError(exception)` pure predicate, alongside the existing `isAxiosErrorResponse` (AC4).
- `src/composables/useGetMessages.ts` — `getMessageContext()`'s catch block: new branch detecting a thread-not-found 404, showing an explanation, resetting `contextThreadId`, and retrying once without thread scoping (AC4). New imports: `showError` (`@nextcloud/dialogs`), `isThreadNotFoundError` (`../types/guards.ts`).
- `tests/php/Notification/NotifierTest.php` — `dataPrepareChatMessage()`: 2 new data-provider rows with `threadId` set (one push, one in-app). `testPrepareChatMessage()`: new `?int $threadId = null` parameter; captures `linkToRouteAbsolute()` call arguments; asserts the message-specific link's `threadId`/absence; `getMessageParameters()` mock now threads `threadId` through when set (AC1, AC2, AC5).

## Change Log

- 2026-08-12 — Story drafted via bmad-create-story. Traced the actual notification-to-navigation path (not assumed): confirmed AC1/AC2/AC5 already work server- and client-side and need only test coverage (Task 1); found two real, unimplemented gaps — AC3's composer-disable-on-Locked-Thread and AC4's land-in-main-chat-on-absent-Thread — neither of which exist in the current codebase, both requiring new production code plus tests (Tasks 2, 3).
- 2026-08-12 — Story implemented end-to-end (Tasks 1-5) via bmad-dev-story. AC1/AC2/AC5: added regression coverage in `tests/php/Notification/NotifierTest.php` proving the message-specific notification link carries `threadId` when the message is in a Thread (including for push notifications, i.e. outside the push guard, per AC1's exact claim) and omits it otherwise (AC2), across two new data-provider rows plus, incidentally, all ~30 pre-existing rows; no production code changed for these three ACs since none was needed. AC3: closed a real gap — `NewMessage.vue`'s composer had no Thread-awareness at all; added a pure, unit-tested `isThreadLocked()` helper (`src/utils/threadState.ts`) folded into the existing `disabled` computed (one seam, matching the established pattern), plus a matching placeholder string. AC4: closed a real gap — the client silently swallowed the server's existing `{error: 'thread'}` 404 for an invalid `threadId`; added a pure, unit-tested `isThreadNotFoundError()` predicate (`src/types/guards.ts`, first test coverage for that file) wired into `useGetMessages.ts::getMessageContext()`'s catch block to show an explanation, drop the `threadId` query param, and retry once — landing the participant in the main chat instead of a blank/broken Thread view. 11 new tests added (4 `threadState.spec.js`, 7 new `guards.spec.ts`), all genuinely executed via `npx vitest run` with real red-green TDD cycles (implementation temporarily broken and restored to prove each new test actually catches a regression). `npm run lint` and `npm run ts:check` both clean across the full project; `php -l`/`psalm --no-cache` clean (only the documented, pre-existing, ignorable `Test\TestCase` artifact) on the touched PHP test file and the three production PHP files its new assertions trace through. Full-suite Vitest run: 1452 tests, 1451 passed, 1 failed — the same pre-existing, confirmed-non-regressive `messagesStore.spec.js` timeout flake Stories 1.3/1.4/1.8 documented (isolated re-run: 87/87 passed). No new component-mount or composable-mount test infrastructure was introduced, per the established, repeated precedent in this epic's prior stories — risky logic was extracted into pure, tested functions instead. No PHP production file was touched (only a test file) — matches the story's Dev Notes prediction that AC1/AC2/AC5 need no server change. No HALT condition triggered. Status set to `review`.
