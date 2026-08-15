---
baseline_commit: 6819859953c32f32ccaecf13f4b11f1ad382db00
---

# Story 1.8: Thread State is visible wherever a Thread appears

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a participant scanning a room,
I want to see at a glance which Threads are finished and which are shut,
So that I can tell a live discussion from one that ended three weeks ago without opening either.

## Acceptance Criteria

**AC1**
**Given** a Closed or Locked Thread
**When** it is rendered in the threads list row
**Then** its state is shown
**And** an Ongoing Thread shows no badge, so the common case stays quiet.

**AC2**
**Given** a Thread is open
**When** its header is rendered
**Then** the header shows the current state and names who set it, and for a Locked Thread shows Story 1.4's reason where one exists — and where no reason was given, shows only who locked it, with no empty affordance.

**AC3**
**Given** a Thread Root Message in the main chat
**When** it is rendered
**Then** its state appears alongside the existing thread title and reply count.

**AC4**
**Given** a Thread in the Followed Thread List in the left sidebar
**When** its row is rendered
**Then** the row shows state.

**AC5**
**Given** the Closed indicator
**When** a participant reads it
**Then** it conveys that the Thread can still be posted in and that posting reopens it, rather than reading as a shut Thread — the mitigation for "closed" meaning the opposite in issue trackers.

**AC6**
**Given** state must not be conveyed by colour alone
**When** any state indicator renders
**Then** it carries a label or icon as well as any tint
**And** it is legible in light and dark themes at normal and high contrast. *(NFR-8, NFR-9)*

**AC7**
**Given** all new user-visible strings
**When** they are added
**Then** they are translatable, and the Thread Title is never translated. *(NFR-10)*

**AC8**
**Given** a participant viewing any of these four surfaces when another actor changes the state
**When** the system message relays
**Then** every surface updates without a reload, driven by the single store rather than by each component holding its own copy. *(NFR-11, AD-15)*

**AC9**
**Given** a relayed state-change message for a Thread the store has never loaded
**When** it arrives
**Then** the store either adopts the Thread the payload carries or ignores the message
**And** it never creates a half-populated entry that later reads as loaded.

**AC10**
**Given** `ThreadHeader.vue` is filed under `src/components/RightSidebar/Threads/` but renders in `TopBar.vue` and `ChatView.vue`
**When** this story touches it
**Then** the change is verified in the surfaces it actually renders in, not in the sidebar.

**AC11**
**Given** a Thread Manager viewing the threads list that exists today — the surface Epic 2 later replaces with the Directory
**When** a row is rendered
**Then** the row offers a menu carrying Story 1.4's four transitions, so a Thread is closed or locked **without being opened**
**And** for a participant who is not a Thread Manager the menu is absent, not disabled, driven by Story 1.3's store getter. *(UJ-1, FR-10)*

**AC12**
**Given** a Thread Manager closes a Thread from that row menu
**When** the request succeeds
**Then** the row's state indicator updates in place and the participant stays in the list — no navigation into the Thread and no navigation away from the list
**And** if the list is filtered to Ongoing, the Thread leaves the list as it goes. *(UJ-1)*

## Tasks / Subtasks

- [x] **Task 1 — `ThreadStateBadge.vue`: one shared, accessible state indicator (AC1, AC3, AC4, AC5, AC6, AC7)**
  - [x] 1.1 Read `src/components/RightSidebar/Threads/ThreadItem.vue`, `ThreadHeader.vue`, `threadsConstants.ts` and `src/components/MessagesList/MessagesGroup/Message/MessagePart/MessageBody.vue` in full before writing (Dev Notes has current-state notes for each; re-confirm no drift).
  - [x] 1.2 Create `src/components/RightSidebar/Threads/ThreadStateBadge.vue`: `defineProps<{ state: number }>()`. Renders **nothing** (`v-if="state !== THREAD.STATE.ONGOING"` at the root) for Ongoing — this is what makes AC1's "no badge, common case stays quiet" true everywhere the component is used, not a per-call-site `v-if`. For Closed: `IconArchiveOutline` (the same icon Story 1.4 already uses for the "Close thread" action in `ThreadHeader.vue`, for visual continuity between the action and its result) + the label text `t('spreed', 'Closed')`. For Locked: `IconLockOutline` (same icon as the "Lock thread" action) + `t('spreed', 'Locked')`. Icon + text label together satisfy AC6 — colour (a tint on the pill) is decoration, never the only signal.
  - [x] 1.3 AC5 — the Closed indicator must convey that posting reopens it. Add `:title` and `:aria-label` on the badge's root element, populated only for the Closed state, with `t('spreed', 'Closed — posting a new message reopens this thread')`. This follows the existing `NcButton`-with-both-`:title`-and-`:aria-label` convention already in `ThreadHeader.vue` (e.g. the Back button) rather than introducing a new tooltip mechanism (`v-tooltip`/`NcTooltip` have zero prior usage anywhere under `src/`, confirmed by `grep -rl v-tooltip src` — do not introduce that pattern here). The Locked badge's `:title`/`:aria-label` is simply `t('spreed', 'Locked')` — AC5's "reads as a shut Thread" mitigation is specific to Closed; Locked genuinely does refuse writes (Stories 1.6/1.7), so no reopens-on-post claim belongs there.
  - [x] 1.4 Style: reuse the existing pill pattern already in `ThreadItem.vue`'s `.thread__details-replies` (`border-radius: var(--border-radius-pill)`, `background-color: var(--color-primary-element-light)`, `color: var(--color-main-text)`) rather than inventing new colour tokens — this is also what keeps AC6's dark/high-contrast legibility free: those are the host theme's own tokens, already proven legible in both themes by the component it's copied from (Consistency Convention: "New surfaces follow the host Nextcloud's design tokens rather than introducing colour values"). Do not hex-code a colour.
  - [x] 1.5 `npx eslint` and `npm run ts:check` on the new file.

