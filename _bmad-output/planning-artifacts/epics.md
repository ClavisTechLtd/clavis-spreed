---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - _bmad-output/planning-artifacts/prds/prd-clavis-spreed-2026-08-08/prd.md
  - _bmad-output/planning-artifacts/prds/prd-clavis-spreed-2026-08-08/addendum.md
  - _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md
  - _bmad-output/specs/spec-clavis-spreed/SPEC.md
  - _bmad-output/specs/spec-clavis-spreed/traceability.md
---

# clavis-spreed — Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for clavis-spreed, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

Scope is **Clavis Talk — Thread Management and Navigation**: thread lifecycle, management authority, Thread Directory, search, featuring, tags, per-Thread unread, thread-aware notifications and navigation. Server (PHP) plus web client. Federation and the mobile clients are out of scope.

Vocabulary is fixed by PRD §3 Glossary and is binding — **Thread**, **Thread Root Message**, **Thread Title**, **Thread State** (Ongoing / Closed / Locked), **Thread Manager**, **Thread Directory**, **Followed Thread List**, **Thread Tag**, **Featured Thread**, **Thread Unread Count**, **Thread Read Marker**, **Conversation**, **Thread Subscription**. A synonym anywhere is a defect. In particular: *Featured*, never "pinned"; *Thread Tag*, never "label".

## Requirements Inventory

### Functional Requirements

Source: `prd.md` §4. Numbering is global and stable; downstream stories reference these identifiers verbatim.

**§4.1 Thread Lifecycle**

- **FR-1:** Every Thread has a Thread State of Ongoing, Closed or Locked, defaulting to Ongoing; the state appears on every API endpoint returning a Thread, and an out-of-range value is refused with a validation error naming the field.
- **FR-2:** A Thread Manager can set an Ongoing Thread to Closed, recorded by a system message; a non-manager is refused; closing an already-Closed Thread is idempotent; concurrent state changes resolve last-write-wins with the loser's client corrected.
- **FR-3:** Any participant who may post in the Conversation can post into a Closed Thread, and doing so returns it to Ongoing — silently, with no state system message — across all nine content paths of FR-5. A Locked Thread does not reopen this way.
- **FR-4:** A Thread Manager can set a Thread to Locked from either Ongoing or Closed, recorded by a system message; a non-manager is refused.
- **FR-5:** While Locked, a Thread refuses every write from all nine enumerated content paths — typed message, bot API message, in-process bot answer, rich object share, poll, file share, attachment upload, message scheduled while Locked, and message scheduled before locking (which fails at fire time with a visible reason) — plus edits, deletes, pins and reactions to existing messages. Each path is its own testable scenario. Refusals fail visibly and are distinguishable from permission and not-found failures. Only the state-change system messages of FR-4 and FR-7 are exempt.
- **FR-6:** A Thread Manager can return a Closed Thread to Ongoing without posting, recorded by a system message; the Thread Root Message author qualifies without being a moderator.
- **FR-7:** A Thread Manager can return a Locked Thread to Ongoing, recorded by a system message, after which all nine write paths accept again; a non-manager is refused, including by posting.
- **FR-8:** Thread State is shown on every surface representing a Thread — Directory row, thread header (naming who set it), Thread Root Message in the main chat, Followed Thread List row. Ongoing shows no badge. The Closed indicator conveys that posting is still permitted and reopens the Thread.
- **FR-43:** A Thread Manager may attach an optional free-text reason when Locking. The reason rides the locking system message, appears in the thread header, in the composer's refusal explanation and in FR-34's notification; it is withheld exactly where a Thread Title is withheld; re-locking replaces it; whitespace-only input is treated as no reason and the length bound is stated before it is hit.

**§4.2 Thread Management Authority**

- **FR-9:** Only a Thread Manager — the Thread Root Message author, or any moderator of the Conversation — may change Thread State, feature or unfeature a Thread, or add or remove Thread Tags. Guests are refused regardless. Authority is evaluated per request.
- **FR-10:** Management controls appear only for actors who may use them — absent, not disabled — evaluated per Thread within the same list.
- **FR-11:** Renaming a Thread continues to follow the same Thread Manager rule it follows today, verified by the existing integration coverage. (Regression guard, not new behaviour.)

**§4.3 Thread Directory**

- **FR-12:** The Thread Directory is reachable in one click from the Conversation top bar, is registered as a **peer** of the Conversation's other sidebar surfaces (not a replacement for the tab set), works on narrow and mobile viewports, and removes the thread list and its three-item preview from Shared items. Opening it does not change which Thread is open.
- **FR-13:** Featured Threads sort above all unfeatured Threads regardless of state or activity; within each group, most recent activity first; a new reply re-sorts live without a refresh.
- **FR-14:** The Directory filters by Thread State, defaulting to Ongoing; Featured Threads appear under any filter with their state shown; hidden Threads are signalled as hidden rather than absent; the filter persists within a visit and resets to Ongoing on return.
- **FR-15:** The Directory filters by Thread Tag; tag and state filters combine; only tags in use in this Conversation are offered; clearing the tag filter restores the list without a reload.
- **FR-16:** Each Directory row carries Thread Title, Thread State, Thread Tags, reply count, last activity and Thread Unread Count. No unread means no indicator. A long title truncates without displacing the other fields, and five tags render without displacing them either.
- **FR-17:** The Directory loads a bounded first page and fetches more on scroll; a single-page Conversation shows no pagination affordance; filtering and searching apply across all Threads in the Conversation, not only those loaded.

**§4.4 Thread Search**

- **FR-18:** A participant can search the current Conversation's Threads by Thread Title — case-insensitive, substring, and **diacritic-insensitive for Vietnamese** (`dinh dang` finds `Định dạng`). Search covers all Thread States and results show state. Results appear as the participant types.
- **FR-19:** A control to hand the same term to the existing message search is present for zero, one or many title results; taking it opens the message search with the term applied without losing the participant's place; an empty result states the term and makes that control prominent.
- **FR-20:** The cross-Conversation Followed Thread List is searchable by Thread Title under the same matching rules, and each result identifies its Conversation.

**§4.5 Featured Threads**

- **FR-21:** A Thread Manager can feature and unfeature a Thread; the effect is visible to every participant in the Conversation; unfeaturing returns it to activity ordering and to the default filter's hiding rules; featuring never changes Thread State and works on Closed and Locked Threads; a non-manager is refused.
- **FR-22:** Featured Threads per Conversation are bounded at a server-side constant (five); exceeding it is refused with an error naming the limit, and the limit is stated in the interface before it is hit.

**§4.6 Thread Tags**

- **FR-23:** A Thread Manager can add and remove Thread Tags; tags are visible to all participants and only editing is restricted; removing a tag from one Thread does not affect others; bounded at **five tags per Thread** and **twenty distinct tags per Conversation**, each refusal naming the limit.
- **FR-24:** Within one Conversation the same tag text always renders in the same colour: first use sets it from a defined palette, later uses inherit it without asking, identity is case-insensitive, the same text in another Conversation is independent, and arbitrary colour input is not accepted.
- **FR-25:** A Thread Manager can change the colour bound to a tag text for the Conversation; the change applies to every Thread carrying that tag and propagates to other participants without a reload; a non-manager is refused.
- **FR-26:** Tag input offers the Conversation's existing tags with their colours as the participant types, marks input that would create a new tag as visually distinct before saving, applies an existing tag without asking for a colour, strips surrounding whitespace and refuses empty input.

**§4.7 Thread Unread State**

- **FR-27:** Every Thread returned to a participant carries their Thread Unread Count. Fully read reports zero; own messages and system messages are excluded; two participants who have read different amounts get different counts; a Thread never opened derives its count from the participant's Conversation read marker, so pre-join activity reports zero.
- **FR-28:** Thread unread state is independent per Thread: reading Thread A leaves Thread B unchanged, reading the main chat leaves a previously-read Thread's count unchanged, reading a Thread does not change the Conversation's main-chat count, a count returns to zero only on reading that Thread to its end or marking it read, and a participant who leaves and rejoins inherits no stale per-Thread state.
- **FR-29:** A participant can mark one Thread read from its Directory row without opening it, and can mark every Thread in the Conversation read — **including Threads hidden by the active filter**, so a badge raised by a Closed Thread is always clearable. Marking read changes no Thread State and is invisible to other participants.
- **FR-30:** The Conversation's unread message count covers main-chat messages only and excludes Thread replies; the Conversation's mention indication no longer depends on that count being non-zero, so a participant mentioned only inside a Thread is still reported as mentioned. Reading a Thread does not decrease the Conversation count. The change is announced through its own capability, separate from the thread-management capability.
- **FR-31:** A Conversation reports, separately from its main-chat unread count, whether it contains any Thread with unread messages, distinguishing unread replies from unread mentions. The indication is raised by filter-hidden Threads too, FR-29's mark-all is the guaranteed way to clear it, and the Conversation list surfaces it without duplicating or contradicting the main-chat badge.
- **FR-32:** Thread Unread Count is displayed in both the Thread Directory and the Followed Thread List; no unread means no indicator; counts update live and clear as the participant reads; a count above the display bound renders as a capped indicator; unread mentions are distinguished from unread replies; opening a Thread with unread messages positions at the first unread message with a stable boundary marker, and leaving part-read preserves that position.

**§4.8 Thread-Aware Notifications**

- **FR-33:** Every notification generated by in-thread activity identifies the Thread by its Thread Title, across all **nine** notification subjects (plain message to a follower, reply, direct mention, group mention, team mention, all mention, reaction, reminder), with a test per subject. Non-thread notifications are unchanged. Over-long titles truncate without erasing the message preview. In a sensitive Conversation the Thread Title is withheld along with the other content already withheld.
- **FR-34:** A participant subscribed to a Thread is notified when it is Closed, Locked or reopened, naming the Thread, the actor and FR-43's reason where one exists; the notification respects the participant's per-Thread notification level; non-subscribers are not notified; no participant learns a Thread is Locked only by failing to post; reopen-by-reply produces only the ordinary reply notification.
- **FR-35:** A push payload for in-thread activity carries both the message identifier and the Thread identifier in the same composed form the in-app notification uses; a push payload for non-thread activity carries the message identifier (which it does not today); the in-app identifiers are unchanged and the existing integration assertion keeps passing; positionally-parsing shipped clients keep working and must not misread the shorter absent-thread value; a sensitive Conversation's payload carries identifiers but no Thread Title.
- **FR-36:** Acting on a notification about a Thread opens that Thread with the relevant message in view; non-thread notifications open the main chat as today; a Locked Thread opens with its state shown and the composer disabled; a deleted Thread lands in the main chat with an explanation rather than an error page.
- **FR-37:** Adding the Thread Title does not push a push payload past what the transport accepts. When both cannot fit, the Thread Title is preserved and the message preview is shortened. Truncation is applied on character boundaries valid for Vietnamese text.

**§4.9 Thread Navigation Entry Points**

- **FR-38:** A control in the Conversation top bar opens the Thread Directory; it is present only where the server supports thread management; it indicates unread Threads; it is keyboard reachable and announced with its purpose and unread state.
- **FR-39:** While a Thread is open, its header offers a route to the Directory alongside the existing route back to the main chat, and taking it leaves the Thread open behind the Directory.
- **FR-40:** The Thread Root Message in the main chat offers a distinct route that opens the Directory positioned on this Thread's row, leaving its existing route into the Thread unchanged.
- **FR-41:** A Thread and the Thread Directory each have a copyable, shareable, returnable address; a message link in a Thread keeps opening that Thread at that message; reloading with a Thread open restores that Thread; browser back and forward move between main chat, Thread and Directory in visit order.
- **FR-42:** Thread and Directory addresses resolve wherever Talk renders a Conversation, including the Files sidebar, which uses a **separate router instance**. A surface that cannot host the Directory omits its entry point cleanly rather than offering a control that fails.

### NonFunctional Requirements

Source: `prd.md` §5. Identifiers assigned here for story reference.

- **NFR-1 — Scale is the design target.** Every list surface stays usable in a Conversation with **≥1000 Threads and 200 participants**. Adopted as a deliberate target, not an observation; the prompting room holds ~180 Threads, so it is a margin that costs nothing.
- **NFR-2 — No unbounded work per request.** Returning a page of Threads must not cost work proportional to the Conversation's total Thread count. Thread Unread Count is the most likely place a per-Thread query creeps in.
- **NFR-3 — Per-participant thread state must not grow without bound.** Thread Read Marker growth tracks Threads a participant has *read*, never participants × Threads, and rows are reclaimed when a participant leaves a Conversation.
- **NFR-4 — Cached Thread data must not go stale.** Thread data is served from a distributed cache with a **900s** lifetime and negative caching. Every mutation invalidates, or a state change appears to succeed and then reverts for other participants for up to fifteen minutes. Highest-risk correctness surface in the work.
- **NFR-5 — Concurrent management resolves predictably.** Two Thread Managers acting on the same Thread never produce a state neither chose: the later write wins and the earlier actor's client is corrected. Applies to state, featuring and tags alike.
- **NFR-6 — Migration is safe on live customer data.** Customers self-host and upgrade in place. Schema changes apply to a Conversation with a large Thread history without a maintenance window and are safe to run twice.
- **NFR-7 — The interface survives missing capability.** Every new surface and changed field is gated on a declared capability and degrades to current behaviour rather than erroring, including against a server whose unread semantics differ (FR-30).
- **NFR-8 — Accessibility.** New controls and list surfaces are keyboard reachable and screen-reader announced, and state is conveyed by more than colour — which matters specifically for Thread Tags, where colour is the point. A tag's text always accompanies its colour.
- **NFR-9 — Theme.** Tag palette and state indicators are legible in both light and dark themes at normal and high contrast.
- **NFR-10 — Localisation.** All new user-visible text is translatable. Thread Titles and Thread Tags are user content and are never translated. Vietnamese is the primary customer language and must be correct in search matching, truncation and sorting.
- **NFR-11 — Real-time consistency.** State, featuring and tag changes reach other participants viewing the same Conversation without a reload, over the signalling path Talk already uses for thread events.

### Additional Requirements

Source: `ARCHITECTURE-SPINE.md` (AD-1 … AD-20), `SPEC.md` Constraints, `addendum.md`. These are binding technical requirements that shape epic and story boundaries.

**Starter template: none.** This is a **brownfield fork**, not a greenfield build. `clavis-spreed` tracks upstream Nextcloud Talk `stable34` / Talk 24.0.3 at commit `6819859`. Threads already exist upstream — creation, replies, renaming, per-thread notification levels, a recent-threads list and a followed-threads list all ship — and this work extends that machinery rather than replacing it.

**Prerequisites before the first commit**

- `git fetch --unshallow` — the clone is depth-1 today (`git rev-list --count HEAD` returns 1) and rebasing onto upstream releases is impossible until history is restored. Prerequisite of the work, not part of it (AD-17).
- A **preparatory commit** normalising the Thread row-key conventions: `Thread::createFromRow()` reads a bare/`t_`-style row while the aliased `SelectHelper::selectThreadsTable()` branch emits `th_*`. A new field added correctly everywhere still fails to load on one path until the two are reconciled, and a checklist spanning two conventions cannot be verified by reading the diff (AD-1).
- An **AC-2 response-time baseline** captured on today's nested list against the seeded fixture, **before the first Directory change ships**. Without the pre-work capture, AC-2 cannot fail — so capturing it is a task in the first Directory story, not a later measurement (AD-9).
- A **seeded fixture** built to the NFR-1 scale target (1000 Threads, 200 participants, activity spread across a year) is an **engineering deliverable**, not a test convenience. AC-2 and AC-5 have no other home; it is built once and kept.

**Write seams and enforcement**

- `ThreadService` is the **sole writer** of `talk_threads` and `talk_thread_attendees` and of the Thread cache. Tag tables are a **separate aggregate** owned solely by `ThreadTagService`; neither service writes the other's rows. No controller, listener or job reaches a mapper directly (AD-1).
- **Tag mutations still invalidate the Thread cache, and the seam is named:** Thread Tags render on the cached `TalkThreadInfo`, so adding, removing or recolouring a tag makes a cached Thread stale even though no thread row was written. `ThreadTagService` therefore calls an explicit invalidation method on `ThreadService` — the cache keeps one owner, and the tag aggregate never touches the `thread/{roomId}/{threadId}` key itself. A recolour fans out to every Thread carrying that tag, bounded rather than sweeping the Conversation. Without this, AD-1's aggregate separation reads as a licence for the tag service to skip invalidation entirely, which is the 900s staleness NFR-4 exists to prevent (AD-1, AD-10, NFR-4).
- Every mutator ends by **invalidating** cache key `thread/{roomId}/{threadId}` with `remove()`, never `set()`; only `findByThreadId()` repopulates. Re-setting from a held entity republishes a superseded value and pins it for the full 900s TTL (AD-1, NFR-4).
- Every new Thread field is added, in the same change, to `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` and `SelectHelper::selectThreadsTable()` (both branches). A field missing from the JSON pair vanishes on a cache round trip; one missing from `SelectHelper` is absent from every joined read (AD-1).
- Locked refusal and Closed→Ongoing revival are applied **inside `ChatManager::sendMessage()` and `ChatManager::addSystemMessage()`**, never in controllers, listeners or jobs — covering eight of the nine paths. The guard reads one expression, resolved once through a shared helper, **before** `commentsManager->save()`. `sendMessage()`'s explicit-`threadId` branch gains the `validateThread()` call the reply branch already performs (AD-2).
- A **third enforcement seam** is mandatory and owned by the lifecycle epic: reactions, edits, deletes and pins reach `commentsManager->save()` by other routes (`ReactionManager` and `ChatManager`'s own edit/delete/pin methods), so shipping only the two ChatManager choke points leaves a Locked Thread editable and deletable while reporting itself locked (AD-2).
- `ChatController::postAttachmentToRoom()` gains the `validateThread()` call it does not perform today, as a precondition of the guard being meaningful there (AD-2).
- Scheduling a message writes no comment, so it is validated at request time **and again** when it fires (AD-2).
- The Locked exemption is an explicit allow-list of the state-change verbs of AD-14 and nothing else. "System messages are exempt" is forbidden framing — four of the nine paths post their content *as* system messages (AD-2).
- Authority is exactly one method, `ThreadService::isThreadManager(Thread, Participant): bool`, lifted from the expression inline in `ThreadController::renameThread()`; every state, featuring, tag and rename endpoint calls it and none re-derives it. Its root-comment lookup takes the low-level `CommentsManager`, **not** `ChatManager` — the reverse injection is a container cycle. Degradation is preserved: if the root comment cannot be loaded, only moderators qualify (AD-3).
- Refusals carry **three distinct exception classes and OCS error identifiers**: Thread is Locked, actor lacks authority, Thread does not exist. Locked is not folded into the existing `['error' => 'permission']` value (AD-4).

**Data, migration and lifetime**

- Migrations are **additive, defaulted, re-runnable and backfill nothing**. No table-wide `UPDATE` at upgrade time. Every pre-existing Thread reads as Ongoing, unfeatured and untagged — the accepted day-one limitation of PRD §9.3 (AD-19, NFR-6).
- Schema delta: `talk_threads` **+** state (integer, default Ongoing), featured (boolean), normalised name, nullable bounded lock reason. `talk_thread_tags` **new** (room_id, name, normalised name, colour; unique on room + normalised name). `talk_thread_tag_map` **new** (thread_id, tag_id, room_id). `talk_thread_attendees` **+** `last_read_message` and `last_mention_message` reinstated — **two columns, not three**; `last_mention_direct` stays dropped. `talk_attendees` **+** a nullable thread-read baseline column.
- The migration reinstating the dropped `talk_thread_attendees` columns carries a comment stating **why** they are back, so whoever merges the next upstream release does not read them as an accident (AD-17).
- A `talk_thread_attendees` row is written **only when a participant reads that Thread**. A Thread with no row resolves unread against the nullable **thread-read baseline** on `talk_attendees`, which is never backfilled and is frozen lazily at **one named seam**: inside `ParticipantService::updateLastReadMessage()`, on **any** advance (that method has six callers, two of them posts), freezing the **pre-advance** value while still `NULL`. The sole exemption is the `UNREAD_MIGRATION` repair in `RoomFormatter`. Thereafter the baseline moves only via mark-all-threads-read, never by reading the main chat (AD-6).
- Mark-all-threads-read **resets** the read columns on existing rows and **never deletes** them: subscription lives on the same row, so deleting a row to clear a badge would silently unfollow the Thread (AD-6, AD-7).
- Subscription gets its **own explicit column** on `talk_thread_attendees`, never inferred from row existence or from a `notification_level` value, shipping **`NOT NULL DEFAULT true`** — every pre-existing row was created by a reply or a level change, and defaulting to `false` would unsubscribe every existing follower at upgrade while AD-19 forbids the repair `UPDATE`. `getRecentByActor()` gains that predicate **in the same change** that introduces read-created rows, not afterwards; `findAttendeesForNotification()` keeps its own filter. `threads.feature:224` is the regression guard for the reply-created case; a new scenario asserts the read-created case does not enrol (AD-7).
- Thread lifetime is bound to the root comment row and the two deletion kinds differ: author/moderator deletion **tombstones** and the Thread survives with id, state, tags, featuring and replies; message **expiry** hard-deletes, so a **bounded background reaper** removes orphaned `talk_threads` and `talk_thread_attendees` rows and **invalidates each reaped Thread's cache entry in the same pass**. Without that, `validateThread()` answers from a warm entry for up to 900s and content is accepted into a Thread that no longer exists. Every read path tolerates a missing root (AD-5).

**Unread split**

- `unreadMessages` counts non-thread messages only, through a **new counting override** in Talk's `CommentsManager` mirroring the `topmost_parent_id` filter its existing *read* override already applies (AD-8).
- The `unreadMessages !== 0` gate on `unreadMention` / `unreadMentionDirect` in `RoomFormatter` is removed, **and so is the `lastReadMessage === lastMessage` short-circuit immediately above it**, which becomes wrong once the last message may be a thread reply (AD-8).
- The unread-count cache key gains a semantics token, **appended** — six sites invalidate that cache by room-id *prefix*, so prepending silently disables all six and leaves counts wrong for the full 1800s TTL (AD-8).
- **Posting a thread reply must stop advancing the conversation read marker.** It does today; once the count excludes thread replies, that advance silently marks unread main-chat messages read as well. A thread reply advances that Thread's own marker instead (AD-8).
- Conversation-level thread unread is surfaced through the already-migrated, currently-uncalled `talk_attendees.has_unread_thread*` columns — **two of the three**; `has_unread_thread_directs` stays uncalled under the two-way split. Writing their callers is the whole of that storage work; no migration is needed (AD-8).

**Query, ordering and caps**

- Unread for a page of Threads resolves in a **fixed number of queries per page**, independent of page size — one grouped query over the page's thread ids — and counting stops at the display cap, not per row (AD-9, NFR-2).
- Paginated results carry a **total order** — featured first, then `last_activity` descending, then thread id descending as tie-break — and pages are addressed by a **keyset cursor on that tuple, never an offset**. `last_activity` moves while a participant pages, so offset paging repeats one row and silently drops another. Changing a filter discards the cursor (AD-9).
- Filtering, ordering, searching and paging all happen **in the query**, not the client store. The **existing client-side sort in the store is removed** as part of this work; the client keeps the server's order as an ordered id list and never re-sorts (AD-9, AD-12).
- Every cap — five tags per Thread, twenty tags per Conversation, Featured Threads per Conversation — is **one server-side constant**, enforced **where the write happens**, and **published to clients in the capability payload** rather than restated in the interface. A sort cannot enforce a count and a disabled button is not enforcement (AD-9).
- The unread **display** cap is different in kind: counting stops at **cap + 1**, so a client can tell a full page from an overflowing one and render the "99+" form (AD-9).

**Tags and text matching**

- Two new tables; `ConversationTagService` is **copied, not extended** — every one of its methods is keyed by `userId` and Thread Tags are shared within the Conversation. Copy its exception vocabulary (`TagNameAlreadyInUseException`, `InvalidTagNameException`, `TagLimitExceededException`) and its `MAX_TAG_IDS_PER_CONVERSATION = 20`. Do **not** copy its `normalizeTagName()` — it is `private` and only trims and length-checks, producing exactly the case-and-diacritic duplicates this forbids (AD-10).
- Colour is a **bounded palette stored as an index**, not a free-form hex value: a free hex column cannot meet contrast in both themes, cannot follow customer theming, and turns every new theme into a data migration (AD-10, NFR-8, NFR-9).
- FR-25 (recolour) is the **only `updateTag`-shaped operation in v1**; renaming, merging and deleting a tag across Threads stay deferred to v2 (AD-10).
- **One shared normalisation helper** (trim, case-fold, diacritic-fold) writes a stored normalised column for both `talk_threads.name` and tag names, at create and at rename; queries match the normalised column. Nothing relies on collation and nothing folds at the call site. This binds **both** list surfaces — FR-20's title search happens inside the Followed Thread List's own hand-built query, not in the client store (AD-11).

**API, capabilities and clients**

- The existing `GET .../threads/recent` **gains optional state, tag and pagination parameters** whose defaults reproduce today's response; it is extended, not duplicated. New endpoints appear only for operations that do not exist: state change, featuring, tagging, mark-read (AD-12).
- No field is removed or renamed. AD-8's unread redefinition is the **one recorded exception** and nothing else may cite it as precedent (AD-12, SPEC Constraints).
- The composed notification object identifier is **variable-arity and absence-significant**: a trailing position may be appended, never reordered or shortened, never padded, never a sentinel, and `0` is never valid at any position. A new position may only follow the thread position and only on notifications that already carry it (AD-12).
- **Two** new capability flags — one for thread management, one for the unread semantics — both in **`FEATURES` *and* `LOCAL_FEATURES`**. The shipped unconditional `threads` flag is reused for neither. `features-local` is the only channel through which a client learns a feature does not work over federation, so a `FEATURES`-only declaration makes AD-18 unimplementable (AD-12, AD-18).
- There is exactly **one Thread representation, `TalkThreadInfo`** — never the bare `TalkThread`. The client store indexes the nested id. Every mutation responds with that same full representation from the same builder — no trimmed variant, no enriched variant (AD-12).
- `openapi*.json` and the generated `src/types/openapi/*.ts` are regenerated **in the same change** as the endpoint, not as a follow-up (AD-12).
- Concurrency is **last-write-wins with no token and no conflict response**; the client replaces its local copy with **the response**, never with what it sent; other participants converge through the relayed state-change system message (AD-13, NFR-5).
- Federation **degrades by omission**: no new Thread field is proxied, no new Thread endpoint is exposed through `lib/Federation/Proxy/`, and the client **hides** state, featuring, tag and per-Thread-unread affordances on a federated Conversation via `features-local` rather than rendering inert ones (AD-18).

**System messages and real-time propagation**

- Each lifecycle transition has exactly one system-message constant, and shipping it means touching **all six registries in the same change**: (1) `src/constants.ts`; (2) `SYSTEM_MESSAGE_TYPE_RELAY` in `src/utils/message.ts`; (3) `SYSTEM_MESSAGE_TYPE_UNTRANSLATED`; (4) `SYSTEM_MESSAGE_TYPE_HIDDEN`; (5) `lib/Signaling/Listener.php` — **four separate verb checks in that one file**, being `SYSTEM_MESSAGE_TYPE_RELAY`, the inline early-return array in `notifySystemMessageSent()`, the `$thread` lookup branch and the `threadInfo` payload branch; (6) the parser in `lib/Chat/Parser/SystemMessage.php` (AD-14).
- The first two of those four checks decide whether a message relays **at all**; the last two decide whether it arrives **carrying its Thread**. Naming a verb in the first two only yields a live update with no thread context — a failure that looks like a rendering bug (AD-14).
- The four verb checks in `lib/Signaling/Listener.php` are **extracted into named constants declared together**, each site testing membership instead of an inline literal, so adding a verb means editing one list (AD-14).
- FR-43's reason travels as **message parameter data, never concatenated into rendered message text**; the parser renders it, the notification builder reads the same parameter rather than re-parsing a rendered string, and the **current** reason is also written to `talk_threads` so the thread header needs no history walk. Re-locking replaces the column and leaves the earlier system message intact (AD-14).
- The relay set is a **superset** of AD-2's exemption list; the lifecycle-transition subset **is** that exemption list, so the two cannot drift. A tag recolour (FR-25) joins the relay set without joining the exemption (AD-14, AD-10).

**Client architecture and routing**

- `useChatExtrasStore` (Pinia) owns Thread objects, tags and per-Thread unread; `messagesService` is called from that store and nowhere else; components read computed getters and dispatch store actions. A relayed message for a Thread the store has never loaded is normal — the store adopts the payload's Thread or ignores the message, never creating a half-populated entry (AD-15).
- **No new named route.** Directory and Thread addresses extend the existing `/call/<token>?threadId=…` query scheme and are read through a shared composable in the `useGetThreadId` mould. `SearchMessagesTab.vue` is the working precedent: `{ name: 'conversation', params: { token }, query: { threadId } }` — the one route name both factories declare (AD-16).
- `createMemoryRouter` backs **four** entry points — `mainFilesSidebar`, `mainPublicShareSidebar`, `mainPublicShareAuthSidebar`, `mainFloatingCall`. **"Resolves" means renders, not routes**: the two factories mount different components for the same route name. Every address this feature introduces declares one of two behaviours, recorded in its epic — **Rendered** (acceptance is a mounted-component test under `createMemoryRouter` against all four entry points) or **Degraded by design** (the parameter is dropped and the participant lands on the main chat). An address with neither declaration does not ship. `?threadId=` is already Rendered; the Directory address is Degraded by design unless its epic deliberately places it there (AD-16).

**Sensitivity and privacy**

- Thread Titles, Thread Tags and FR-43's lock reason are user-authored content treated exactly as message content. Notifications withhold the Thread Title wherever the shipped sensitive-conversation setting already withholds the message preview — **reusing the existing mechanism, not paralleling it**. No Thread Title or lock reason enters any part of a push payload outside the envelope the customer's server encrypts for the target device; the Clavis push proxy stays unable to read what it relays, which is a commitment made to customers. Routing **identifiers** are not content and do travel (AD-20).

**Fork hygiene**

- New behaviour lands in new classes — services, listeners, mappers, migrations — wherever a seam allows. Edits inside upstream-owned methods are the **smallest diff that works** and carry a comment naming the requirement (AD-17).
- One upstream thread to watch rather than act on: an open upstream issue reports Threads persisting after all their messages are deleted, the same ground AD-5's reaper occupies. If upstream lands its own fix, that is a merge collision to reconcile deliberately, not to discover (AD-17).

**Test surface**

- Behat scenarios extend `tests/integration/features/chat-4/threads.feature` (thirteen scenarios today). Existing step columns are `t.id`, `t.token`, `t.title`, `t.numReplies`, `t.lastMessage`, `a.notificationLevel`; **state, tags, featuring and unread all need adding to those definitions**.
- Two existing scenarios are load-bearing and must keep passing: **`:223`** asserts the composed notification object id `room1/Message 2/Thread 1` — FR-35's regression guard; **`:238-243`** exercises file-share-into-thread, FR-5's sixth path, and a Locked scenario belongs beside it.
- FR-5's acceptance is **one integration scenario per write path**, not one for the family (AD-2).
- Cache correctness needs a **multi-actor** test — a single actor reads its own fresh value and cannot see the bug (NFR-4).
- FR-30 needs a **mention-specific** test: the regression is not "the count is wrong" but "a thread mention stops being a mention", caught only by asserting the mention flag with a zero main-chat count.
- `tests/php/Service/ThreadServiceTest.php` and `ThreadControllerTest.php` **do not exist today**. `ThreadService` is the seam for most of this work and holds the cache logic, so it gets the PHPUnit tests it lacks.
- CI tests **four database engines** on every PR — MySQL/MariaDB, PostgreSQL, SQLite and **Oracle** (`phpunit-oci.yml`, `integration-oci.yml`). Write-time normalisation, grouped unread queries and migrations must all hold on Oracle, the strictest of the four.

**Verification limits carried into acceptance**

- Push delivery to devices depends on the Clavis push proxy, whose APNs/FCM hop is **unimplemented** and blocked on an Apple `.p8` key and a Firebase project. FR-35 and FR-37 are verifiable at **payload construction** but **not on a device**. No story may assume a device test exists.
- The push payload size budget must be **measured against the platform notifications app's actual encryption limit**, never inferred from the existing 100-character preview truncation at `Notifier.php:686-689`. It is story-level acceptance criteria, not an epic gate.

**Known non-scope defects found while mapping** (reportable upstream, not fixed here)

- `lib/Service/BotService.php:321` — a bot answer requesting a thread calls `createThread()` with the *invoking* comment id while the `thread_created` system message at `:328` uses the *bot's own reply* id; the thread row and the system message disagree about the root.
- `lib/Signaling/Listener.php:621-635` — the `threadInfo` signalling payload sets `'first' => $thread->toArray($room)`, putting a `Thread` where the REST shape puts a `ChatMessage`, and `'last' => null`. The signalling shape does not match `TalkThreadInfo`. **This will confuse anyone implementing FR-8's real-time propagation.**

### UX Design Requirements

**No UX design contract exists for this work.** No `ux-designs/` folder is present under `_bmad-output/planning-artifacts/`, and no legacy `*ux*.md` document was found. The `bmad-ux` workflow has not been run for this feature.

This is a deliberate record, not an omission to be filled by inference. UI-affecting requirements are therefore carried entirely by the PRD and the architecture, specifically:

- **Visual and interaction behaviour** — FR-8 (state visible on four surfaces, Ongoing silent), FR-10 (controls absent not disabled), FR-12 (Directory as a sidebar peer, narrow-viewport reachable), FR-16 (row fields, truncation under five tags), FR-18/FR-19 (as-you-type results, always-available message-search handoff), FR-26 (existing-tag suggestion, new-tag visually distinct), FR-32 (capped indicator, first-unread positioning with a stable boundary, position preserved on leaving part-read), FR-38 (top-bar control with unread indication), FR-43 (header shows reason, no empty affordance when absent).
- **Design tokens and palette** — the bounded Thread Tag palette is drawn from the host Nextcloud's design tokens, stored as an index rather than a hex value, and legible in light and dark at normal and high contrast (AD-10, NFR-9).
- **Accessibility** — keyboard reachability, screen-reader announcement including unread state, and state never conveyed by colour alone: a Closed or Locked Thread carries a label or icon, and a Thread Tag always renders its text with its colour (NFR-8, Consistency Conventions).
- **Component location warning** — `ThreadHeader.vue` is filed under `src/components/RightSidebar/Threads/` but renders in `TopBar.vue:54` and `ChatView.vue:27`, never in the sidebar. The directory layout misleads; do not infer placement from it.

If a UX design contract is produced later, this section is where it lands, and the affected stories are revisited rather than the contract being retro-fitted into them.

### FR Coverage Map

Every one of the 43 functional requirements maps to exactly one epic. Two requirements have a single consequence completed by a later epic; both are marked and neither is double-counted.

| FR | Epic | Coverage |
| --- | --- | --- |
| FR-1 | Epic 1 | Thread State exists, defaults Ongoing, present on every endpoint returning a Thread |
| FR-2 | Epic 1 | Thread Manager closes a Thread |
| FR-3 | Epic 1 | Posting into a Closed Thread returns it to Ongoing, across all nine paths |
| FR-4 | Epic 1 | Thread Manager locks a Thread from Ongoing or Closed |
| FR-5 | Epic 1 | Locked refuses every write — nine paths plus edits, deletes, pins, reactions |
| FR-6 | Epic 1 | Thread Manager reopens a Closed Thread directly |
| FR-7 | Epic 1 | Thread Manager reopens a Locked Thread |
| FR-8 | Epic 1 | Thread State visible on all four surfaces that exist today |
| FR-9 | Epic 1 | Thread Manager authority gates state, featuring and tagging |
| FR-10 | Epic 1 | Management controls absent, not disabled, for actors who may not use them |
| FR-11 | Epic 1 | Renaming authority unchanged — regression guard on existing coverage |
| FR-43 | Epic 1 | Optional free-text reason on locking, carried to header, composer and notification |
| FR-12 | Epic 2 | Thread Directory as a first-class peer surface; Shared items preview removed |
| FR-13 | Epic 2 | Featured first, then most recent activity, re-sorting live |
| FR-14 | Epic 2 | State filter defaulting to Ongoing; Featured Threads survive the filter |
| FR-15 | Epic 2 | Tag filter, combining with the state filter |
| FR-16 | Epic 2 | Row carries title, state, tags, reply count, last activity, unread count — **unread field completed in Epic 3** |
| FR-17 | Epic 2 | Incremental loading; filter and search span all Threads, not only loaded ones |
| FR-18 | Epic 2 | Title search — case-insensitive, substring, diacritic-insensitive for Vietnamese |
| FR-19 | Epic 2 | Always-available handoff to the existing message search |
| FR-20 | Epic 2 | Followed Thread List searchable by title, in its own query |
| FR-21 | Epic 2 | Feature and unfeature, visible to every participant, independent of state |
| FR-22 | Epic 2 | Featured Threads bounded per Conversation by a server-side constant |
| FR-23 | Epic 2 | Add and remove Thread Tags; five per Thread, twenty per Conversation |
| FR-24 | Epic 2 | Tag colour fixed within a Conversation, case-insensitive identity |
| FR-25 | Epic 2 | Thread Manager changes a tag's colour for the Conversation, propagating live |
| FR-26 | Epic 2 | Tag input surfaces existing tags before creating new ones |
| FR-38 | Epic 2 | Top-bar control opens the Directory — **unread indication completed in Epic 3** |
| FR-39 | Epic 2 | Thread header links to the Directory, leaving the Thread open behind it |
| FR-40 | Epic 2 | Thread Root Message links to the Directory positioned on its row |
| FR-41 | Epic 2 | Thread and Directory addresses are copyable, shareable, restorable |
| FR-42 | Epic 2 | Addresses resolve in both router factories, or omit their entry point cleanly |
| FR-27 | Epic 3 | Every Thread reports a Thread Unread Count for the current participant |
| FR-28 | Epic 3 | Unread state independent per Thread, in both directions |
| FR-29 | Epic 3 | Mark one Thread read, and mark all read including filter-hidden Threads |
| FR-30 | Epic 3 | Conversation unread counts main chat only; mention indication decoupled |
| FR-31 | Epic 3 | Conversation reports thread-unread separately from main-chat unread |
| FR-32 | Epic 3 | Unread shown in Directory and Followed Thread List; first-unread positioning |
| FR-33 | Epic 4 | All nine in-thread notification subjects name the Thread |
| FR-34 | Epic 4 | Lifecycle changes notify a Thread's followers, carrying FR-43's reason |
| FR-35 | Epic 4 | Push payloads carry the same identifiers as in-app notifications |
| FR-36 | Epic 1 | Following a notification lands the participant in the Thread |
| FR-37 | Epic 4 | Push notification text stays within its size budget |

**Coverage by epic:** Epic 1 — 13 FRs · Epic 2 — 20 FRs · Epic 3 — 6 FRs · Epic 4 — 4 FRs · **Total 43 / 43.**

**Non-functional coverage.** NFR-4 (cache staleness) and NFR-5 (concurrency) are established in Epic 1 and enforced by every subsequent epic's mutations. NFR-1 and NFR-2 (scale, no unbounded work) are owned by Epic 2's query design and re-tested by Epic 3's unread counting. NFR-3 (per-participant growth) is owned by Epic 3 alone. NFR-6 (migration safety) applies to all four migrations. NFR-7 (capability degradation) is owned by whichever epic declares each of the two flags — Epic 1 and Epic 3. NFR-8, NFR-9 and NFR-10 (accessibility, theme, localisation) are acceptance criteria on every user-visible story, not a separate epic. NFR-11 (real-time consistency) is established by Epic 1's relay registration and extended by Epic 2's tag recolour.

## Epic List

Four epics. The order is Lifecycle → Directory → Unread → Notifications, and it is load-bearing: Epic 1 establishes the aggregate boundary, the authority method and the system-message relay registration that Epics 2–4 all build on; Epic 2 builds the surfaces Epic 3's badges need somewhere to land; Epic 4 needs Epic 1's lifecycle verbs for FR-34.

Each epic delivers complete functionality for its domain and none requires a later epic to function. Where a later epic completes one consequence of an earlier requirement, that is stated rather than hidden — see FR-16 and FR-38 in the coverage map.

### Epic 1: Thread Lifecycle and Management Authority

A Thread Manager can declare a Thread finished, or shut it, and everyone in the Conversation can see which. **Closed** is advisory and deliberately cheap to get wrong — anyone who may post revives it, so tidying a room costs nothing to undo. **Locked** is permission — every write is refused from all thirteen entry points, optionally with a stated reason, and only a Thread Manager reopens it. Authority is the author of the Thread Root Message or a Conversation moderator, evaluated in exactly one place, mirroring the rule renaming already follows. And following a notification about a Thread arrives in that Thread — behaviour that largely works today, covered by a test here so that neither the addressing rework in Epic 2 nor the payload change in Epic 4 can quietly break it.

**FRs covered:** FR-1, FR-2, FR-3, FR-4, FR-5, FR-6, FR-7, FR-8, FR-9, FR-10, FR-11, FR-36, FR-43
**Governing decisions:** AD-1, AD-2, AD-3, AD-4, AD-5, AD-13, AD-14, AD-16, AD-17, AD-19, AD-20
**Delivers issue items:** 1, 2, 3

**Implementation notes:**

- Carries all four prerequisites: `git fetch --unshallow`; the row-key normalisation commit reconciling `createFromRow()` and `SelectHelper`; the seeded fixture at 1000 Threads / 200 participants; and the **AC-2 response-time baseline captured on today's nested list before this epic's own row changes ship** — FR-8 adds a state field to that row and a column to that query, so a baseline captured later is already polluted.
- Capability flag 1 (thread management), in both `FEATURES` and `LOCAL_FEATURES`.
- Migration 1, additive and defaulted: `talk_threads` **+** state (integer, default Ongoing), **+** nullable bounded lock reason.
- Establishes `ThreadService` as the sole write seam with `remove()`-only cache invalidation — the discipline every later epic inherits.
- The **third enforcement seam** for reactions, edits, deletes and pins is mandatory here, not optional: shipping only the two `ChatManager` choke points leaves a Locked Thread editable and deletable while reporting itself locked.
- `ChatController::postAttachmentToRoom()` gains the `validateThread()` call it does not perform today.
- Every lifecycle verb registered in all six places, with the four verb checks in `lib/Signaling/Listener.php` extracted into named constants declared together.
- The AD-5 reaper for Threads orphaned by message expiry, bounded per run, invalidating each reaped cache entry in the same pass.
- `tests/php/Service/ThreadServiceTest.php` — does not exist today and is where the cache logic gets covered.
- FR-5's acceptance is **one integration scenario per write path**, not one for the family.
- FR-8 lands on the four surfaces that already exist — the current threads list, the thread header, the Thread Root Message in the main chat, and the Followed Thread List. It does not wait for the Directory.
- FR-36's notification navigation is pinned under test **here**, not in Epic 4. Deep-linking into a Thread largely works today; covering it in Epic 1 puts the regression net in place before Epic 2 reworks the addressing and before Epic 4 changes what a push payload hands the client. It follows FR-8 because a Locked Thread must be able to render its state and disable its composer for two of the criteria to mean anything. No new named route is added — the existing `/call/<token>?threadId=…` query scheme and `useGetThreadId` are used as they stand.

### Epic 2: Thread Directory — Finding the Thread You Want

A room with a thousand Threads becomes navigable. Threads get a front door: a first-class Directory that is a peer of the Conversation's other sidebar surfaces rather than a sub-page of Shared items, reachable in one click from the top bar, from an open Thread's header and from a Thread Root Message. Featured Threads hold the top for a room's standing references. Filters narrow by state and by tag, title search finds a Thread by name — without diacritics — and hands the term to message search when that was the wrong question. Rows carry enough to decide without opening, and every destination has an address that can be copied, shared and returned to, in every surface that renders a Conversation.

**FRs covered:** FR-12, FR-13, FR-14, FR-15, FR-16, FR-17, FR-18, FR-19, FR-20, FR-21, FR-22, FR-23, FR-24, FR-25, FR-26, FR-38, FR-39, FR-40, FR-41, FR-42
**Governing decisions:** AD-9, AD-10, AD-11, AD-12, AD-14, AD-15, AD-16, AD-19
**Delivers issue items:** 4, 5, 6, 7

**Implementation notes:**

- Consolidated because filtering, ordering, paging, featuring, search and tagging are **one query and one set of files** — `ThreadController::getRecentActiveThreads` parameters, `ThreadService` queries, the Directory components, and `useChatExtrasStore`. Splitting them would rewrite the same query four times.
- Two additive migrations: `talk_threads` **+** featured (boolean) **+** normalised name; and the two new tag tables, `talk_thread_tags` (room-scoped, unique on room + normalised name) and `talk_thread_tag_map`.
- **The keyset cursor is built once, on the full tuple** — featured, then `last_activity` descending, then thread id descending. This is why featuring cannot be a later epic: `last_activity` moves while a participant pages, so an offset repeats one row and silently drops another, and adding `featured` to the tuple afterwards means rebuilding the cursor.
- The **existing client-side sort in the store is removed** as part of this work; the client keeps the server's order as an ordered id list and never re-sorts.
- The thread list and its three-item preview are removed from Shared items rather than left as a second path.
- One shared normalisation helper — trim, case-fold, diacritic-fold — serving both `talk_threads.name` and tag names, written at create and at rename, matched against the stored normalised column. Nothing relies on collation, and it must hold on **all four CI database engines including Oracle**.
- `ThreadTagService` is a **separate aggregate** from `ThreadService`; neither writes the other's rows. `ConversationTagService` is copied for its exception vocabulary and its cap of twenty, **not** for its `normalizeTagName()`, which only trims and would produce exactly the case-and-diacritic duplicates this forbids.
- **Separate aggregate, shared cache owner.** Tags render on the cached Thread representation, so every tag mutation invalidates the affected Threads' cache entries — through an explicit `ThreadService` method that `ThreadTagService` calls, never by the tag service reaching the cache key. A recolour fans out across every Thread carrying the tag. Stories 2.7 AC19–AC20 and 2.8 AC12–AC13 are where this is enforced.
- **The Directory row carries the management menu, not just the fields.** Story 2.4 AC15 places Story 1.4's four transitions and Story 2.1's feature/unfeature on the row itself, inheriting the list-row menu Story 1.8 AC11 established, and Story 2.7's tag action joins the same menu. This is what makes Story 2.4 AC16 — PRD AC-1, the walk that §9.3's accepted day-one limitation rests on — reachable at all.
- Tag colour is an **index into a bounded palette**, never a free-form hex value.
- A tag recolour joins AD-14's relay set so it propagates live, **without** joining AD-2's Locked exemption list.
- Every cap is one server-side constant, enforced at write time and **published in the capability payload** rather than restated in the interface.
- FR-20's title search happens inside the Followed Thread List's own hand-built query, not in the client store — two list surfaces filtering at two different layers is the same divergence twice.
- Every address this epic introduces declares **Rendered** or **Degraded by design** for `createMemoryRouter`'s four entry points (`mainFilesSidebar`, `mainPublicShareSidebar`, `mainPublicShareAuthSidebar`, `mainFloatingCall`). "Resolves" means renders, not routes: the two factories mount different components for the same route name. An address with neither declaration does not ship.
- No new named route — addresses extend the existing `/call/<token>?threadId=…` query scheme through a shared composable in the `useGetThreadId` mould.
- `ThreadHeader.vue` is filed under `src/components/RightSidebar/Threads/` but renders in `TopBar.vue` and `ChatView.vue`, never in the sidebar. Do not infer placement from the directory layout.
- FR-16's unread field and FR-38's unread indication render empty until Epic 3; both degrade cleanly rather than erroring.

### Epic 3: Per-Thread Unread State

A badge points at where you actually owe a reply. Thread unread becomes genuinely independent: reading one Thread clears that Thread and nothing else, and reading the Conversation's main chat clears nothing in any Thread. A participant can mark one Thread read from its row without opening it, or mark every Thread read — including Threads the active filter is hiding — so a badge is always clearable. Opening a Thread with unread messages lands on the first one, and leaving part-read comes back to the same place.

**FRs covered:** FR-27, FR-28, FR-29, FR-30, FR-31, FR-32
**Governing decisions:** AD-6, AD-7, AD-8, AD-9, AD-12, AD-19
**Delivers issue item:** 9 — and over-delivers it: the issue asked for a count, this specifies independence, and independence is what forces the API change.

**Implementation notes:**

- **Not merged into Epic 2, despite sharing `ThreadService`, `ThreadController` and `useChatExtrasStore` with it.** The overlap is real and was weighed. It stays separate because this is the **only epic that breaks an API contract**: FR-30 changes the meaning of a field three unmodified shipped clients already read, and it ships behind its own capability flag. Folding it into Epic 2 would bind a wholly additive, independently releasable Directory to a breaking change, and would put both behind one flag that can no longer tell a client which of the two it is talking to. Epics 1 and 2 also reach these files, but each owns a distinct concern within them — lifecycle writes, then query and ordering — and each lands a working surface before the next begins.
- **The highest-risk epic in this work.** FR-30 is the one recorded exception to the additive-only API rule, and three shipped clients this epic does not modify read that field. The mention indication is currently *computed from* the count, so a careless change makes thread mentions stop registering as mentions everywhere.
- CAP-8 and CAP-9 are one change, not two: if reading a Thread must not alter main-chat unread, thread replies cannot keep contributing to it. Shipping them apart would create an intermediate state that contradicts itself.
- Capability flag 2 (unread semantics), separate from flag 1, so a client can tell which semantics the server uses independently of whether thread management is present.
- Migration: `talk_thread_attendees` **+** `last_read_message` and `last_mention_message` reinstated (**two columns, not three** — `last_mention_direct` stays dropped), **+** an explicit subscription column shipping `NOT NULL DEFAULT true`; `talk_attendees` **+** a nullable thread-read baseline. The reinstatement carries a comment saying why the columns are back.
- The baseline is frozen lazily at **one named seam**, `ParticipantService::updateLastReadMessage()`, on **any** advance — that method has six callers and two of them are posts, so a freeze wired to "reading the main chat" would let a participant whose first action is a thread reply resolve every never-opened Thread as read, permanently.
- Mark-all-threads-read **resets** read columns and never deletes rows: subscription lives on the same row, so deleting one to clear a badge would silently unfollow the Thread.
- `getRecentByActor()` gains the subscription predicate **in the same change** that introduces read-created rows, not afterwards — otherwise every Thread a participant ever opened enrols itself into their Followed Thread List.
- The `unreadMessages !== 0` gate **and** the `lastReadMessage === lastMessage` short-circuit above it are both removed from `RoomFormatter`; the second becomes wrong once the last message may be a thread reply.
- The unread-count cache key's semantics token is **appended, never prepended** — six sites invalidate that cache by room-id prefix.
- **Posting a thread reply must stop advancing the conversation read marker**, or the split leaks backwards and silently marks unread main-chat messages read.
- Two of the three already-migrated `has_unread_thread*` columns get their first callers; no migration is needed for them.
- Cache correctness needs a **multi-actor** test — a single actor reads its own fresh value and cannot see the bug. FR-30 needs a **mention-specific** test: the regression is not "the count is wrong" but "a thread mention stops being a mention".
- Completes FR-16's unread row field and FR-38's top-bar unread indication.

### Epic 4: Thread-Aware Notifications

A notification says which Thread it came from, so it can be triaged without being opened. Followers of a Thread learn when it is Closed, Locked or reopened — and why, when a reason was given — rather than discovering it by trying to post and failing. Push payloads carry the same identifiers the in-app notification already carries, so a client routes into the right Thread without a second network call.

**FRs covered:** FR-33, FR-34, FR-35, FR-37
**Governing decisions:** AD-12, AD-14, AD-20
**Delivers issue item:** 10, and the server-side groundwork for item 8

**Implementation notes:**

- **Honest scope.** Only FR-33 and FR-34 produce a user-visible change on a client this work touches. Web and desktop deep-linking into Threads already works, and Android already reaches Threads from a push by fetching the notification over the API — the reported symptom of landing in the main chat originates in that client's *fallback* path. FR-35 and FR-37 remove a network round trip and enable the mobile epic; they do not, by themselves, change what any user sees. Reading this epic as "issue item 8 is done" is a misread.
- FR-36 is **not** here. Notification navigation is pinned under test in Epic 1 Story 1.9, so the payload change in this epic cannot silently break it. That ordering is the point of the placement, and reversing it forfeits the guard.
- Nine notification subjects carry the Thread Title, with **a test per subject**, not one test for the family.
- FR-34 depends on Epic 1's lifecycle verbs and reads FR-43's reason from the system message's **parameter data**, never by re-parsing rendered text.
- The composed notification object identifier is append-only, variable-arity and absence-significant: a trailing position may be appended, never reordered or shortened, never padded, never a sentinel, and `0` is never valid at any position. The existing assertion at `threads.feature:223` on `room1/Message 2/Thread 1` is the regression guard and must keep passing.
- AD-20 governs what travels: Thread Titles and lock reasons are withheld wherever the shipped sensitive-conversation setting already withholds the message preview, and never enter any part of a push payload outside the envelope the customer's server encrypts. Routing identifiers are not content and do travel.
- **No device test exists and none may be assumed.** The Clavis push proxy's APNs/FCM hop is unimplemented, blocked on an Apple `.p8` key and a Firebase project. FR-35 and FR-37 are verifiable at payload construction only.
- FR-37's size budget must be **measured** against the platform notifications app's actual encryption limit, never inferred from the existing 100-character preview truncation at `Notifier.php:686-689`.
- Watch for the known upstream defect at `lib/Signaling/Listener.php:621-635` — the `threadInfo` signalling payload does not match `TalkThreadInfo`. Out of scope, but it will confuse anyone tracing thread context through notifications and signalling.

## Epic 1: Thread Lifecycle and Management Authority

A Thread Manager can declare a Thread finished, or shut it, and everyone in the Conversation can see which. **Closed** is advisory and deliberately cheap to get wrong — anyone who may post revives it, so tidying a room costs nothing to undo. **Locked** is permission — every write is refused from all thirteen entry points, optionally with a stated reason, and only a Thread Manager reopens it. Authority is the author of the Thread Root Message or a Conversation moderator, evaluated in exactly one place, mirroring the rule renaming already follows. And following a notification about a Thread arrives in that Thread — behaviour that largely works today, covered by a test here so that neither the addressing rework in Epic 2 nor the payload change in Epic 4 can quietly break it.

*Covers FR-1 … FR-11, FR-36 and FR-43. Governed by AD-1, AD-2, AD-3, AD-4, AD-5, AD-13, AD-14, AD-16, AD-17, AD-19, AD-20.*


### Story 1.1: Prepare the fork — history, row-key convention, and the scale fixture

As a Clavis engineer,
I want full git history, one row-key convention for hydrating a Thread, and a seeded Conversation at the design scale with its response time already recorded,
So that the fork can rebase onto upstream releases, a new Thread field cannot load on one query path while silently failing on another, and the criteria this work is judged by can actually fail.

**Acceptance Criteria:**

**AC1**
**Given** the working clone is depth-1 and `git rev-list --count HEAD` returns 1
**When** `git fetch --unshallow` completes
**Then** the full upstream history is present and `.git/shallow` is gone
**And** a rebase onto an upstream `stable34` reference completes without a history-related error.

**AC2**
**Given** `Thread::createFromRow()` reads a bare/`t_`-style row while the aliased branch of `SelectHelper::selectThreadsTable()` emits `th_*` keys
**When** the two are reconciled onto a single prefix
**Then** both the direct-query path and the joined-read path hydrate a `Thread` from the same row-key convention
**And** no new column, API field or response shape is introduced by the change.

**AC3**
**Given** the reconciliation is a behaviour-preserving refactor
**When** it lands as its own commit ahead of any new Thread field
**Then** `tests/integration/features/chat-4/threads.feature` passes unchanged
**And** it passes on all four CI database engines including Oracle.

**AC4**
**Given** no scale fixture exists
**When** the seeding tool is run
**Then** a Conversation contains at least 1000 Threads and 200 participants with activity dates spread across a year
**And** the tool is re-runnable and produces a deterministic dataset that later epics reuse rather than rebuild.

**AC5**
**Given** the seeded Conversation and the current nested threads list with no change yet applied to it
**When** first-page response time is sampled
**Then** median and 95th-percentile figures are recorded in a committed artefact
**And** the sample is taken before any story in this epic alters that list, its row, or its query — a baseline captured after Story 1.8 adds a state field is already polluted. *(AC-2)*

**AC6**
**Given** the seeded Conversation immediately after seeding
**When** `talk_thread_attendees` rows are counted and compared against participants × Threads
**Then** the count is recorded as the pre-read baseline that AC-5 measures growth against.

### Story 1.2: Every Thread reports a Thread State

As a participant,
I want every Thread to carry a state,
So that the interface and the API have something to show and the lifecycle has somewhere to live.

**Acceptance Criteria:**

**AC1**
**Given** the migration adding an integer state column to `talk_threads` with a default of Ongoing
**When** a new Thread is created
**Then** it reports state Ongoing
**And** the migration is additive, defaulted, safe to run twice, and runs no table-wide `UPDATE`.

**AC2**
**Given** Threads that existed before the migration
**When** they are read after upgrade
**Then** every one reports state Ongoing with no interaction required
**And** no backfill statement appears in the migration.

**AC3**
**Given** the four endpoints that return a Thread — `getRecentActiveThreads`, `getSubscribedThreads`, `getThread` and `renameThread`
**When** each is called
**Then** the `TalkThreadInfo` response carries the state
**And** the regenerated `openapi*.json` and `src/types/openapi/*.ts` land in the same change.

**AC4**
**Given** a request setting a state outside the three defined values
**When** it is submitted
**Then** it is refused with a validation error naming the field
**And** the refusal is distinguishable from a permission failure and from a Thread-not-found failure.

**AC5**
**Given** the state field is added to `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` and both branches of `SelectHelper::selectThreadsTable()`
**When** a Thread is read back through the distributed cache rather than from the database
**Then** its state survives the round trip
**And** a Thread read through a joined query carries the state as well.

**AC6**
**Given** the thread-management capability flag declared in both `FEATURES` and `LOCAL_FEATURES`, separate from the shipped unconditional `threads` flag
**When** a client that does not see the flag talks to this server
**Then** it behaves exactly as it does today rather than erroring
**And** a client on a federated Conversation learns from `features-local` that the feature is unavailable there.

### Story 1.3: One authority rule for Thread management, on the server and in the client

As a member who started a Thread,
I want authority over my own Thread without being made a moderator,
So that management follows the rule renaming already uses instead of inventing a second one.

**Acceptance Criteria:**

**AC1**
**Given** the authority expression currently inline in `ThreadController::renameThread()`
**When** it is lifted into `ThreadService::isThreadManager(Thread, Participant): bool`
**Then** that single method holds *root-message author OR `hasModeratorPermissions(false)`*
**And** no endpoint re-derives the expression anywhere else in the codebase.

**AC2**
**Given** `isThreadManager()` needs to load the root comment
**When** the dependency is wired
**Then** it takes the low-level `CommentsManager` rather than `ChatManager`, which already injects `ThreadService`
**And** the object-type/object-id check that lookup needs lives in one private helper beside it.

**AC3**
**Given** a Thread whose root comment cannot be loaded
**When** authority is evaluated
**Then** only moderators qualify, preserving the degradation upstream already implements.

**AC4**
**Given** `renameThread` refactored to call the shared method
**When** the existing thread integration coverage runs
**Then** renaming behaviour and permissions are identical before and after, verified by that coverage passing unchanged. *(FR-11)*

**AC5**
**Given** three distinct failure causes — Thread is Locked, actor lacks authority, Thread does not exist
**When** each is triggered
**Then** each carries its own exception class and its own OCS error identifier in the existing `['error' => '<identifier>']` convention
**And** Locked is **not** folded into the existing `permission` value.

**AC6**
**Given** the `TalkThreadInfo` representation gains a field stating whether the current actor may manage this Thread
**When** the client store reads a Thread
**Then** exactly one computed getter exposes that answer and every later control consumes it
**And** components never call the service layer to ask.

**AC7**
**Given** a member who is not a moderator, viewing a list containing both a Thread they started and a Thread someone else started
**When** the existing rename control is rendered
**Then** it appears on their own Thread and is **absent — not disabled —** on the other, within the same list. *(FR-10)*

**AC8**
**Given** a Conversation moderator viewing the same list
**When** the controls are rendered
**Then** they appear on every Thread in the Conversation.

**AC9**
**Given** a guest session
**When** any management operation is attempted on any Thread
**Then** it is refused regardless of who created the Thread.

**AC10**
**Given** a member who is demoted from moderator between opening a menu and using it
**When** the request arrives
**Then** it is refused on the request rather than at session end, because authority is evaluated per request.

### Story 1.4: A Thread Manager moves a Thread between states, with an optional reason on locking

As a moderator whose room keeps re-litigating settled decisions,
I want to mark a Thread finished, shut it and say why, and undo either,
So that the list shows what is actually live and a decision that has been made stops being reopened.

*One state-change endpoint serves all four transitions — close, lock, reopen from Closed, reopen from Locked — per AD-12's rule that new endpoints appear only for operations that do not exist.*

**Acceptance Criteria:**

**AC1**
**Given** an Ongoing Thread and an actor who is a Thread Manager
**When** they close it
**Then** the Thread reports state Closed immediately after the request succeeds
**And** the response carries the full `TalkThreadInfo` representation, the same shape and same builder a list endpoint returns. *(FR-2)*

**AC2**
**Given** a Thread that is Ongoing, and another that is Closed
**When** a Thread Manager locks each
**Then** both report Locked, and the Closed one reaches Locked directly without passing through Ongoing. *(FR-4)*

**AC3**
**Given** a Closed Thread and a Thread Manager who is the Thread Root Message author but not a moderator
**When** they reopen it without posting
**Then** the Thread reports Ongoing. *(FR-6)*

**AC4**
**Given** a Locked Thread and a Thread Manager
**When** they unlock it
**Then** it reports Ongoing and accepts writes again. *(FR-7)*

**AC5**
**Given** any of the four transitions succeeds
**When** the Thread's messages are read
**Then** a system message records the transition and who performed it
**And** each transition's constant is registered in all six places, including **all four** verb checks in `lib/Signaling/Listener.php` — the two that decide whether it relays at all, and the two that decide whether it arrives carrying its Thread.

**AC6**
**Given** those four verb checks currently sit as inline literals inside one file
**When** this story lands
**Then** they are extracted into named constants declared together and each site tests membership
**And** adding a future verb means editing one list, not remembering four.

**AC7**
**Given** a participant who is neither the Thread Root Message author nor a Conversation moderator
**When** they attempt any of the four transitions
**Then** they are refused with the authority error identifier from Story 1.3, with the same error shape across all four.

**AC8**
**Given** a Thread that is already Closed
**When** a Thread Manager closes it again
**Then** the request succeeds without duplicating the system message.

**AC9**
**Given** the migration adding a nullable, bounded lock-reason column to `talk_threads`
**When** it runs
**Then** it is additive, defaulted, safe to run twice and backfills nothing.

**AC10**
**Given** a Thread Manager locking with a free-text reason
**When** the request succeeds
**Then** the reason is carried on the locking system message as **message parameter data**, never concatenated into the rendered message text
**And** the current reason is also written to the `talk_threads` column so the thread header needs no history walk. *(FR-43)*

**AC11**
**Given** a Thread Manager locking without a reason, and another submitting whitespace only
**When** each request completes
**Then** both succeed and both are treated as no reason, with the absence producing no error
**And** the length bound is stated in the interface before the participant hits it.

**AC12**
**Given** a Thread locked with reason A, unlocked, then locked again with reason B
**When** the Thread is read
**Then** the column holds B and the earlier system message still holds A intact.

**AC13**
**Given** the lock reason is user-authored content in a customer Conversation
**When** it travels
**Then** it is withheld exactly where a Thread Title is withheld, and never enters any part of a push payload outside the envelope the customer's server encrypts.

**AC14**
**Given** two Thread Managers changing the same Thread's state concurrently
**When** both requests complete
**Then** the later write wins with no conflict response and no concurrency token
**And** the earlier actor's client displays the state carried in **its own response**, never what it sent.

**AC15**
**Given** any transition completes
**When** the cache is examined
**Then** `thread/{roomId}/{threadId}` was **removed**, not re-set from the entity the mutator held.

**AC16**
**Given** actor A changes a Thread's state and actor B is viewing the same Conversation
**When** B reads the Thread through the distributed cache
**Then** B sees the new state rather than a stale value, and B's view updates without a reload with the relayed payload carrying its Thread context
**And** this is proven by a **multi-actor** integration scenario, because a single actor reads its own fresh value and cannot see the bug.

**AC17**
**Given** a Thread Manager with a Thread open
**When** the thread header renders
**Then** it offers a menu carrying all four transitions — close, lock, reopen from Closed, reopen from Locked — so the actor changes state from the Thread itself without navigating away from it
**And** the lock action offers AC10's optional reason field with AC11's length bound stated before it is hit. *(UJ-2)*

**AC18**
**Given** the state-change controls
**When** a Thread is rendered for a non-manager
**Then** they are absent, not disabled, driven by Story 1.3's store getter. *(FR-10)*

**AC19**
**Given** the four lifecycle constants this story introduces
**When** AD-2's exemption list is assembled
**Then** the lifecycle-transition subset of the relay set **is** that exemption list, so the guard cannot drift from the state machine.

> **Control placement is acceptance, not styling.** AC17 fixes *where* a Thread Manager reaches these transitions, because FR-2, FR-4, FR-6 and FR-7 specify the transitions without specifying the affordance — an implementation offering them only on a per-Thread page satisfies every other criterion in this story and still fails UJ-2. The list-row equivalent lands in Story 1.8 AC11 and the Directory-row equivalent in Story 2.4 AC15; together they are what makes PRD AC-1 reachable.

> **Epic gate:** this story ships a Locked state that does not yet refuse anything. Stories 1.6 and 1.7 complete FR-5, and **the epic does not ship without both** — a Locked Thread that reports itself locked while remaining writable is the failure AD-2 exists to prevent.

### Story 1.5: Posting into a Closed Thread returns it to Ongoing

As a participant with something to add,
I want posting into a finished Thread to simply revive it,
So that a premature close costs one message to undo and closing stays a habit rather than a decision.

**Acceptance Criteria:**

**AC1**
**Given** a Closed Thread and a participant who may post in the Conversation but is not a Thread Manager
**When** they post a reply into it
**Then** the reply succeeds and the Thread reports state Ongoing.

**AC2**
**Given** the revival happens
**When** the Thread's messages are read
**Then** no system message about the state change was produced — the reply itself is the record.

**AC3**
**Given** the revival hook is applied inside `ChatManager::sendMessage()` and `ChatManager::addSystemMessage()`
**When** content arrives by any of the eight live paths
**Then** every one of them revives the Thread, not only a typed message
**And** the effective thread id is resolved once through the single shared helper, before the write.

**AC4**
**Given** a message scheduled before the Thread was closed
**When** it fires into the Closed Thread
**Then** it posts and the Thread returns to Ongoing.

**AC5**
**Given** a Locked Thread and a non-manager participant
**When** they post
**Then** the post is refused and the Thread stays Locked — the asymmetry with Closed is deliberate.

**AC6**
**Given** the revival mutates the Thread
**When** the write completes
**Then** the cache entry is invalidated in the same pass
**And** a second actor reading the Thread sees Ongoing.

**AC7**
**Given** a participant viewing the recent-threads list filtered to live Threads
**When** a reply revives a Closed Thread
**Then** the Thread reappears in that list without the participant refreshing.

### Story 1.6: A Locked Thread refuses posted content on all nine paths

As a participant,
I want a Locked Thread to refuse content from wherever it arrives,
So that "locked" means locked rather than "locked unless you use a different button".

*Content reaches a Thread through nine distinct paths. Each is its own testable consequence because the first revision of the PRD enumerated six and missed three — the enumeration is the control, not a formality.*

**Acceptance Criteria:**

**AC1**
**Given** the guard is applied inside `ChatManager::sendMessage()` and `ChatManager::addSystemMessage()` and nowhere else
**When** it evaluates
**Then** it reads one expression, resolved once through the shared thread-id helper, **before** `commentsManager->save()`
**And** never on a value re-derived afterwards, because a refusal that happens after the save is not a refusal.

**AC2**
**Given** `sendMessage()`'s explicit-`threadId` branch, which today skips the `validateThread()` call the reply branch performs
**When** the asymmetry is closed
**Then** the guard is always handed a validated thread id.

**AC3**
**Given** a Locked Thread
**When** a typed message is posted into it *(path 1)*
**Then** it is refused with the `ThreadLocked` identifier, distinguishable by the client from a generic permission failure and from a Thread-not-found failure.

**AC4**
**Given** a Locked Thread
**When** a bot posts through the bot API *(path 2)*, or an in-process bot answers an invocation event *(path 3)*
**Then** each is refused, with a scenario per path.

**AC5**
**Given** a Locked Thread
**When** a rich object is shared into it *(path 4)*, or a poll is created in it *(path 5)*
**Then** each is refused, with a scenario per path.

**AC6**
**Given** a Locked Thread
**When** a file is shared into it *(path 6)* — the path that reads the thread identifier from share metadata and can create Threads as well as post into them
**Then** it is refused
**And** the scenario sits beside the existing file-share-into-thread scenario at `threads.feature:238-243`.

**AC7**
**Given** `ChatController::postAttachmentToRoom()`, which performs no thread validation whatsoever today
**When** the missing `validateThread()` call is added and an attachment is uploaded into a Locked Thread *(path 7)*
**Then** it is refused.

**AC8**
**Given** a Thread that is Locked at the moment of scheduling
**When** a participant schedules a message into it *(path 8)*
**Then** the request is refused at request time, where an acting participant still exists
**And** this carve-out is stated in the code rather than hidden, because scheduling writes no comment at all.

**AC9**
**Given** a message scheduled while the Thread was Ongoing, and the Thread Locked before the scheduled time
**When** the background job fires *(path 9)*
**Then** the message does not post, and the job marks it failed with a reason the sender can see
**And** the guard resolves and refuses without depending on request context, because this path has no live request and no acting participant.

**AC10**
**Given** a message scheduled while the Thread was Locked, then unlocked before its time
**When** it fires
**Then** it posts normally and, if the Thread was Closed, revives it per Story 1.5.

**AC11**
**Given** any refusal on any of these nine paths
**When** it occurs
**Then** it fails visibly rather than silently redirecting the content into the Conversation's main chat.

**AC12**
**Given** the state-change verbs of Story 1.4
**When** they write their system messages into a Locked Thread
**Then** they succeed, because the exemption is an explicit allow-list of those verbs and nothing else
**And** the exemption is **not** expressed as "system messages are exempt", which would leave four of these nine paths unguarded.

**AC13**
**Given** FR-5's enumeration is the control
**When** acceptance is written
**Then** there is **one integration scenario per path**, not one for the family.

### Story 1.7: A Locked Thread refuses edits, deletes, pins and reactions

As a participant,
I want a Locked Thread to be frozen rather than merely closed to new messages,
So that nobody edits an existing message to say something new in a Thread that is supposed to be settled.

**Acceptance Criteria:**

**AC1**
**Given** `ReactionManager` and `ChatManager`'s own edit, delete and pin methods reach `commentsManager->save()` without passing through `sendMessage()` or `addSystemMessage()`
**When** Locked is extended to them
**Then** it is done by a **third enforcement seam** covering all four, not by four separate controller guards and not by an extra entry in the exemption list.

**AC2**
**Given** a Locked Thread containing a message the actor wrote
**When** they attempt to edit it
**Then** it is refused with the `ThreadLocked` identifier.

**AC3**
**Given** a Locked Thread containing an existing message
**When** a participant or a moderator attempts to delete it
**Then** it is refused.

**AC4**
**Given** a Locked Thread containing an existing message
**When** a moderator attempts to pin or unpin it
**Then** it is refused.

**AC5**
**Given** a Locked Thread containing an existing message
**When** a participant attempts to add or remove a reaction
**Then** it is refused, and `ReactionManager` is a call site of the third seam.

**AC6**
**Given** the Thread is unlocked by a Thread Manager
**When** all four operations are retried
**Then** all four succeed again.

**AC7**
**Given** the seam is one place
**When** a new route to `commentsManager->save()` is added in future
**Then** it routes through the seam rather than around it.

### Story 1.8: Thread State is visible wherever a Thread appears

As a participant scanning a room,
I want to see at a glance which Threads are finished and which are shut,
So that I can tell a live discussion from one that ended three weeks ago without opening either.

**Acceptance Criteria:**

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

### Story 1.9: Following a notification lands the participant in the Thread

As someone tapping a notification,
I want to arrive in the Thread the notification was about,
So that acting on a notification does not mean hunting for what it referred to.

*Placed here deliberately. Web and desktop deep-linking into Threads largely works today; this story pins that behaviour under test **before** anything later moves it — Epic 2 Story 2.5 reworks the addressing and Epic 4's push payload change alters what a client is handed, and neither can silently break navigation once this net exists. It sits after Story 1.8 because two of its criteria require a Locked Thread to render its state.*

**Acceptance Criteria:**

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

### Story 1.10: Threads orphaned by message expiry are reaped

As a Clavis engineer,
I want a Thread to survive its root message being deleted but not survive it expiring,
So that thread rows do not accumulate forever and a warm cache cannot accept content into a Thread that no longer exists.

**Acceptance Criteria:**

**AC1**
**Given** a Thread whose Thread Root Message is deleted by its author or by a moderator
**When** the deletion completes
**Then** the comment row survives as a tombstone and the Thread survives with its id, state, lock reason and replies intact
**And** its root renders as the deleted placeholder.

**AC2**
**Given** message expiry, which hard-deletes comment rows
**When** the expiry job runs
**Then** a bounded reaper removes `talk_threads` and `talk_thread_attendees` rows whose root comment no longer exists.

**AC3**
**Given** a Conversation with a long history
**When** the reaper runs
**Then** it is bounded per run rather than a full-table sweep, alongside the existing five-minute expiry job.

**AC4**
**Given** a Thread is reaped
**When** the same pass completes
**Then** that Thread's cache entry has been invalidated
**And** `validateThread()` no longer answers from a warm entry, so content cannot be accepted into a Thread that no longer exists.

**AC5**
**Given** any read path encountering a Thread whose root comment is missing
**When** it renders or responds
**Then** it degrades the way `renameThread` already does rather than erroring.

**AC6**
**Given** an open upstream issue reports Threads persisting after all their messages are deleted — the same ground this reaper occupies
**When** a future upstream release lands its own fix
**Then** the reaper carries a comment naming this requirement so the collision is reconciled deliberately rather than discovered.

## Epic 2: Thread Directory — Finding the Thread You Want

A room with a thousand Threads becomes navigable. Threads get a front door: a first-class Directory that is a peer of the Conversation's other sidebar surfaces rather than a sub-page of Shared items, reachable in one click from the top bar, from an open Thread's header and from a Thread Root Message. Featured Threads hold the top for a room's standing references. Filters narrow by state and by tag, title search finds a Thread by name — without diacritics — and hands the term to message search when that was the wrong question. Rows carry enough to decide without opening, and every destination has an address that can be copied, shared and returned to, in every surface that renders a Conversation.

*Covers FR-12 … FR-26 and FR-38 … FR-42. Governed by AD-9, AD-10, AD-11, AD-12, AD-14, AD-15, AD-16, AD-19.*

**Story order is constrained, not stylistic.** The keyset cursor is addressed by the tuple *(featured, last_activity DESC, id DESC)*, so the featured column must exist before paging is built — Story 2.1 precedes Story 2.2 for that reason alone. The server-side query work (2.1–2.3) lands in the list that exists today before the Directory surface replaces it (2.4), so nothing is built twice.

### Story 2.1: Feature a Thread, and Featured Threads sort first

As a moderator whose room has standing references,
I want to raise a Thread to the top of the list and keep it there,
So that the rota, the checklist and the escalation procedure stay visible even though nobody replies to them any more.

**Acceptance Criteria:**

**AC1**
**Given** the migration adding a featured boolean to `talk_threads`
**When** it runs
**Then** it is additive, defaulted to false, safe to run twice and backfills nothing — every pre-existing Thread reads as unfeatured.

**AC2**
**Given** a Thread Manager and an unfeatured Thread
**When** they feature it
**Then** the Thread reports as featured for **every participant in the Conversation**, not only the actor
**And** the response carries the full `TalkThreadInfo` representation from the same builder a list endpoint uses. *(FR-21)*

**AC3**
**Given** a featured Thread
**When** a Thread Manager unfeatures it
**Then** it returns to activity ordering, and if its state is Closed or Locked it returns to being hidden under the default filter.

**AC4**
**Given** a participant who is not a Thread Manager
**When** they attempt to feature or unfeature
**Then** they are refused with the authority error identifier from Story 1.3.

**AC5**
**Given** a Closed Thread and a Locked Thread
**When** each is featured
**Then** both succeed, and featuring changes neither one's Thread State — featuring and state are independent.

**AC6**
**Given** the list of a Conversation's Threads
**When** it is ordered
**Then** every featured Thread sorts above every unfeatured Thread whatever their state or activity, and within each group the most recent activity is first
**And** the tie-break is thread id descending, so the order is total rather than merely mostly-determined. *(FR-13)*

**AC7**
**Given** a participant viewing the list
**When** a reply arrives in a listed Thread
**Then** that Thread moves to the top of its group without the participant refreshing.

**AC8**
**Given** a Conversation already holding the maximum number of Featured Threads
**When** a Thread Manager features one more
**Then** it is refused with an error **naming the limit**, not a generic failure
**And** the limit is one server-side constant, identical for every Conversation, enforced where the write happens — a sort cannot enforce a count and a disabled button is not enforcement. *(FR-22)*

**AC9**
**Given** the featured cap
**When** a client asks what the server allows
**Then** the cap is published in the capability payload rather than restated as a literal in the interface
**And** the interface states the limit before the participant hits it.

**AC10**
**Given** the featuring mutation completes
**When** the cache is examined
**Then** `thread/{roomId}/{threadId}` was removed, and a second actor reading the Thread sees the new featured value — proven by a multi-actor scenario.

**AC11**
**Given** a federated Conversation
**When** it is rendered
**Then** the featuring affordance is **hidden** rather than rendered inert, driven by `features-local`. *(AD-18)*

### Story 2.2: The thread list pages by keyset cursor and loads incrementally

As a participant in a room with a thousand Threads,
I want the list to arrive in pages and keep its place as I scroll,
So that opening it does not wait on the whole room and scrolling does not silently skip Threads.

**Acceptance Criteria:**

**AC1**
**Given** the existing `GET .../threads/recent` endpoint
**When** pagination parameters are added
**Then** they are **optional and their defaults reproduce today's response**, so the endpoint grows rather than being duplicated
**And** no parallel thread-list endpoint is introduced. *(AD-12)*

**AC2**
**Given** the total order *(featured, `last_activity` descending, thread id descending)*
**When** a page is addressed
**Then** it is addressed by a **keyset cursor on that tuple**, never by an offset.

**AC3**
**Given** a participant paging through a Conversation where `last_activity` is moving because replies keep arriving
**When** they reach the next page
**Then** no row is repeated and no Thread is silently dropped
**And** an offset-based implementation is demonstrated to fail this scenario, so the test is meaningful.

**AC4**
**Given** a Conversation at the seeded scale of 1000 Threads
**When** the first page is requested
**Then** it returns without the request doing work proportional to the Conversation's total Thread count
**And** the number of queries per page is fixed and independent of page size. *(NFR-1, NFR-2)*

**AC5**
**Given** a participant at the end of the loaded list
**When** they continue scrolling
**Then** the next page is fetched and appended in order. *(FR-17)*

**AC6**
**Given** a Conversation whose Threads all fit in one page
**When** the list renders
**Then** no pagination affordance is shown.

**AC7**
**Given** the client store currently sorts Threads itself
**When** this story lands
**Then** **that client-side sort is removed**, and the store keeps the server's order as an ordered id list and never re-sorts. *(AD-9, AD-15)*

**AC8**
**Given** the seeded fixture and the baseline recorded in Story 1.1
**When** first-page response time is measured again
**Then** it does not regress against that baseline. *(AC-2)*

**AC9**
**Given** four CI database engines including Oracle
**When** the paged query runs
**Then** it produces the same order and the same page boundaries on all four.

### Story 2.3: The thread list filters by Thread State

As a participant opening a room's threads,
I want to see what is live by default,
So that a room's finished discussions stop competing with its current ones.

**Acceptance Criteria:**

**AC1**
**Given** the list endpoint gains an optional state filter parameter
**When** it is omitted
**Then** the response reproduces today's behaviour, preserving the additive contract.

**AC2**
**Given** a participant opening the list fresh
**When** it loads
**Then** it shows Ongoing Threads and every Featured Thread, and no others. *(FR-14)*

**AC3**
**Given** a Featured Thread that is Closed or Locked
**When** the default filter is applied
**Then** it appears with its state shown — featuring is never silently overridden by the filter.

**AC4**
**Given** Closed and Locked Threads that are not Featured
**When** the participant changes the filter
**Then** they become reachable
**And** the filter makes clear that Threads are being **hidden**, rather than leaving the participant to read absence as emptiness.

**AC5**
**Given** a participant who chose a non-default filter and stayed in the Conversation
**When** they navigate within it and return to the list
**Then** their filter choice is still applied.

**AC6**
**Given** the same participant leaving the Conversation and coming back later
**When** the list loads
**Then** the filter has reset to Ongoing — a sticky filter that hides live Threads is a support call.

**AC7**
**Given** a participant who has paged partway down a filtered list
**When** they change the filter
**Then** the cursor is **discarded** rather than paged on with, and the list restarts from the top of the new result set.

**AC8**
**Given** filtering happens in the query rather than in the client store
**When** a filter is applied to a Conversation with 1000 Threads
**Then** it applies across all of them, not only those already loaded. *(FR-17)*

### Story 2.4: The Thread Directory becomes a first-class peer surface

As a participant who wants to find a Thread,
I want the thread list to be its own destination alongside the Conversation's other surfaces,
So that reaching it costs one click instead of three levels inside a tab about files.

**Acceptance Criteria:**

**AC1**
**Given** the threads list today renders in a `v-else-if` on `contentState === 'threads'` that **replaces the whole sidebar tab set**
**When** the Directory is introduced
**Then** it is registered as a **peer** of the Conversation's other sidebar surfaces — reachable by the same navigation, appearing in the same set, and not rendered by replacing them
**And** a participant can move between the Directory and any other surface without an intermediate step. *(FR-12)*

**AC2**
**Given** a participant anywhere in a Conversation
**When** they use the control in the Conversation's top bar
**Then** the Thread Directory opens in one click. *(FR-38)*

**AC3**
**Given** the top-bar control
**When** the server does not declare the thread-management capability
**Then** the control is absent, and present otherwise.

**AC4**
**Given** the top-bar control
**When** a keyboard user reaches it and a screen-reader user encounters it
**Then** it is keyboard reachable and announced with its purpose. *(NFR-8)*

> The control's **unread indication**, the third consequence of FR-38, is completed in Epic 3 Story 3.6. It renders without one until then.

**AC5**
**Given** the Shared items tab, which today carries a three-item thread preview and a "Show more threads" button that is the only entry point to the list
**When** this story lands
**Then** the thread list no longer appears inside Shared items and Shared items contains no thread preview — the old path is removed rather than left as a second one.

**AC6**
**Given** a participant with a Thread open
**When** they open the Directory
**Then** which Thread they have open does not change.

**AC7**
**Given** a narrow or mobile-width viewport
**When** the participant navigates
**Then** the Directory is reachable through the same navigation as the Conversation's other surfaces.

**AC8**
**Given** a Directory row for a Thread that has values for them
**When** it renders
**Then** it shows Thread Title, Thread State, Thread Tags, reply count and last activity. *(FR-16)*

> The row's **Thread Unread Count**, the sixth field of FR-16, is completed in Epic 3 Story 3.6. The row renders without it until then, and shows no indicator rather than a zero.

**AC9**
**Given** a Thread whose title is longer than the row
**When** it renders
**Then** the title truncates without pushing the state, tags or unread indicator out of view.

**AC10**
**Given** a Thread carrying the maximum five Thread Tags
**When** its row renders
**Then** all five render without displacing the other fields.

**AC11**
**Given** a Thread with no value for a field
**When** its row renders
**Then** that field is absent rather than shown empty.

**AC12**
**Given** the Directory is a new surface
**When** it is used
**Then** it is keyboard-navigable, its filters are labelled, and its state indicators follow the host Nextcloud's design tokens rather than introducing colour values. *(NFR-8, NFR-9)*

**AC13**
**Given** the seeded fixture and Story 1.1's baseline
**When** the Directory's first page is measured with rows carrying state, tags, reply count and last activity
**Then** it does not regress against that baseline. *(AC-2)*

**AC14**
**Given** a federated Conversation
**When** it is rendered
**Then** state, featuring and tag affordances are hidden via `features-local` rather than rendered inert. *(AD-18)*

**AC15**
**Given** a Thread Manager viewing a Directory row, and Story 1.8 AC11's row menu on the list this surface replaces
**When** the row renders
**Then** the Directory row carries that same menu — Story 1.4's four transitions plus Story 2.1's feature and unfeature — gated on Story 1.3's store getter, absent and not disabled for a non-manager
**And** the menu carries no affordance the participant cannot use, so a member sees it on Threads they started and not on others' within the same list. *(UJ-1, FR-10)*

> Story 2.7's tag action joins this same menu when tagging ships; it is not a second affordance.

**AC16**
**Given** the seeded Conversation of Story 1.1 with every Thread Ongoing, and the default Ongoing filter of Story 2.3
**When** a moderator works down the Directory closing Threads from AC15's row menus
**Then** the default-filter list shortens as they go, and reducing it to twenty Threads or fewer takes **only row-menu interactions** — no navigation away from the Directory, no bulk tooling and no per-Thread page
**And** the walk is timed and the figure recorded, because this is the evidence PRD §9.3 rests on when it accepts the day-one limitation and declines both bulk close and migration-time reclassification. If the walk is not tolerable at this scale, §9.2's deferred bulk tooling is the answer and this criterion is where that shows. *(AC-1)*

### Story 2.5: Thread and Directory addresses, and the remaining entry points

As a participant who found something worth sharing,
I want a link to a Thread or to the Directory that survives being pasted, reloaded and navigated back to,
So that pointing a colleague at a discussion does not mean telling them where to click.

**Acceptance Criteria:**

**AC1**
**Given** addresses must resolve in both router factories
**When** the Directory and Thread addresses are defined
**Then** **no new named route is added** — they extend the existing `/call/<token>?threadId=…` query scheme, read through a shared composable in the `useGetThreadId` mould
**And** they follow the working precedent in `SearchMessagesTab.vue`, which navigates by the one route name both factories declare. *(AD-16)*

**AC2**
**Given** `createMemoryRouter` backs four entry points — `mainFilesSidebar`, `mainPublicShareSidebar`, `mainPublicShareAuthSidebar` and `mainFloatingCall` — and mounts different components than `createTalkRouter` for the same route name
**When** each address this story introduces is specified
**Then** it declares **Rendered** or **Degraded by design**, recorded here, and an address with neither declaration does not ship. *(FR-42)*

**AC3**
**Given** an address declared **Rendered**
**When** it is accepted
**Then** acceptance is a **mounted-component test** under `createMemoryRouter`, exercised against all four entry points — a route-resolution test proves nothing about what the participant sees.

**AC4**
**Given** an address declared **Degraded by design**
**When** it is opened in a memory-router surface
**Then** the unsupported parameter is dropped and the participant lands on the Conversation's main chat, and the surface omits that entry point cleanly rather than offering a control that fails.

**AC5**
**Given** a Thread is open
**When** the thread header renders
**Then** it offers a route to the Directory alongside the existing route back to the main chat
**And** taking it leaves the Thread open behind the Directory, so dismissing the Directory returns the participant where they were. *(FR-39)*

**AC6**
**Given** a Thread Root Message in the main chat
**When** it renders
**Then** its existing route into the Thread is unchanged, and a **distinct** route opens the Directory positioned on this Thread's row. *(FR-40)*

**AC7**
**Given** a message inside a Thread
**When** its link is copied and opened
**Then** it opens that Thread at that message — the behaviour that exists today, preserved and now covered by a test. *(FR-41)*

**AC8**
**Given** the Thread Directory
**When** its address is copied and opened
**Then** it reopens the Directory on the same Conversation.

**AC9**
**Given** a page with a Thread open
**When** it is reloaded
**Then** that Thread is restored, not the main chat.

**AC10**
**Given** a participant who moved between the main chat, a Thread and the Directory
**When** they use browser back and forward
**Then** they move between those destinations in the order visited.

**AC11**
**Given** `ThreadHeader.vue` is filed under `src/components/RightSidebar/Threads/` but renders in `TopBar.vue` and `ChatView.vue`
**When** this story changes it
**Then** the change is verified in the surfaces it actually renders in.

**AC12**
**Given** Story 1.9 already covers notification navigation with a test written against the query scheme as it stands today
**When** this story consolidates addressing behind the shared composable
**Then** that test still passes and is **re-pointed at the shared composable**, so the old path and the new one do not both survive.

### Story 2.6: Find a Thread by title, in both list surfaces

As a participant who remembers there was a thread about something,
I want to type part of its name — with or without diacritics — and find it,
So that "I know we discussed this" stops being a reason to start a new Thread.

**Acceptance Criteria:**

**AC1**
**Given** matching must not vary by the deployment's database collation
**When** normalisation is implemented
**Then** **one** shared function performs trim, case-fold and diacritic-fold, and writes a stored normalised column for `talk_threads.name` at create and at rename
**And** queries match that column; nothing folds at the call site and nothing relies on collation. *(AD-11)*

**AC2**
**Given** the migration adding the normalised name column
**When** it runs
**Then** it is additive, defaulted, safe to run twice, and existing Threads gain their normalised value on their next rename rather than by a table-wide backfill. *(AD-19)*

**AC3**
**Given** a Thread titled `Định dạng`
**When** a participant searches `dinh dang`
**Then** the Thread is returned — diacritic-insensitive matching is required, not optional, because typing without diacritics is normal for the customer base. *(FR-18)*

**AC4**
**Given** a search term matching part of a title
**When** it is submitted
**Then** matching ignores case and matches any part of the title, not only its start.

**AC5**
**Given** Threads in every Thread State
**When** a search runs
**Then** it covers all of them, and results show state so a match in a Locked Thread is not mistaken for a live one.

**AC6**
**Given** a participant typing in the Directory's search field
**When** characters arrive
**Then** results appear as they type, with no submit action.

**AC7**
**Given** search happens in the query rather than in the client store
**When** a term is entered in a Conversation with 1000 Threads
**Then** it searches all of them, not only those already loaded, and combines with the state filter.

**AC8**
**Given** a title search that returned zero, one or many results
**When** the participant looks for a next step
**Then** a control to search **message bodies** for the same term is present in all three cases, so a wrong-but-nonzero result set does not dead-end. *(FR-19)*

**AC9**
**Given** the participant takes that control
**When** the message search opens
**Then** it opens with the term applied and does not lose the participant's place in the Conversation.

**AC10**
**Given** a title search returning nothing
**When** the empty state renders
**Then** it states the term searched and makes the message-search control the prominent next step.

**AC11**
**Given** the cross-Conversation Followed Thread List in the left sidebar
**When** a participant searches it by title
**Then** it narrows to Threads whose titles match across every Conversation, under the same matching rules as AC3–AC4
**And** each result identifies which Conversation the Thread belongs to. *(FR-20)*

**AC12**
**Given** the Followed Thread List builds its own query
**When** its title filter is implemented
**Then** it filters **inside that query**, not in the client store — two list surfaces filtering at two different layers is the same divergence twice. *(AD-11)*

**AC13**
**Given** four CI database engines including Oracle
**When** normalisation and matching run
**Then** they produce identical results on all four.

### Story 2.7: Thread Tags — apply, remove, and the colour bound to the text

As a moderator whose room runs several kinds of work,
I want to label Threads with free text that always renders the same colour for everyone in the room,
So that one Conversation can be read as two without anyone having to define a taxonomy first.

**Acceptance Criteria:**

**AC1**
**Given** the migration adding two tables — a Conversation-scoped tag row (display name, normalised name, colour, unique on room + normalised name) and a Thread↔tag association carrying no colour
**When** it runs
**Then** it is additive, safe to run twice and backfills nothing.

**AC2**
**Given** `ConversationTagService` already exists but is keyed by `userId` while Thread Tags are shared within the Conversation
**When** `ThreadTagService` is built
**Then** it is a **copy, not an extension** — taking that service's exception vocabulary (`TagNameAlreadyInUseException`, `InvalidTagNameException`, `TagLimitExceededException`) and its cap of twenty
**And** **not** its `normalizeTagName()`, which is private and only trims, and would reproduce exactly the case-and-diacritic duplicates this story forbids.

**AC3**
**Given** the tag tables are a separate aggregate
**When** writes occur
**Then** `ThreadTagService` is their sole writer and does not write thread rows, and `ThreadService` does not write tag rows. *(AD-1, AD-10)*

**AC4**
**Given** a Thread Manager
**When** they add a tag to a Thread and remove one from it
**Then** both succeed, and the added tag is visible to **every participant in the Conversation**. *(FR-23)*

**AC5**
**Given** two Threads carrying the same tag
**When** the tag is removed from one
**Then** the other is unaffected.

**AC6**
**Given** a participant who is not a Thread Manager
**When** they attempt to add or remove a tag
**Then** they are refused — but they still **see** every tag, because only editing is restricted.

**AC7**
**Given** a Thread already carrying five Thread Tags
**When** a sixth is added
**Then** it is refused with an error naming the limit.

**AC8**
**Given** a Conversation already holding twenty distinct Thread Tags
**When** a twenty-first is created
**Then** it is refused with an error that says so and points at the existing tags.

**AC9**
**Given** both caps
**When** they are enforced
**Then** each is one server-side constant enforced **at write time** and published in the capability payload, not restated as an interface literal.

**AC10**
**Given** the first use of a tag text in a Conversation
**When** the actor applies it
**Then** they choose its colour from a **defined palette**, and the colour is stored as an **index into that palette**, never as a free-form hex value. *(FR-24, AD-10)*

**AC11**
**Given** a tag text already used in the Conversation
**When** another participant applies the same text
**Then** it takes the established colour and the participant is not asked for one.

**AC12**
**Given** the tag texts `Escalation` and `escalation`
**When** they are compared for identity
**Then** they are one tag, not two — identity is case-insensitive and uses Story 2.6's shared normalisation helper, not a second fold implementation. *(AD-11)*

**AC13**
**Given** the same tag text in a different Conversation
**When** it is applied
**Then** it is independent and may carry a different colour.

**AC14**
**Given** the palette
**When** any tag renders
**Then** it is legible in light and dark themes at normal and high contrast, its **text always accompanies its colour**, and arbitrary colour input is not accepted. *(NFR-8, NFR-9)*

**AC15**
**Given** a participant typing a tag
**When** input arrives
**Then** matching existing tags in the Conversation are shown with their colours
**And** input matching no existing tag is presented as a **new** tag, visually distinct from picking an existing one, before it is saved. *(FR-26)*

**AC16**
**Given** a participant selecting an existing tag from those suggestions
**When** it is applied
**Then** no colour is asked for.

**AC17**
**Given** tag input with surrounding whitespace, and input that is empty or whitespace only
**When** each is submitted
**Then** the first is stripped and accepted and the second is refused.

**AC18**
**Given** a Thread Tag is user-authored content in a customer Conversation
**When** it travels
**Then** it carries message-grade sensitivity and is withheld wherever a message preview already is. *(AD-20)*

**AC19**
**Given** Thread Tags render on the cached `TalkThreadInfo` representation, while AD-1 makes `ThreadService` the sole owner of the Thread cache and AD-10 makes `ThreadTagService` a separate aggregate that does not write thread rows
**When** a tag is added to or removed from a Thread
**Then** `ThreadTagService` invalidates that Thread's `thread/{roomId}/{threadId}` entry **through an explicit `ThreadService` invalidation method it calls** — the cache stays owned by one service, and the tag aggregate never reaches the cache key itself
**And** the invalidation is `remove()`, never `set()`, exactly as every other mutator in this work. *(AD-1, NFR-4)*

**AC20**
**Given** actor A tags a Thread and actor B is viewing the same Conversation
**When** B reads that Thread
**Then** B sees the tag rather than a stale representation, proven by a **multi-actor** integration scenario — a single actor reads its own fresh value and cannot see the bug. *(NFR-4)*

### Story 2.8: Recolour a tag, and filter the Directory by tag

As a moderator whose room now uses tags,
I want to fix a colour that reads badly and to see only the Threads carrying one tag,
So that tags become the axis the room is organised on rather than decoration.

**Acceptance Criteria:**

**AC1**
**Given** colour is bound to the tag row rather than to each tagging
**When** a Thread Manager changes the colour bound to a tag text in the Conversation
**Then** every Thread carrying that tag in that Conversation updates. *(FR-25)*

**AC2**
**Given** a participant who is not a Thread Manager
**When** they attempt to recolour
**Then** they are refused.

**AC3**
**Given** another participant viewing the Conversation
**When** a recolour lands
**Then** it reaches them **without a reload**, propagated on the relay set. *(NFR-11)*

**AC4**
**Given** the recolour propagates on AD-14's relay set
**When** the relay set and AD-2's Locked exemption list are compared
**Then** the recolour is in the **relay set but not the exemption list** — the relay set is a superset, and a tag recolour must reach participants live without becoming a write that a Locked Thread permits.

**AC5**
**Given** recolouring is the only `updateTag`-shaped operation in v1
**When** this story is scoped
**Then** renaming a tag across Threads, merging near-duplicates and deleting one everywhere are **not** built — they are v2, and FR-26's input suggestion is the v1 mitigation for near-duplicates.

**AC6**
**Given** a participant in the Directory
**When** they select a tag
**Then** the list narrows to Threads carrying it. *(FR-15)*

**AC7**
**Given** a tag filter and a state filter
**When** both are applied
**Then** they combine.

**AC8**
**Given** the Conversation's tag vocabulary
**When** the filter offers tags
**Then** only tags **in use in this Conversation** are offered.

**AC9**
**Given** an applied tag filter
**When** the participant clears it
**Then** the previous list is restored without reloading the Directory.

**AC10**
**Given** the tag filter is a predicate added to the existing query
**When** it is applied
**Then** filtering happens in the query across all Threads in the Conversation, and changing it discards the keyset cursor rather than paging on with it. *(AD-9)*

**AC11**
**Given** the seeded fixture with a tagged subset
**When** the filter is applied to that tag
**Then** it yields **exactly** that subset. *(AC-4)*

**AC12**
**Given** a recolour changes one tag row but the colour renders on **every** Thread carrying that tag, each with its own cache entry
**When** the recolour completes
**Then** the cache entry of every affected Thread in that Conversation is invalidated through Story 2.7 AC19's `ThreadService` method — not only the tag row, and not one Thread
**And** the invalidation is bounded rather than a Conversation-wide sweep, because a tag on the twenty-tag ceiling of a seeded Conversation can carry hundreds of Threads. *(AD-1, NFR-4)*

**AC13**
**Given** actor A recolours a tag and actor B is viewing the same Conversation
**When** B's Directory renders after the relay of AC3 has been consumed **and** again after a fresh page load
**Then** both show the new colour — the relay hides a missed invalidation in the live case and a page load exposes it, so the multi-actor scenario asserts both. *(NFR-4, NFR-11)*

## Epic 3: Per-Thread Unread State

A badge points at where you actually owe a reply. Thread unread becomes genuinely independent: reading one Thread clears that Thread and nothing else, and reading the Conversation's main chat clears nothing in any Thread. A participant can mark one Thread read from its row without opening it, or mark every Thread read — including Threads the active filter is hiding — so a badge is always clearable. Opening a Thread with unread messages lands on the first one, and leaving part-read comes back to the same place.

*Covers FR-27 … FR-32. Governed by AD-6, AD-7, AD-8, AD-9, AD-12, AD-19.*

**The highest-risk epic in this work.** FR-30 is the one recorded exception to the additive-only API rule, and three shipped clients this work does not modify read that field. Story order is constrained by that risk: per-Thread counts exist **before** the Conversation count is split, because splitting first would make thread replies invisible — gone from the Conversation count with no thread badge yet to carry them.

### Story 3.1: Per-Thread read markers, over one lazily frozen fallback baseline

As a Clavis engineer,
I want a read marker per participant per Thread that exists only for Threads they have actually read,
So that per-Thread unread is affordable and a new member does not walk into a room with five hundred old Threads and five hundred unread badges.

**Acceptance Criteria:**

**AC1**
**Given** the migration reinstating columns upstream created and dropped three weeks later
**When** it runs
**Then** `talk_thread_attendees` gains `last_read_message` and `last_mention_message` — **two columns, not three**; `last_mention_direct` stays dropped because the requester settled the client split as two-way and it has no reader
**And** the migration carries a comment stating **why** the columns are back, so whoever merges the next upstream release does not read them as an accident. *(AD-6, AD-17)*

**AC2**
**Given** the migration also adds a nullable thread-read baseline column to `talk_attendees`
**When** it runs
**Then** it ships `NULL` for every row and **no table-wide `UPDATE` runs at upgrade time**
**And** the whole migration is additive, defaulted and safe to run twice. *(AD-19)*

**AC3**
**Given** a participant who has never read a given Thread
**When** they join the Conversation, or open the Conversation, or read its main chat
**Then** **no** `talk_thread_attendees` row is created for that Thread — a row is written **only when they read that Thread**. *(NFR-3)*

**AC4**
**Given** the seeded fixture of Story 1.1
**When** row counts are taken after seeding and again after a scripted read pass and compared against participants × Threads
**Then** growth tracks Threads actually read, not Threads that exist. *(AC-5)*

**AC5**
**Given** a participant who leaves a Conversation
**When** the departure is processed
**Then** their per-Thread rows for that Conversation are reclaimed
**And** a participant who leaves and rejoins inherits no stale per-Thread read state.

**AC6**
**Given** `ParticipantService::updateLastReadMessage()` is the single choke point every advance of the conversation read marker passes through
**When** **any** advance occurs — including the two of its six callers that are *posts* rather than reads
**Then** the **pre-advance** value is frozen into the thread-read baseline if that baseline is still `NULL`, before `last_read_message` advances. *(AD-6)*

**AC7**
**Given** a participant whose very first action in a Conversation is a reply inside a Thread
**When** that reply advances their conversation marker
**Then** the baseline captured is the **pre-advance** value, so their never-opened Threads do **not** all resolve as read
**And** this scenario exists as a test, because a freeze wired to "reading the main chat" instead would produce permanently zero badges — the exact defect this design prevents.

**AC8**
**Given** the `UNREAD_MIGRATION` repair in `RoomFormatter`, which reconstructs a legacy marker rather than reflecting a read
**When** it advances the marker
**Then** it does **not** freeze the baseline — it is the one and only exemption.

**AC9**
**Given** a Thread with no `talk_thread_attendees` row and a baseline that is still `NULL`
**When** its unread is resolved
**Then** it falls back to `talk_attendees.last_read_message`, which is correct on day one and costs no migration write.

**AC10**
**Given** a `talk_thread_attendees` row can now be created by *reading*
**When** subscription is expressed
**Then** it lives in its **own explicit column**, never inferred from row existence and never from a `notification_level` value
**And** that column ships `NOT NULL DEFAULT true`, because every pre-existing row was created by a reply or a level change and defaulting to `false` would unsubscribe every existing follower at upgrade while no repair `UPDATE` is permitted. *(AD-7)*

**AC11**
**Given** `getRecentByActor()` — the Followed Thread List query — today excludes only `NOTIFY_NEVER`, while `findAttendeesForNotification()` excludes `NOTIFY_DEFAULT`
**When** read-created rows are introduced
**Then** `getRecentByActor()` gains the subscription predicate **in the same change**, not afterwards
**And** `findAttendeesForNotification()` keeps its own filter unchanged.

**AC12**
**Given** the existing assertion at `threads.feature:224` that a `notificationLevel = 0` Thread appears under subscribed threads
**When** the suite runs
**Then** it still passes — the reply-created case is unchanged.

**AC13**
**Given** a participant who has only ever *read* a Thread and never replied to it
**When** their Followed Thread List is fetched
**Then** that Thread does **not** appear — asserted by a new scenario beside AC12's.

**AC14**
**Given** `ThreadAttendee::jsonSerialize()` exposes only `notificationLevel` today
**When** new fields are added
**Then** they are added to every serialisation site in the same change, and survive a cache round trip. *(AD-1)*

### Story 3.2: Each Thread reports a Thread Unread Count

As a participant returning after leave,
I want each Thread to tell me how many messages in it I have not read,
So that I can see which three Threads moved without reading everything.

**Acceptance Criteria:**

**AC1**
**Given** a Thread the participant has read to its end
**When** its unread is reported
**Then** it is zero. *(FR-27)*

**AC2**
**Given** a Thread containing three messages the participant has not seen
**When** its unread is reported
**Then** it is three.

**AC3**
**Given** a Thread containing messages the participant wrote themselves
**When** its unread is counted
**Then** their own messages are excluded, matching Conversation-level behaviour.

**AC4**
**Given** a Thread containing a rename or a state-change system message
**When** its unread is counted
**Then** system messages are excluded, so a rename does not read as an unread reply.

**AC5**
**Given** two participants who have read different amounts of the same Thread
**When** each fetches it
**Then** each gets their own count.

**AC6**
**Given** a participant who has never opened a Thread whose activity predates their joining the Conversation
**When** its unread is resolved
**Then** it reports **zero** rather than the Thread's full length, derived from their conversation read marker or frozen baseline per Story 3.1.

**AC7**
**Given** a page of Threads in a Conversation holding 1000 of them
**When** unread is resolved for that page
**Then** it resolves in a **fixed number of queries independent of page size** — one grouped query over the page's thread ids — not one query per rendered row. *(AD-9, NFR-2)*

**AC8**
**Given** the display cap that lets a client render a "99+" form
**When** counting runs
**Then** it stops at **cap + 1**, not at the cap — a client receiving exactly the cap cannot tell a full page from an overflowing one.

**AC9**
**Given** the seeded fixture and Story 1.1's baseline
**When** the first page is measured with unread counts included
**Then** it does not regress against that baseline. *(AC-2)*

**AC10**
**Given** four CI database engines including Oracle
**When** the grouped counting query runs
**Then** it produces identical counts on all four.

### Story 3.3: Conversation unread splits, and thread unread becomes independent

As a participant,
I want reading one Thread to clear that Thread and nothing else, and reading the main chat to clear nothing in any Thread,
So that a badge means something instead of being cleared by whatever I happened to open.

*This story carries the single most dangerous change in the work. It redefines the meaning of a shipped API field that three unmodified clients read, and the mention indication is currently computed **from** that field.*

**Acceptance Criteria:**

**AC1**
**Given** a second capability flag, separate from the thread-management flag of Story 1.2
**When** it is declared
**Then** it appears in both `FEATURES` and `LOCAL_FEATURES`, and a client can tell which unread semantics the server uses **independently** of whether thread management is present. *(FR-30, AD-12)*

**AC2**
**Given** Talk's `CommentsManager` already applies a `topmost_parent_id` filter in its *read* override but has no counting equivalent
**When** the split is implemented
**Then** a **new counting override** mirrors that filter, and `unreadMessages` counts non-thread messages only. *(AD-8)*

**AC3**
**Given** a Conversation whose only unread messages are thread replies
**When** it is formatted
**Then** it reports zero unread messages.

**AC4**
**Given** the `unreadMessages !== 0` gate on `unreadMention` and `unreadMentionDirect` in `RoomFormatter`
**When** the split lands
**Then** that gate is removed — **and so is the `lastReadMessage === lastMessage` short-circuit immediately above it**, which becomes wrong once the last message may be a thread reply.

**AC5**
**Given** a participant mentioned **only inside a Thread**, with a main-chat unread count of zero
**When** the Conversation is formatted
**Then** they are still reported as mentioned
**And** a test asserts the mention flag **with a zero main-chat count**, because the regression is not "the count is wrong" but "a thread mention stops being a mention".

**AC6**
**Given** a participant mentioned in the main chat
**When** the Conversation is formatted
**Then** they are reported as mentioned exactly as they are today.

**AC7**
**Given** `ChatManager` today advances the conversation read marker on **every** post
**When** a participant posts a thread reply
**Then** it **stops** advancing the conversation marker and advances that Thread's own marker instead
**And** a scenario asserts that posting a thread reply does not mark unread *main-chat* messages read — the split leaking backwards.

**AC8**
**Given** six sites invalidate the unread-count cache by room-id **prefix**
**When** the cache key gains its semantics token
**Then** the token is **appended**, never prepended — prepending silently disables all six and leaves counts wrong for the full 1800s TTL
**And** the prefix is treated as part of the key contract, not an implementation detail.

**AC9**
**Given** unread messages in Threads A and B
**When** the participant reads A
**Then** B's count is unchanged. *(FR-28)*

**AC10**
**Given** unread messages in Thread A, which the participant has read before
**When** they read the Conversation's main chat to its end
**Then** A's count is unchanged.

**AC11**
**Given** unread messages in Thread A
**When** the participant reads A
**Then** the Conversation's main-chat unread count does not change; and when they read the main chat, it does.

**AC12**
**Given** a Thread with a non-zero count
**When** it returns to zero
**Then** it did so only because **that participant read that Thread to its end** — reading the Conversation's main chat, reading a different Thread, and any other participant's activity all leave it untouched. At this point in the epic that is the only path to zero.

**AC13**
**Given** pre-upgrade cache entries written under the old semantics
**When** they are read after upgrade
**Then** they are not read with post-upgrade semantics, because AC8's token distinguishes them.

**AC14**
**Given** an unmodified Android, iOS or desktop client
**When** it talks to this server
**Then** it degrades to current behaviour rather than erroring, gated on AC1's capability
**And** any support report of a wrong Conversation badge is treated as a regression in this story until shown otherwise, not as a client bug. *(AC-W1, NFR-7)*

### Story 3.4: Mark a Thread read, and mark every Thread read

As a participant with a badge raised by a Thread I do not intend to open,
I want to clear it from the list,
So that unread state stays trustworthy instead of becoming something I learn to ignore.

**Acceptance Criteria:**

**AC1**
**Given** a Thread with unread messages listed in the Directory
**When** the participant marks it read from its row
**Then** its count returns to zero without the Thread being opened. *(FR-29)*
**And** this becomes the **second and final** path to zero, alongside reading the Thread to its end per Story 3.3 — Story 3.3's AC12 is re-asserted with both paths and still admits no third.

**AC2**
**Given** a Conversation with unread Threads, some of them hidden by the active filter
**When** the participant marks every Thread read
**Then** **every** Thread is cleared including the hidden ones, so a badge raised by a Closed Thread is always clearable.

**AC3**
**Given** subscription and read state live on the same `talk_thread_attendees` row
**When** mark-all runs
**Then** it **resets the read columns and never deletes the row** — deleting a row to clear its badge would silently unfollow the Thread. *(AD-6, AD-7)*

**AC4**
**Given** the thread-read baseline on `talk_attendees`
**When** mark-all-threads-read runs
**Then** the baseline advances — this is the **only** thing that moves it after Story 3.1 freezes it, and reading the main chat never does.

**AC5**
**Given** a participant marks a Thread read
**When** other participants observe the Thread
**Then** nothing changed for them: marking read does not alter Thread State and does not appear as activity.

**AC6**
**Given** a mark-read mutation
**When** it completes
**Then** the affected cache entries are invalidated, and a second actor's view is not served a stale count.

**AC7**
**Given** the mark-read operations do not exist as endpoints today
**When** they are added
**Then** they are **new endpoints** for operations that do not exist, rather than a redefinition of an existing one, and `openapi*.json` and the generated TypeScript types are regenerated in the same change. *(AD-12)*

### Story 3.5: The Conversation reports whether it has unread Threads

As a participant scanning my conversation list,
I want a room to tell me it holds an unread Thread even when its main chat is fully read,
So that the split between the two kinds of unread does not hide one of them.

**Acceptance Criteria:**

**AC1**
**Given** a Conversation containing at least one Thread with unread messages for the current participant
**When** it is formatted
**Then** it reports a thread-unread indication, separate from its main-chat unread count; and reports none when no Thread does. *(FR-31)*

**AC2**
**Given** the two-way client split settled by the requester
**When** the indication is reported
**Then** it distinguishes **unread replies in Threads** from **unread mentions in Threads**
**And** direct and group mentions are **not** distinguished — which is why `last_mention_direct` did not return and `has_unread_thread_directs` keeps its zero callers.

**AC3**
**Given** three `talk_attendees.has_unread_thread*` columns are already migrated and currently uncalled
**When** this story lands
**Then** **two** of them gain their first callers and **no migration is needed**.

**AC4**
**Given** a Conversation whose main chat is fully read but which holds an unread Thread
**When** it is formatted
**Then** it still reports the thread-unread indication.

**AC5**
**Given** a Conversation with unread main-chat messages and no unread Threads
**When** it is formatted
**Then** it reports no thread-unread indication.

**AC6**
**Given** an unread Thread hidden under the Directory's default filter
**When** the indication is computed
**Then** that Thread raises it just as a visible one would.

**AC7**
**Given** a Conversation reporting the thread-unread indication
**When** the participant uses mark-all-threads-read from Story 3.4
**Then** the indication clears — this is the guaranteed way to clear it.

**AC8**
**Given** the Conversation list in the left sidebar
**When** a Conversation carries both a main-chat unread badge and a thread-unread indication
**Then** the two are surfaced without duplicating or contradicting each other.

**AC9**
**Given** the seeded fixture
**When** both unread states are exercised in both directions — no main-chat unread with an unread Thread, and main-chat unread with no unread Thread
**Then** neither reads as a bug. *(AC-6)*

### Story 3.6: Unread state in the Directory and the Followed Thread List

As a participant,
I want the count on the row and the view to open where I stopped reading,
So that a badge tells me where to go and going there does not cost me my place.

**Acceptance Criteria:**

**AC1**
**Given** a Thread with unread messages
**When** its Directory row renders
**Then** the count is shown. *(FR-32)*

**AC2**
**Given** the same Thread
**When** its Followed Thread List row renders
**Then** the count is shown there too.

**AC3**
**Given** a Thread with no unread messages
**When** either row renders
**Then** no indicator is shown at all — not a zero.

**AC4**
**Given** either list is open
**When** messages arrive and as the participant reads
**Then** counts update and clear without a reload.

**AC5**
**Given** a count above the display bound
**When** the row renders
**Then** it renders as a capped indicator rather than widening the row, using the cap + 1 signal from Story 3.2.

**AC6**
**Given** a Thread containing an unread mention of the participant, and another containing only unread replies
**When** both rows render
**Then** the two are visually distinguished from each other.

**AC7**
**Given** a Thread with unread messages
**When** the participant opens it
**Then** the view is positioned at the **first unread message**, with a visible boundary marking where unread begins
**And** that boundary does not jump as new messages arrive.

**AC8**
**Given** a participant who leaves a Thread part-read
**When** they reopen it
**Then** they return to the same first-unread message, not to the end.

**AC9**
**Given** Epic 2 Story 2.4 left the Directory row's unread field unrendered
**When** this story lands
**Then** FR-16's sixth row field is complete.

**AC10**
**Given** Epic 2 Story 2.4 left the top-bar control without its unread indication
**When** this story lands
**Then** the control indicates when the Conversation has Threads with unread messages, and a screen reader announces that state along with the control's purpose — completing FR-38. *(NFR-8)*

**AC11**
**Given** the seeded fixture with unread messages spread across many Threads
**When** a participant uses only the Directory to reach an unread Thread and answer in it
**Then** featured Threads at the top, unread counts on rows, title search and first-unread landing are together sufficient
**And** reading that Thread clears its badge and no other, and reading the main chat clears none of them. *(AC-3)*

**AC12**
**Given** unread indication must not rely on colour alone
**When** counts and mention markers render
**Then** each carries a number or a label as well as any tint, legible in light and dark at normal and high contrast. *(NFR-8, NFR-9)*

## Epic 4: Thread-Aware Notifications

A notification says which Thread it came from, so it can be triaged without being opened. Followers of a Thread learn when it is Closed, Locked or reopened — and why, when a reason was given — rather than discovering it by trying to post and failing. Push payloads carry the same identifiers the in-app notification already carries, so a client routes into the right Thread without a second network call.

*Covers FR-33, FR-34, FR-35 and FR-37. Governed by AD-12, AD-14, AD-20.*

**Honest scope.** Only Stories 4.1 and 4.2 produce a user-visible change on a client this work touches. Web and desktop deep-linking into Threads already works, and Android already reaches Threads from a push by fetching the notification over the API — the reported symptom of landing in the main chat originates in **that client's fallback path when the fetch fails**. Story 4.3 removes a network round trip and enables the mobile epic; it does not, by itself, change what any user sees. Reading this epic as "issue item 8 is done" is a misread.

**FR-36 lives in Epic 1 Story 1.9.** Following a notification into a Thread is pinned under test there, deliberately ahead of both Epic 2's addressing rework and Story 4.3's payload change, so neither can silently break navigation. This epic inherits that guard rather than establishing it.

### Story 4.1: Thread notifications name the Thread

As someone whose phone lights up during a meeting,
I want the notification to say which Thread it came from,
So that I can decide whether it matters without opening it.

**Acceptance Criteria:**

**AC1**
**Given** `lib/Notification/Notifier.php:275` gates exactly nine subjects that route to `parseChatMessage()` — `reply`, `mention`, `mention_direct`, `mention_group`, `mention_team`, `mention_all`, `chat`, `reaction` and `reminder`
**When** each is emitted for activity inside a Thread
**Then** it carries the Thread Title
**And** there is **a test per subject**, not one test for the family — the first revision covered two of the nine. *(FR-33)*

> The definitive enumeration is the condition at `Notifier.php:275`, not the prose list in PRD §4.8, which names eight by omitting the generic `mention` subject.

**AC2**
**Given** a notification for activity outside any Thread
**When** it is built
**Then** it is unchanged and gains no thread text.

**AC3**
**Given** `lib/Chat/Notifier.php:628-638` already puts `threadId` into message parameters when non-null and non-zero
**When** the Thread Title is added
**Then** it is read from that parameter data rather than re-derived, and no second lookup is introduced on the notification path.

**AC4**
**Given** a Thread Title too long for the space available
**When** the notification renders
**Then** the title is truncated with an indication
**And** the truncation does not remove the message preview entirely.

**AC5**
**Given** a Conversation the recipient has marked sensitive
**When** a notification for in-thread activity is built
**Then** the Thread Title is withheld along with the other content already withheld, **reusing the shipped sensitive-conversation mechanism rather than paralleling it**. *(AD-20)*

**AC6**
**Given** the Thread Title is user-authored content
**When** it is rendered in any notification surface
**Then** it is never translated, while the surrounding notification text is translatable. *(NFR-10)*

### Story 4.2: Thread lifecycle changes notify a Thread's followers

As someone following a Thread,
I want to be told when it is closed, locked or reopened,
So that I do not learn a Thread is shut by writing a reply and having it refused.

**Acceptance Criteria:**

**AC1**
**Given** a participant subscribed to a Thread
**When** a Thread Manager locks it
**Then** they receive a notification naming the Thread, naming who locked it, and carrying the reason where Story 1.4 supplied one. *(FR-34, FR-43)*

**AC2**
**Given** the same participant
**When** the Thread is closed, and when it is reopened
**Then** each transition notifies them on the same terms.

**AC3**
**Given** the recipient set is already computed by `ThreadService::findAttendeesForNotificationByThreadId()`
**When** lifecycle notifications choose who to notify
**Then** they **reuse that method** rather than inventing a recipient set
**And** the subscription logic at `lib/Chat/Notifier.php:253-293` is left unchanged.

**AC4**
**Given** a participant who has muted the Thread through its per-Thread notification level
**When** a lifecycle change occurs
**Then** they are not notified.

**AC5**
**Given** a participant who is not subscribed to the Thread
**When** a lifecycle change occurs
**Then** they are not notified.

**AC6**
**Given** a Closed Thread revived by someone posting into it, per Story 1.5
**When** notifications are generated
**Then** the ordinary reply notification is produced and **no** additional state-change notification — reopening by reply emits no state system message to notify about.

**AC7**
**Given** the lock reason is user-authored content
**When** the notification is built for a sensitive Conversation
**Then** the reason is withheld exactly where the Thread Title is withheld. *(AD-20)*

**AC8**
**Given** the reason is carried on the locking system message as parameter data
**When** the notification builder reads it
**Then** it reads that **parameter**, never re-parsing a rendered message string. *(AD-14)*

**AC9**
**Given** a Locked Thread
**When** a subscribed participant attempts to post and is refused
**Then** that refusal is not the first they learn of the lock — this notification preceded it.

### Story 4.3: Push payloads carry the same identifiers as in-app notifications, and fit

As a mobile client,
I want the push payload itself to tell me which message in which Thread,
So that routing does not depend on a second network call that can fail.

*Groundwork for the mobile epic. It removes a round trip and a fallback failure mode; it does not change what any user sees on a client this work touches.*

**Acceptance Criteria:**

**AC1**
**Given** the two `setObject` calls **append**, producing `{token}/{messageId}/{threadId}`, and today a guard skips **both** whenever the payload being built is a push payload — so a push object id is the bare room token
**When** the guard is corrected
**Then** a push payload for in-thread activity contains **both** the message identifier and the Thread identifier, in the same composed form the in-app notification uses, so a client parses one shape. *(FR-35)*

**AC2**
**Given** a push payload for activity **outside** any Thread
**When** it is built
**Then** it contains the message identifier, which it does not today.

**AC3**
**Given** the existing integration assertion at `threads.feature:223` on the value `room1/Message 2/Thread 1`
**When** the suite runs
**Then** it still passes — the in-app identifiers are unchanged.

**AC4**
**Given** the composed identifier is variable-arity and absence-significant, and shipped Android and iOS clients parse it **by position**
**When** the payload is constructed
**Then** a trailing position may be appended but never reordered or shortened; a position is either present with a real value or **absent entirely** — never padded, never a sentinel, and `0` is never valid at any position. *(AD-12)*

**AC5**
**Given** a client that receives the shorter value because the activity was not in a Thread
**When** it parses positionally
**Then** it does not misread the shorter value as carrying a thread identifier.

**AC6**
**Given** a Conversation the recipient has marked sensitive
**When** a push payload is built for in-thread activity
**Then** it carries the identifiers but **no Thread Title and no lock reason** — an identifier names a destination without disclosing what is in it, so a client can route without leaking content. *(AD-20)*

**AC7**
**Given** the Clavis push proxy relays material the customer's server encrypted for the device and cannot read it
**When** this story lands
**Then** nothing it adds moves a Thread Title or lock reason into any part of the payload the proxy can inspect.

**AC8**
**Given** the platform notifications app encrypts each payload for the target device and bounds the plaintext
**When** the size budget is established
**Then** it is **measured against that app's actual limit**, never inferred from the 100-character preview truncation at `Notifier.php:686-689`. *(FR-37)*

**AC9**
**Given** a notification for a Thread with a maximum-length title and a maximum-length message preview
**When** it is delivered
**Then** it fits within the budget.

**AC10**
**Given** a title and a preview that cannot both fit
**When** the payload is assembled
**Then** the **Thread Title is preserved and the message preview is shortened**, because the title is what makes the notification triageable.

**AC11**
**Given** Vietnamese text in a title or preview
**When** truncation is applied
**Then** it falls on character boundaries valid for that text, never mid-character. *(NFR-10)*

**AC12**
**Given** the Clavis push proxy's APNs/FCM hop is unimplemented and blocked on an Apple `.p8` key and a Firebase project
**When** this story is accepted
**Then** acceptance is at **payload construction**, and **no on-device verification is claimed or assumed** — `NotImplementedSender` fails loudly rather than pretending, and downstream planning must not assume a device test exists.