- [x] **Task 2 — "Who set it": a testable, pure derivation from already-loaded message history (AC2)**
  - [x] 2.1 Confirm (already confirmed during story creation, re-verify if in doubt) that no persisted "who changed the Thread's state" field exists anywhere in `TalkThread`/`TalkThreadInfo` (`lib/ResponseDefinitions.php`) — Story 1.4 AC10 only wrote the lock **reason** to a column specifically "so the thread header needs no history walk"; it made no equivalent guarantee for the **actor**. The only place that actor exists client-side is the lifecycle system message itself (`thread_closed`/`thread_locked`/`thread_reopened`/`thread_unlocked`, all parsed server-side in `lib/Chat/Parser/SystemMessage.php` and already flowing into the Vuex message store via `processMessage`'s unconditional `context.commit('addMessage', ...)`, confirmed by reading `src/store/messagesStore.js` in full during story creation). See the "Assumption — deriving 'who set it'" Dev Note below before implementing; this is an interpretive resolution, not something to re-derive from scratch.
  - [x] 2.2 Add a pure, exported helper `getThreadStateActor(messages: ChatMessage[], threadId: number, state: number): string | undefined` to a new file `src/utils/threadState.ts`. It filters `messages` to `threadId` matches whose `systemMessage` is one of the four lifecycle verbs, maps `state` to the verb(s) that produce it (`CLOSED` → `thread_closed`; `LOCKED` → `thread_locked`; `ONGOING` → `thread_reopened` **or** `thread_unlocked`), takes the **last** (highest-id) match via `.findLast(...)`, and returns `getDisplayNameWithFallback(match.actorDisplayName, match.actorType, true)` — or `undefined` if no match exists. Pure function, no store/composable dependency, so it is unit-testable without mounting anything (matches Story 1.3's documented precedent that this codebase has no component-mount test infrastructure for `ThreadItem.vue`/`ThreadHeader.vue`, and judges introducing it disproportionate for this story too).
  - [x] 2.3 Add a second small exported helper in the same file, `getThreadStateSummary(state, actorName, lockReason)`, returning the exact display string per the Dev Notes table below (handles the "no reason → just who locked it, no empty affordance" branch of AC2 and the Ongoing/no-actor-found quiet case) — keeping the string-assembly logic out of `ThreadHeader.vue`'s template so it stays testable.
  - [x] 2.4 Add `tests/vitest` — actually `src/utils/__tests__/threadState.spec.js` (new file; no existing `src/utils/__tests__/` precedent for this exact file, but `src/utils/__tests__/` as a directory pattern already exists for other `src/utils/*.ts` helpers — confirm the directory convention by `ls src/utils/__tests__/` before creating). Cases: no matching message → `undefined`; one `thread_locked` message → that actor; `thread_locked` then later `thread_unlocked` then later `thread_locked` again (different actor) → the **latest** `thread_locked` actor, not the first; a message for a **different** `threadId` is ignored; `getThreadStateSummary` for Locked+reason, Locked+no-reason+known-actor, Locked+no-reason+unknown-actor (no empty affordance — omits the "locked by" clause entirely rather than rendering "Locked by " with nothing after it), Closed+known-actor, Ongoing (always `undefined`/no summary, matching AC1's quiet-common-case principle applied to the header per the Dev Notes assumption below).
  - [x] 2.5 `npx eslint`, `npm run ts:check`, `npx vitest run src/utils/__tests__/threadState.spec.js`.

- [x] **Task 3 — `ThreadItem.vue`: row badge, used by both AC1 and AC4's surfaces (AC1, AC4, AC6, AC7)**
  - [x] 3.1 Re-read `ThreadItem.vue`'s `#details` template slot (renders `numReplies` pill + `NcDateTime`) — the row's already-established slot for compact per-row metadata.
  - [x] 3.2 Add `<ThreadStateBadge :state="thread.thread.state" class="thread__details-state" />` inside `#details`, before the existing reply-count pill, so state reads first (title → state → replies → time), matching the left-to-right importance order the row's other fields already use (name/subname first, metadata trailing).
  - [x] 3.3 No prop/context split is needed for AC1 vs AC4 — `ThreadItem.vue` is the **same** component `ThreadsTab.vue` (AC1's "threads list") and `LeftSidebar.vue` (AC4's "Followed Thread List") both already render one instance per Thread (confirmed during story creation: `LeftSidebar.vue` imports `ThreadItem` from `../RightSidebar/Threads/ThreadItem.vue` and loops `chatExtrasStore.followedThreadsList`, structurally identical to `ThreadsTab.vue`'s loop over `chatExtrasStore.followedThreads`/`getThreadsList`). Adding the badge here satisfies both ACs from one change — do not duplicate the badge markup into a second, list-specific component.
  - [x] 3.4 `npm run lint` and `npm run ts:check` on `ThreadItem.vue`.

- [x] **Task 4 — `ThreadItem.vue`: the row management menu, scoped to the current threads list only (AC11, AC12)**
  - [x] 4.1 Re-read epics.md's exact AC11 wording ("the threads list that exists today — the surface Epic 2 later replaces with the Directory") and its cross-reference at line 341 (Story 2.4 AC15 "inheriting the list-row menu Story 1.8 AC11 established"). This scopes the menu to `ThreadsTab.vue`'s list specifically — **not** the cross-Conversation Followed Thread List in the left sidebar, which Epic 2/3 continue to treat as a materially different surface (FR-20 gives it its own search query; Story 3.6 lists it as a distinct unread surface alongside the Directory). Since `ThreadItem.vue` is shared between the two, gate the new menu items behind a new prop rather than showing them everywhere the component is used.
  - [x] 4.2 Add `const { thread, showManagementActions = false } = defineProps<{ thread: ThreadInfo, showManagementActions?: boolean }>()` to `ThreadItem.vue`. `ThreadsTab.vue` passes `:show-management-actions="true"` on its `<ThreadItem>` loop; `LeftSidebar.vue`'s usage is left unchanged (prop omitted, defaults `false`) — this is an explicit opt-in, so the Followed Thread List's behaviour is provably unchanged by this task (verify with `npm run lint`/`ts:check`, and by grepping `LeftSidebar.vue`'s `<ThreadItem` usage post-edit to confirm no new prop was added there).
  - [x] 4.3 In the `#actions` template, inside the existing `v-if="submenu === null"` block, add up to two `NcActionButton`s **after** the existing "Edit thread details"/"Thread notifications" actions, `v-if="showManagementActions && canManageThread"`, computed from `thread.thread.state` — copy `ThreadHeader.vue`'s exact `NcActionButton`/icon/label set for each of the four transitions (`IconArchiveOutline`/"Close thread", `IconLockOpenOutline`/"Reopen thread", `IconLockOutline`/"Lock thread", `IconLockOpenOutline`/"Unlock thread"), so the row menu and the header menu present identically. Reuse the same `state === Ongoing → Close+Lock`, `state === Closed → Reopen+Lock`, `state === Locked → Unlock` mapping Story 1.4 Task 13.2 already established — do not invent a different mapping for the row.
  - [x] 4.4 Wire each action to `chatExtrasStore.changeThreadState(thread.thread.roomToken, thread.thread.id, <STATE>)` directly (Close/Reopen/Unlock), and for Lock: `const reason = await chatExtrasStore.promptLockThreadReason(); await chatExtrasStore.changeThreadState(thread.thread.roomToken, thread.thread.id, THREAD.STATE.LOCKED, reason)` — **no `if` guard on `reason`**: `ThreadHeader.vue`'s actual `lockThread()` (Story 1.4) calls `changeThreadState()` unconditionally after awaiting `promptLockThreadReason()`, matching that function's own `Promise<string>` return type in `chatExtras.ts`; copy that exact, unconditional shape rather than inventing a guard `ThreadHeader.vue` does not have.
  - [x] 4.5 AC12 — "the row's state indicator updates in place... no navigation... participant stays in the list": satisfied by construction, not by new code. `changeThreadState()` (Story 1.4) calls `addThread(token, response.data.ocs.data)` on success, which replaces the store's entry for that `[token][threadId]`; `ThreadsTab.vue` renders `v-for="thread of followedThreads"` / `getThreadsList(token)` directly off that same reactive store, so the row's `ThreadStateBadge` (Task 3) re-renders with the new state without any router navigation — nothing in this task pushes a route. Confirm this by reading `ThreadsTab.vue`'s list-rendering `v-for` once more and tracing that no `@click` on the row itself (only on the `NcActions` menu) triggers navigation, which would be the AC12 regression to watch for.
  - [x] 4.6 AC12's second clause ("if the list is filtered to Ongoing, the Thread leaves the list as it goes") is **not yet exercisable** — `ThreadsTab.vue` has no Thread-state filter today; that is Epic 2 Story 2.3's scope. Document this explicitly in Dev Notes (see below) as a forward-looking clause with nothing to test yet, rather than silently skipping it or fabricating a filter this story was not asked to build.
  - [x] 4.7 `npm run lint` and `npm run ts:check` on `ThreadItem.vue` and `ThreadsTab.vue`.

- [x] **Task 5 — `ThreadHeader.vue`: state, who set it, reason (AC2, AC10)**
  - [x] 5.1 Re-read `ThreadHeader.vue` in full (already re-read during story creation) — it already computes `threadState` (Story 1.4) and `canManageThread` (Story 1.3); it does not yet import Vuex's `useStore` (removed in Story 1.3's refactor, for a *different*, now-inapplicable reason — that removal was about not re-deriving *authority* from Vuex, not a ban on reading message history from it. Re-adding `useStore` here for a different purpose does not reopen that decision).
  - [x] 5.2 Add `import { useStore } from 'vuex'` and `const store = useStore()`. Add `const stateActorName = computed(() => getThreadStateActor(store.getters.messagesList(token.value), threadId.value, threadState.value))` (Task 2's helper) and `const stateSummary = computed(() => getThreadStateSummary(threadState.value, stateActorName.value, currentThread.value?.thread.lockReason ?? null))`.
  - [x] 5.3 In the template, inside `.conversation-header__text`, after the existing `.description` (`numReplies`) paragraph, add: `<p v-if="stateSummary" class="description description--state"><ThreadStateBadge :state="threadState" />{{ stateSummary }}</p>`. Ongoing renders nothing here (Task 2.3/2.4's helper returns `undefined` for Ongoing, matching AC1's quiet-common-case principle — see the Dev Notes assumption below for why this also applies to the header, not only list rows).
  - [x] 5.4 AC10 — this story's change to `ThreadHeader.vue` must be verified against how it actually renders: `TopBar.vue:54` (`<ThreadHeader v-else-if="!isInCall && threadId" class="top-bar__wrapper" />`, non-standalone, narrow horizontal space in the conversation's top bar) and `ChatView.vue:27` (`<ThreadHeader v-if="isSidebar && threadId" standalone />`, standalone mode, even narrower — Files sidebar / floating call). Confirm the new `.description--state` line degrades gracefully at both widths: reuse the existing `.description`'s `overflow: hidden; text-overflow: ellipsis;` rule (already scoped in `<style>`) rather than adding new overflow handling, and confirm by reading both parent components' surrounding CSS that neither imposes a fixed height incompatible with a second wrapped/truncated line. Do **not** add a sidebar-specific code path — the same markup must serve both call sites, which is the point of this AC.
  - [x] 5.5 `npm run lint` and `npm run ts:check` on `ThreadHeader.vue`.

- [x] **Task 6 — `MessageBody.vue`: Thread Root Message state (AC3, AC6, AC7)**
  - [x] 6.1 Re-read `MessageBody.vue`'s `isThreadStarterMessage`/`threadInfo`/`threadTitle`/`threadNumReplies` computed properties and the `message-main__thread-title` / `message-actions__thread` template blocks (both already read in full during story creation).
  - [x] 6.2 Add `threadState()` computed: `return this.threadInfo?.thread.state ?? THREAD.STATE.ONGOING` (mirrors the existing `threadNumReplies`/`threadTitle` fallback-to-message-field pattern, but `state` has no per-message fallback field the way `threadTitle`/`threadReplies` do — the entity's own default is Ongoing, so an unloaded thread renders quietly with no badge, which is the correct degrade per AC1's spirit rather than an error state).
  - [x] 6.3 Add `<ThreadStateBadge v-if="isThreadStarterMessage" :state="threadState" class="message-actions__thread-state" />` next to the existing `message-actions__thread` reply-count button (inside `.message-actions`, alongside `slot name="default"`) — "alongside the existing thread title and reply count" per AC3's literal wording; do not merge it into the `message-main__thread-title` paragraph, which is the *title* line, not the metadata row the reply-count button already occupies.
  - [x] 6.4 **Register the component — `MessageBody.vue` uses the Options API** (`<script>`, no `<script setup>`), unlike `ThreadItem.vue`/`ThreadHeader.vue`. Every component referenced in its template — down to plain icons (`IconForumOutline`, `NcButton`, etc.) — is both explicitly imported *and* listed in the `components: {}` object; a bare import alone silently fails to resolve at render time. Add `import ThreadStateBadge from '../../../../RightSidebar/Threads/ThreadStateBadge.vue'` (path relative to `MessagesList/MessagesGroup/Message/MessagePart/`, verify the exact relative depth against an existing cross-directory import in this same file before writing it) and add `ThreadStateBadge` to the `components: {}` object. This step has no automated failure signal if skipped — this repo's `vue/no-undef-components` eslint rule is `'warn'` (not `'error'`) and `npm run lint` runs without `--max-warnings`, and this file is plain `.vue`/Options API so `vue-tsc`/`ts:check` does not type-check its template bindings either — so treat this subtask as a hard, individually-verified step, not an assumed side effect of adding the tag in 6.3.
  - [x] 6.5 `npm run lint` and `npm run ts:check` on `MessageBody.vue`.

- [x] **Task 7 — Live propagation: the store stays the single source, and the one real relay gap this story must close (AC8, AC9)**
  - [x] 7.1 Confirm (re-verify, already traced during story creation) that `updateThread()` in `src/stores/chatExtras.ts` already satisfies AC9 exactly as written: when the store has never loaded `[token][threadId]`, it calls `fetchSingleThread()` instead of writing a partial object, and `fetchSingleThread()` only ever calls `addThread()` with a **complete** `ThreadInfo` fetched from the server (or, on error, writes nothing) — so a half-populated entry that later reads as loaded cannot occur today. This AC needs **no new code**, only a regression test proving the existing behaviour explicitly for a Thread the store has never seen (Task 7.4).
  - [x] 7.2 AC8 is **not** fully satisfied today for the four new surfaces, and this is a real, fixable, in-scope gap — not the same pre-existing, explicitly-out-of-scope wiring gap Story 1.4 documented for AC16. Read `src/store/messagesStore.js`'s `processMessage()` action in full (already read in full during story creation) and trace: `thread_closed`/`thread_locked`/`thread_reopened`/`thread_unlocked` are **not** in `SYSTEM_MESSAGE_TYPE_HIDDEN` (Story 1.4's deliberate choice — they render as chat bubbles), so they do **not** take the `isHiddenSystemMessage()` branch (~line 472) where `THREAD_CREATED`/`THREAD_RENAMED` get their special-cased `fetchSingleThread()`/`updateThreadTitle()` handling (~lines 496-505). They fall through to the generic "Update threads" block (~lines 671-693), which only re-fetches when the thread is **unknown**, or patches `title`/`numReplies`/`lastMessageId`/`last` when a **known** thread's `numReplies`/`title`/`lastMessageId` changed — it never reads or writes `state`/`lockReason`, because the relayed/polled `ChatMessage` object itself carries no such fields (only `threadTitle`/`threadReplies` are mirrored onto `ChatMessage`, confirmed by grep). Left unfixed, a participant already viewing a Thread's row/header/root-message when another actor closes or locks it keeps seeing the old state until they navigate away and back — the literal scenario AC8 exists to prevent, on the four surfaces this story just built.
  - [x] 7.3 Fix: in the "Update threads" `if (message.isThread)` block, before the existing `title`/`numReplies`/`lastMessageId` diff check, add a branch for the four lifecycle verbs that **unconditionally** calls `chatExtrasStore.fetchSingleThread(token, message.threadId)` when the thread is already known (mirroring, structurally, the existing `THREAD_CREATED` precedent a few lines above, which already calls the same function for a different trigger condition). This is one extra `GET` per lifecycle transition per client with that Conversation open — not a per-rendered-row query, so it does not engage AD-9 (which governs list-page rendering cost, not single live-event refreshes). This also benefits the **poll** fallback path, not only the signalling relay, since `processMessage()` is the one entry point for both (`fromRealtime` is just a flag on the same function) — fixing it here is strictly better than fixing it only in the signalling-specific consumer. Comment the addition with the AC8 reference and the reasoning above (this codebase's convention, established in every prior story, is to document *why* at the point of a non-obvious fix, not just *what*).
  - [x] 7.4 Tests in `src/store/__tests__/messagesStore.spec.js` (confirm exact path via `ls src/store/*.spec.js` — Story 1.3's Dev Agent Record referenced it as `src/store/messagesStore.spec.js`, sibling to the source file, not a `__tests__` subfolder; follow whichever convention the file actually uses). Add cases: a `thread_closed` message for a **known** Thread triggers `fetchSingleThread` (mock `chatExtrasStore.fetchSingleThread`, assert called with the right token/threadId); the same for `thread_locked`/`thread_reopened`/`thread_unlocked`; a `thread_closed` message for an **unknown** Thread still goes through the existing not-known branch (single `fetchSingleThread` call, not a double-fetch from both branches — this guards against accidentally hitting the new branch and the pre-existing `!thread` branch for the same message); a plain reply (`message.isThread`, no `systemMessage`) is unaffected by this change (existing title/numReplies diff logic still runs, existing tests for it — if any — still pass unmodified). AC9's regression test: `updateThread()` for an unknown `[token][threadId]` calls `fetchSingleThread` and never writes a partial entry — extend `src/stores/__tests__/chatExtras.spec.js` if this exact case is not already covered there (check first; Story 1.2/1.3/1.4 added many `updateThread`-adjacent cases already).
  - [x] 7.5 `npx eslint`, `npm run ts:check`, `npx vitest run src/store/messagesStore.spec.js src/stores/__tests__/chatExtras.spec.js`.

- [x] **Task 8 — Regression pass and housekeeping**
  - [x] 8.1 `grep -rn "ThreadStateBadge\|getThreadStateActor\|getThreadStateSummary" src/` — confirm every intended call site (Tasks 3, 5, 6) actually uses the shared helpers rather than a second, drifted re-implementation.
  - [x] 8.2 `grep -rn "showManagementActions" src/` — confirm exactly one `true`-passing call site (`ThreadsTab.vue`) and that `LeftSidebar.vue`'s `<ThreadItem>` usage is unmodified (AC4's surface keeps today's behaviour beyond the new badge).
  - [x] 8.3 `git status` / `git diff --stat` — confirm the File List below is exhaustive and no unrelated file was touched.
  - [x] 8.4 `npm run lint` (full project), `npm run ts:check` (full project), `npx vitest run` (full suite — watch for the known, previously-confirmed-non-regressive `messagesStore.spec.js` full-suite timeout flake documented in Stories 1.3/1.4; isolate-rerun that file alone if it recurs, do not treat it as a regression without checking).
  - [x] 8.5 Every PHP file touched (expected: none — see Dev Notes "Why this story should not need a backend change") gets `php -l` and `psalm --no-cache` via the container regardless, as a hard gate, in case Task 2/7's investigation turns up a reason a backend field genuinely is required (document as a HALT-worthy deviation if so, per the skill's HALT conditions, rather than silently expanding scope).
  - [x] 8.6 Update Dev Agent Record, File List, Change Log; set Status to `review`.

## Dev Notes

### Current state of the files this story touches (read in full during story creation)

**`src/components/RightSidebar/Threads/ThreadItem.vue`** (234 lines) — row component for both the "threads list" (`ThreadsTab.vue`) and the Followed Thread List (`LeftSidebar.vue`). `#details` slot renders a reply-count pill (`.thread__details-replies`) and `NcDateTime`. `#actions` slot: `submenu === null` shows "Edit thread details" (`v-if="canManageThread"`) and "Thread notifications" (`isMenu`); `submenu === 'notifications'` shows the back button + notification-level radio list. `canManageThread` already reads `chatExtrasStore.canManageThread(...)` (Story 1.3). No state badge, no lifecycle-transition actions today.

**`src/components/RightSidebar/Threads/ThreadHeader.vue`** (274 lines) — renders in `TopBar.vue` (non-standalone) and `ChatView.vue` (`standalone`). Already shows title + `numReplies` (no state). Already has the full four-transition `NcActions` menu (Story 1.4, `v-if="canManageThread"`), computed off `threadState = currentThread.value?.thread.state ?? THREAD.STATE.ONGOING`. Does not import Vuex's `useStore` (removed in Story 1.3 for authority-derivation reasons that do not apply to reading message history).

**`src/components/MessagesList/MessagesGroup/Message/MessagePart/MessageBody.vue`** (882 lines) — `isThreadStarterMessage` prop; `threadInfo` computed reads `chatExtrasStore.getThread(token, threadId)`; `threadTitle`/`threadNumReplies` computeds fall back to message-level `threadTitle`/`threadReplies` fields when the store hasn't loaded the Thread. Template: `.message-main__thread-title` paragraph (icon + title, above the message body) and a `.message-actions__thread` `NcButton` (reply-count, inside `.message-actions`, alongside the reactions/buttons slot) — no state today.

**`src/components/LeftSidebar/LeftSidebar.vue`** — imports `ThreadItem` from `../RightSidebar/Threads/ThreadItem.vue`; renders one per `chatExtrasStore.followedThreadsList` entry inside `.threads-tab__list` when `showThreadsList` is true. Structurally the AC4 surface. Not touched by this story except implicitly (the shared `ThreadItem.vue` gains a badge every instance renders, and gains a prop this usage does not pass).

**`src/components/RightSidebar/Threads/ThreadsTab.vue`** — renders `ThreadItem` per `followedThreads`/`getThreadsList(token)` entry inside its own `.threads-tab__list`. This is AC11's "threads list that exists today." No Thread-state filter exists yet (Epic 2 Story 2.3's scope).

**`src/stores/chatExtras.ts`** — `updateThread()` already guards AC9 (see Task 7.1). `changeThreadState()`/`promptLockThreadReason()` already exist (Story 1.4) and are reused as-is by Task 4's row menu — no store changes needed for AC11/AC12 beyond what Story 1.4 already built.

**`src/store/messagesStore.js`** — `processMessage(context, { token, message, fromRealtime })` is the single entry point for both the signalling relay and the REST poll fallback. `isHiddenSystemMessage()`-branch handling (THREAD_CREATED/THREAD_RENAMED special cases) and the later generic `if (message.isThread)` "Update threads" block are two **different** code paths — the four new lifecycle verbs (not hidden, per Story 1.4) take the second one, which does not read `state`/`lockReason` today (Task 7's fix).

**`src/utils/message.ts`** — `SYSTEM_MESSAGE_TYPE_HIDDEN` does **not** include the four lifecycle verbs (Story 1.4, deliberate — they render as visible chat bubbles). `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` **does** include them (load-bearing for the relay to reach the store at all). Neither list needs a change from this story.

**No existing `ThreadStateBadge`/`ThreadStateIndicator`/similar component exists** — confirmed via `find src -iname "*thread*"` during story creation. This story creates the first one.

**No `v-tooltip`/`NcTooltip` usage exists anywhere under `src/`** (confirmed via `grep -rl v-tooltip src`) — do not introduce it; use `:title`/`:aria-label` on the existing `NcButton`-adjacent convention instead (Task 1.3).

### Assumption — deriving "who set it" from message history, not a new persisted field (documented, no interactive user available)

AC2 requires the header to "name who set it" for the Thread's current state. No story before this one persisted an actor for a state transition: Story 1.4 AC10 deliberately wrote only the lock **reason** to `talk_threads.lock_reason`, explicitly "so the thread header needs no history walk" — a guarantee scoped to the reason, not the actor. The only place the actor of a transition exists is the lifecycle system message that Story 1.4 already posts and already flows into the client's message store on every processed message.

Two readings were considered: (a) add a new persisted `stateActorId`/`stateActorType` pair to `Thread`, following AD-1's full field checklist (migration, `addType`, `createFromRow`, `fromJson`, `toJson`, `toArray`, both `SelectHelper` branches, `ResponseDefinitions.php`, OpenAPI/TS regeneration) — a real, substantial backend change touching files no story in this epic has needed to touch for a *display*-only requirement; or (b) derive it client-side from the system message already present in the loaded conversation's message history, which this story's own AC2 sits squarely inside a story whose other eleven ACs are otherwise entirely about *rendering* existing data on new surfaces, not about growing the server's data model further.

**Resolution:** (b). This keeps Story 1.8 what its title says it is — visibility, not new persistence — matches this task's own steer ("very likely primarily/entirely a FRONTEND story"), and the data is genuinely present and correct: the system message that performed the transition necessarily carries the correct actor (Story 1.4's `SystemMessage.php` parser output), and it is already committed into the same Vuex message store `MessageBody.vue`/every other message-rendering surface already reads. The cost is that "who set it" can only be shown when that message is loaded in the client's message history for the open Conversation — normal for an open Thread (its own messages, including its lifecycle system messages, are what's loaded when the Thread is open) but not retrievable from a cold Directory-row hover, which is exactly why AC2 scopes this to "a Thread is open" and AC1/AC4's list-row ACs do **not** ask for "who," only for the state itself. If a future story needs "who" without opening the Thread, that is new, explicit scope, not an interpretation smuggled into this one.

### Assumption — the header treats Ongoing the same "quiet" way list rows do (documented, no interactive user available)

AC1 is explicit that "an Ongoing Thread shows no badge, so the common case stays quiet" — but that sentence is written under AC1 (list rows), and AC2 (header) has no equivalent carve-out in its own text: "the header shows the current state and names who set it" reads, taken literally, as unconditional.

**Resolution:** apply the same quiet-common-case principle to the header. Every open Thread that has never been closed or locked is Ongoing by default (Story 1.2's migration default), so an unconditional reading would show *some* state text on literally every Thread anyone opens — the opposite of "the common case stays quiet," and inconsistent with AC1's explicit design rationale for the identical state value on a different surface of the same feature. `getThreadStateSummary()` (Task 2.3) therefore returns nothing for Ongoing, matching `ThreadStateBadge`'s own `v-if` (Task 1.2) — both surfaces are silent for the default case, and the header shows text only for Closed/Locked, where there is something non-default to report and (per AC2's own text) a reason and/or an actor to name.

### AC2 display-string table (Task 2.3's `getThreadStateSummary`)

| State | Reason | Actor found | Rendered summary text |
| --- | --- | --- | --- |
| Ongoing | — | — | (nothing — badge and summary both absent) |
| Closed | n/a | yes | `t('spreed', 'Closed by {actor}', { actor })` |
| Closed | n/a | no | `t('spreed', 'Closed')` |
| Locked | given | yes | `t('spreed', 'Locked by {actor} ({reason})', { actor, reason })` |
| Locked | given | no | `t('spreed', 'Locked ({reason})', { reason })` |
| Locked | none | yes | `t('spreed', 'Locked by {actor}', { actor })` |
| Locked | none | no | `t('spreed', 'Locked')` |

No branch ever renders a dangling `()` or "by " with nothing after it — this is the literal "no empty affordance" AC2 names for the no-reason case, generalised consistently to the no-actor-found case too.

### Architecture compliance

- **AD-9** — the badge on `ThreadItem.vue` reads `state`/`canManage` fields already present on every `TalkThreadInfo` the list endpoints return (Stories 1.2/1.3) — no new per-row query. Task 7's `fetchSingleThread()` call is one request per **live transition event**, not per rendered row or per page load, so it does not engage this AD (which governs list-page rendering cost).
- **AD-14/AD-15** — no new system-message verb, no new registry entry; this story only *consumes* the six registries Story 1.4 already completed. All four surfaces read Thread state exclusively through `useChatExtrasStore` getters (`getThread`/`getThreadsList`/`followedThreadsList`, all pre-existing) — no component introduces a local copy, satisfying AC8's "driven by the single store" clause by construction for every surface except the one genuine gap Task 7 closes (the store itself not yet being told about a live lifecycle transition for an already-known Thread).
- **AD-18** — no new capability gate is added by this story. The existing `thread-management`/`threads` local capability flags (Story 1.2) already gate every surface this story decorates (a federated Conversation cannot reach a Thread with a non-Ongoing state through any path this story touches, since Story 1.4's `setState()` endpoint itself is already inaccessible there — see Story 1.4 AD-18 compliance note). Nothing here needs an additional `hasTalkFeature()` check beyond what already wraps these components.
- **Consistency Conventions — Accessibility & theming** — "state is never conveyed by colour alone... carries a label or icon, not just a tint" is `ThreadStateBadge.vue`'s entire reason for existing as one shared component rather than four independent implementations that could drift on this exact point.

### Why this story should not need a backend change

Every field this story displays already exists on `TalkThreadInfo` (`state`, `lockReason` — Stories 1.2/1.4) or is derivable client-side from already-relayed data (the state-change actor — see the Assumption above). No AC in this story asks for a new field, a new endpoint, or a new capability. If Task 2's investigation or Task 8.5's PHP-file check turns up a reason this is not achievable without a backend change, that is a HALT-worthy discovery to raise explicitly, not a silent scope expansion.

### Environment constraints (same as Stories 1.1–1.4 — read before starting)

- This story is expected to be **entirely frontend** (Vue/TS). No `php` binary on the host; if Task 8.5 turns up an unexpected PHP file, use `docker exec -w /var/www/html/custom_apps/spreed clavis-deploy-nextcloud-1 php -l <path>` and `... php vendor/bin/psalm --no-cache <paths>` (mount is read-only, edit on host paths).
- Node/npm work natively on the host. `npm run lint`, the TS typecheck script (confirm exact name via `package.json` — Stories 1.3/1.4 used `npm run ts:check`), and `npx vitest run` are all genuinely executable here — run them for real, report real pass/fail counts, per every prior story's established discipline.
- No browser is available. Visual/UX correctness is verified by careful reading of existing similar components (the `.thread__details-replies` pill this story's badge copies its styling from; `ThreadHeader.vue`'s existing `:title`/`:aria-label` convention) rather than a screenshot — state this plainly, do not claim a visual check that did not happen.

### Testing standards summary

- `src/utils/__tests__/` (or the confirmed actual convention — check before creating) is the precedent for a new pure-function spec file (`threadState.spec.js`).
- `src/stores/__tests__/chatExtras.spec.js` (Stories 1.3/1.4) is the precedent for `updateThread()`/AC9 regression coverage.
- `src/store/messagesStore.spec.js` (referenced in Story 1.3/1.4's Dev Agent Records, including its known unrelated full-suite timeout flake) is the file to extend for Task 7's `processMessage()` coverage.
- Component-level changes (`ThreadItem.vue`, `ThreadHeader.vue`, `MessageBody.vue`) follow Stories 1.3/1.4's explicit, repeated precedent: verified via `npm run lint`/`ts:check` plus careful manual template/script review, **not** new component-mount test infrastructure — judged disproportionate for template/markup additions in this codebase, consistently, across every prior story in this epic. The new logic these components call (`ThreadStateBadge`'s own state→icon/label mapping is trivial enough to review by eye; the non-trivial parts — "who set it," the summary string, the relay fix — are pulled out into pure functions/store actions specifically so they *do* get real automated tests without needing mount infrastructure.

### Project Structure Notes

- `ThreadStateBadge.vue` follows the existing `src/components/RightSidebar/Threads/` co-location convention (sibling of `ThreadItem.vue`/`ThreadHeader.vue`/`threadsConstants.ts`), even though (per AC10's own warning about `ThreadHeader.vue`) it will be consumed from `MessageBody.vue` outside that directory — this mirrors `ThreadHeader.vue`'s own precedent exactly (filed under `RightSidebar/Threads/`, rendered in `TopBar.vue`/`ChatView.vue`), so it is not a new pattern.
- `src/utils/threadState.ts` follows the existing `src/utils/*.ts` flat-file convention (sibling of `message.ts`, `getDisplayName.ts`, `textParse.ts`).
- No conflicts with the unified project structure otherwise.

### Previous story intelligence (Stories 1.1–1.7)

- Every prior story in this epic found at least one real defect (`psalm` on the PHP side, or a genuine logic gap like this story's Task 7) that manual tracing alone would have missed on the first pass — Task 7's relay gap was found by tracing `processMessage()`'s actual branching, not assumed; expect similarly careful tracing to matter here too.
- Stories 1.2–1.4 consistently document interpretive assumptions inline, at the point of the decision, rather than silently resolving ambiguity — this story's two Assumptions (actor derivation, header quiet-Ongoing) follow that same discipline.
- Story 1.3's "no new component-mount test infrastructure, judged disproportionate" call is reused verbatim here for the same class of change (template/markup additions to `ThreadItem.vue`/`ThreadHeader.vue`) — this is now a three-times-repeated, consistent codebase judgment, not a one-off.
- Story 1.4 flagged the client relay-to-store wiring's `// FIXME: to be removed when chat relay provides thread data in original message` comment (in `chatExtras.ts::fetchSingleThread()`) as a known, pre-existing, partially-built mechanism, explicitly out of Story 1.4's scope. This story does **not** attempt to remove that FIXME or rebuild that mechanism — Task 7's fix is a different, narrower, additive fix (a new branch in `messagesStore.js` triggering an existing, already-correct `fetchSingleThread()` call) that closes this story's own AC8 gap without touching the FIXME'd code path at all.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story-1.8] — story statement and AC1-AC12, verbatim (lines 837-908).
- [Source: _bmad-output/planning-artifacts/epics.md line 341] — Story 2.4 AC15 inherits this story's AC11 row menu; confirms AC11's surface is the pre-Directory threads list, not the Followed Thread List.
- [Source: _bmad-output/planning-artifacts/epics.md line 36 (FR-8)] — "the four surfaces that already exist — the current threads list, the thread header, the Thread Root Message in the main chat, and the Followed Thread List."
- [Source: _bmad-output/planning-artifacts/epics.md line 320] — "FR-8 lands on the four surfaces that already exist... It does not wait for the Directory."
- [Source: .../ARCHITECTURE-SPINE.md#AD-9] — no per-Thread query per rendered row; the badge reads already-present fields, Task 7's fix is per-event not per-row.
- [Source: .../ARCHITECTURE-SPINE.md#AD-14, #AD-15] — six-registry system-message convention (already complete, this story only consumes it); single-store rule.
- [Source: .../ARCHITECTURE-SPINE.md#AD-18] — federation degrades by omission; no new gate needed.
- [Source: .../ARCHITECTURE-SPINE.md#Consistency-Conventions] — "state is never conveyed by colour alone."
- [Source: _bmad-output/implementation-artifacts/1-2-every-thread-reports-a-thread-state.md] — `state`/`STATE_ONGOING|CLOSED|LOCKED` on `TalkThread`.
- [Source: _bmad-output/implementation-artifacts/1-3-one-authority-rule-for-thread-management-on-the-server-and-in-the-client.md] — `canManage` on `TalkThreadInfo`; `chatExtrasStore.canManageThread()`; the reason `ThreadHeader.vue`/`ThreadItem.vue` no longer import Vuex's `useStore` for authority (does not preclude importing it for message history, Task 5).
- [Source: _bmad-output/implementation-artifacts/1-4-a-thread-manager-moves-a-thread-between-states-with-an-optional-reason-on-locking.md] — `lockReason`; `changeThreadState()`/`promptLockThreadReason()`; the four-transition `ThreadHeader.vue` menu this story's row menu (Task 4) copies; the explicit note that display work is Stories 1.8/2.4's scope, not 1.4's; the FIXME-flagged, explicitly out-of-scope relay-to-store gap (distinct from Task 7's gap).
- [Source: src/components/RightSidebar/Threads/ThreadItem.vue, ThreadHeader.vue, ThreadsTab.vue] — current state, read in full.
- [Source: src/components/LeftSidebar/LeftSidebar.vue] — confirms `ThreadItem` reuse for the Followed Thread List (AC4's surface).
- [Source: src/components/MessagesList/MessagesGroup/Message/MessagePart/MessageBody.vue] — Thread Root Message rendering (AC3's surface).
- [Source: src/store/messagesStore.js] — `processMessage()`, the single relay/poll entry point; the `isHiddenSystemMessage` branch vs. the generic `message.isThread` branch; Task 7's exact edit site.
- [Source: src/utils/message.ts] — `SYSTEM_MESSAGE_TYPE_HIDDEN`/`_UNTRANSLATED`, confirming the four lifecycle verbs' membership.
- [Source: src/stores/chatExtras.ts] — `updateThread()` (AC9's existing guard), `changeThreadState()`/`promptLockThreadReason()` (reused by Task 4).
- [Source: src/utils/getDisplayName.ts] — `getDisplayNameWithFallback()`, reused by Task 2's helper.
- [Source: src/components/TopBar/TopBar.vue:54, src/components/ChatView.vue:27] — the two `ThreadHeader.vue` render sites AC10 requires verification against.

## Dev Agent Record

### Agent Model Used

Claude Sonnet 5 (claude-sonnet-5), via bmad-dev-story

### Debug Log References

- `npx eslint <file>` run on every created/modified file individually (auto-fixed two import-order violations and one attribute-hyphenation violation via `--fix`, both re-verified clean afterward) — all clean. `npm run lint` (full project, plain `eslint`) — zero errors.
- `npm run ts:check` (`vue-tsc --noEmit`, full project) run after every task — zero errors throughout, including after the final task.
- `npx vitest run <file>` run per-file after every task (red before implementation, green after) for every file with new tests: `src/utils/__tests__/threadState.spec.js` (14/14), `src/store/messagesStore.spec.js` (87/87, including the 6 new AC8/AC9 cases), `src/stores/__tests__/chatExtras.spec.js` (25/25, including the 2 new AC9 cases).
- `npx vitest run` (full suite) — 1441 tests (1419 pre-existing + 22 new: 14 `threadState.spec.js` + 6 `messagesStore.spec.js` + 2 `chatExtras.spec.js`), 1438 passed, 3 failed — all three inside `messagesStore.spec.js`'s unrelated "eases list" test group (`Error: Test timed out in 5000ms`), the exact same full-suite resource-contention timeout flake Stories 1.3 and 1.4's Dev Agent Records documented for that identical file under identical concurrency conditions. Confirmed non-regressive by isolated re-run: `npx vitest run src/store/messagesStore.spec.js` alone — 87/87 passed.
- Independent fresh-context review of the draft story (before implementation began) found two real issues, both fixed before Task 1 started: (1) Task 6 was missing the explicit `components: {}` registration step `MessageBody.vue`'s Options API requires (verified live: temporarily removing the registration produces a `vue/no-undef-components` **warning**, not an error, confirmed invisible to `npm run lint` — exactly the silent-failure risk the reviewer flagged); (2) Task 4.4's "identical call shape" claim for the row-menu Lock action included an `if (reason !== undefined)` guard that `ThreadHeader.vue`'s actual `lockThread()` does not have — corrected to the real, unconditional shape.
- No PHP file was touched by this story (confirmed via `git status --porcelain -- '*.php'`, cross-checked against this session's own edit history) — matches the story's Dev Notes prediction ("Why this story should not need a backend change"). No HALT triggered.
- No component-mount test infrastructure was introduced for `ThreadItem.vue`/`ThreadHeader.vue`/`MessageBody.vue`'s template/markup changes, per the explicit, three-times-repeated precedent in Stories 1.3/1.4's Dev Notes (judged disproportionate for this class of change in this codebase) — verified instead via `npm run lint` + `npm run ts:check` + careful manual template/script review, and via a live before/after lint check proving the one genuine silent-failure risk (missing Options API component registration) is caught.
- No browser is available in this environment; visual/UX correctness (badge placement, header line wrapping at `TopBar.vue`/`ChatView.vue`'s two widths) was verified by reading the existing `.description`/`.thread__details` CSS this story's markup reuses, and by reasoning about the existing `NcListItem`/`NcActions` component contracts — not by a screenshot. Stated plainly, not claimed as executed.

### Completion Notes List

- **AC1/AC4 (list-row badge, threads list + Followed Thread List):** `ThreadStateBadge.vue` (new) renders nothing for Ongoing, an icon+label pill for Closed/Locked. Added to `ThreadItem.vue`'s `#details` slot — the one component both `ThreadsTab.vue` (AC1's "threads list") and `LeftSidebar.vue` (AC4's Followed Thread List) already render per Thread, so one change covers both surfaces.
- **AC2 (header: state, who set it, reason):** `ThreadHeader.vue` gains a new `.description--state` line, shown only for Closed/Locked (Ongoing stays quiet, matching AC1's own stated rationale — documented as an explicit Assumption since AC2's text has no literal carve-out). "Who set it" is derived from the Thread's already-loaded message history (`store.getters.messagesList`), not a new persisted field — documented as the other explicit Assumption, since no story before this one persisted a state-change actor and Story 1.4 AC10's "no history walk" guarantee was scoped to the lock *reason* only, not the actor. Both derivations live in new pure, unit-tested helpers (`src/utils/threadState.ts`) rather than inline in the component.
- **AC3 (Thread Root Message in main chat):** `MessageBody.vue` gains a `threadState` computed and renders `ThreadStateBadge` alongside the existing reply-count button. Required an explicit component registration in the `components: {}` object (Options API file, unlike the other two `<script setup>` components this story touches) — flagged by independent review before implementation and verified live (see Debug Log).
- **AC5 (Closed ≠ shut):** the badge's `:title`/`:aria-label` on Closed states `'Closed — posting a new message reopens this thread'`, following the existing `NcButton`-with-both-attributes convention already in this codebase; no new tooltip mechanism introduced (`v-tooltip`/`NcTooltip` have zero prior usage in `src/`).
- **AC6 (not colour alone, themed):** every non-Ongoing state renders an icon *and* a text label; the badge's pill styling reuses `ThreadItem.vue`'s existing `.thread__details-replies` design tokens rather than introducing new colour values.
- **AC7 (translatable, Thread Title never translated):** every new user-visible string uses `t()`/`n()`; no Thread Title string is ever passed through a translation function anywhere in this change.
- **AC8/AC9 (live propagation, no half-populated entries):** AC9 needed no new code — `chatExtrasStore.updateThread()` already re-fetches rather than partially writing an unknown Thread; added a regression test proving it (`chatExtras.spec.js`). AC8 had a real, fixable, in-scope gap (distinct from Story 1.4's documented, explicitly out-of-scope AC16 wiring gap): the four lifecycle system messages fall through `messagesStore.js`'s generic "Update threads" block, which never read `state`/`lockReason`. Fixed with a new branch that re-fetches an already-known Thread on any of the four lifecycle verbs (`THREAD_LIFECYCLE_SYSTEM_TYPES`), benefiting both the signalling relay and the REST poll fallback since `processMessage()` is the single entry point for both. One request per live transition per open Conversation, not per rendered row — does not engage AD-9.
- **AC10 (verify in TopBar.vue/ChatView.vue, not the sidebar):** the new header line reuses `.description`'s existing `overflow: hidden; text-overflow: ellipsis;` rule via a `--state` modifier (`display: flex; align-items: center; gap`), with no `standalone`-specific branch — the same markup serves both `TopBar.vue:54` and `ChatView.vue:27`.
- **AC11/AC12 (row management menu, scoped to the current threads list):** `ThreadItem.vue` gains an opt-in `showManagementActions` prop (default `false`) and up to two new `NcActionButton`s per render, mirroring `ThreadHeader.vue`'s existing four-transition mapping exactly. Only `ThreadsTab.vue` passes `showManagementActions`; `LeftSidebar.vue`'s Followed Thread List usage is unchanged (confirmed via grep — exactly one `true`-passing call site). AC12's "updates in place, no navigation" holds by construction (`changeThreadState()` already replaces the store entry the row reads from reactively); AC12's Ongoing-filter clause is not yet exercisable (`ThreadsTab.vue` has no state filter — Epic 2 Story 2.3's scope), documented rather than fabricated.
- **No HALT condition was triggered.** Two ambiguities were resolved by direct engineering judgment and documented in the story's Dev Notes at story-creation time ("who set it" derivation source; Ongoing quiet-header principle), consistent with every prior story's discipline in this epic. An independent fresh-context review before implementation found two real, non-blocking gaps in the story's own task text (not the codebase) — both corrected before Task 1 began (see Debug Log).

### File List

**Created:**
- `src/components/RightSidebar/Threads/ThreadStateBadge.vue` — shared, accessible state indicator (AC1, AC3, AC4, AC5, AC6, AC7).
- `src/utils/threadState.ts` — `getThreadStateActor()`/`getThreadStateSummary()`, pure helpers for AC2's "who set it" and reason display.
- `src/utils/__tests__/threadState.spec.js` — 14 new tests for the two helpers above.

**Modified:**
- `src/components/RightSidebar/Threads/ThreadItem.vue` — state badge in `#details` (AC1, AC4); new `showManagementActions` prop and four-transition row menu (AC11, AC12).
- `src/components/RightSidebar/Threads/ThreadsTab.vue` — passes `showManagementActions` to `ThreadItem` (AC11).
- `src/components/RightSidebar/Threads/ThreadHeader.vue` — state/who-set-it/reason line, sourced from message history via `threadState.ts` (AC2, AC10).
- `src/components/MessagesList/MessagesGroup/Message/MessagePart/MessageBody.vue` — `threadState` computed, `ThreadStateBadge` alongside the reply-count button, explicit Options API component registration (AC3).
- `src/store/messagesStore.js` — new `THREAD_LIFECYCLE_SYSTEM_TYPES` constant and a new branch in `processMessage()`'s "Update threads" block that re-fetches an already-known Thread on a lifecycle system message (AC8).
- `src/store/messagesStore.spec.js` — new `describe('thread lifecycle relay (Story 1.8, AC8/AC9)')` block: 6 new tests.
- `src/stores/__tests__/chatExtras.spec.js` — new `describe('updateThread for a Thread the store has never loaded (Story 1.8, AC9)')` block: 2 new tests.

## Change Log

- 2026-08-12 — Story implemented end-to-end (Tasks 1-8). AC1/AC4: one shared `ThreadStateBadge.vue` covers both list-row surfaces via the shared `ThreadItem.vue` component. AC2: header shows state/who-set-it/reason for Closed/Locked (Ongoing stays quiet, per a documented assumption); "who set it" derived from already-loaded message history via new pure, unit-tested helpers (`src/utils/threadState.ts`), not a new persisted field. AC3: Thread Root Message in the main chat gains the same badge, requiring an explicit Options API component registration flagged by an independent pre-implementation review. AC5/AC6/AC7: Closed's tooltip conveys posting reopens it; every non-Ongoing state carries icon+label on reused design tokens; every new string is translatable. AC8/AC9: a real (not pre-existing/out-of-scope) relay gap was found and fixed in `messagesStore.js`'s generic thread-update path, benefiting both the signalling relay and REST poll fallback; AC9's existing guard in `updateThread()` was confirmed correct and given regression coverage. AC10: verified against both `TopBar.vue` and `ChatView.vue` render sites, no sidebar-specific code path. AC11/AC12: row-level four-transition menu added to `ThreadItem.vue`, scoped via a new opt-in prop to `ThreadsTab.vue` only (not the Followed Thread List), mirroring `ThreadHeader.vue`'s existing transition logic exactly. 22 new tests added (14 `threadState.spec.js`, 6 `messagesStore.spec.js`, 2 `chatExtras.spec.js`), all genuinely executed via `npx vitest run` (not just written), following red-green TDD for every case with production logic. `npm run lint` and `npm run ts:check` both clean across the full project. Full-suite Vitest run showed the same pre-existing, confirmed-non-regressive `messagesStore.spec.js` timeout flake Stories 1.3/1.4 documented (isolated re-run: 87/87 passed). No PHP file touched — this story needed no backend change, as its Dev Notes predicted. No HALT condition triggered. Status set to `review`.
