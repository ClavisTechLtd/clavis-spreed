---
name: 'Adversarial Seam Review — ARCHITECTURE-SPINE.md'
type: architecture-review
lens: 'adversarial-seams'
target: '_bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md'
repo: 'clavis-spreed @ stable34, commit 6819859 (Talk 24.0.3)'
reviewed: '2026-08-08'
re-reviewed: '2026-08-08 (against spine r2 — AD-2, AD-6, AD-7, AD-14, AD-16, AD-19 rewritten; AD-20 added)'
verdict: 'Sharper than r1 and three Critical holes are genuinely closed — but 13 of 17 collisions remain open, and AD-14''s amendment makes one of them reachable that was previously masked.'
collisions: 17
open: 13
closed: 4
---

# Adversarial Seam Review — Clavis Talk Thread Management Spine

## Method

The lens is construction, not critique. For each seam I built two epic-level implementations that each obey **every AD as written**, then showed where they collide and what the user sees. A finding that could not be stated as a collision scenario was discarded — several candidates were, and are listed in *Rejected candidates* at the end so the next reviewer does not re-derive them.

The epic split assumed, from the driving PRD:

- **E1** Thread lifecycle + management authority + the Locked write guard (§4.1, §4.2)
- **E2** Thread Directory + search + Featured Threads + Thread Tags (§4.3–§4.6)
- **E3** Per-Thread unread + the conversation unread split (§4.7)
- **E4** Thread-aware notifications (§4.8)
- **E5** Navigation entry points and routing (§4.9)

Every claim below was checked against the code at `stable34` / `6819859`. Line references are to that commit.

**Verdict.** The spine is unusually good at the level it operates: the ownership seams are named, the dependency direction is drawn, and most ADs prevent something real. It is not yet safe to hand to five epics, because four of its load-bearing claims are wrong or unsatisfiable against the actual code (AS-1, AS-2, AS-3, AS-4), and a further six seams are simply unassigned — two epics can each pick a defensible answer and ship incompatible halves. The fixes are all local: eleven new or tightened AD clauses, no restructuring.

Findings are ordered by severity, then by blast radius.

---

## Status against spine r2

The spine changed while this review was being written. Re-read at r2; below is what moved.

**Genuinely closed — 4.**

| # | Closed by | Verdict on the new rule |
| --- | --- | --- |
| AS-4 | AD-7 rewritten | **Closes it, and closes it better than I proposed.** My fix was to change `getRecentByActor()` to `!= NOTIFY_DEFAULT`. That fix was wrong: `tests/integration/features/chat-4/threads.feature:224` asserts a **reply**-created row at `notificationLevel = 0` *does* appear under subscribed threads, so my predicate would have broken a shipped behaviour. An explicit subscription column is the only rule that separates reply-created from read-created rows. One residual — see AS-17. |
| AS-11 | AD-19 + AD-6 rewritten | **Closes it.** `NULL` column, no table-wide `UPDATE`, `COALESCE`-equivalent fallback. The self-contradiction is gone. The lazy freeze it replaces the backfill with opens a new seam — see AS-10, now escalated. |
| AS-14 | AD-16 rewritten | **Largely closes it.** Naming all four memory-router entry points and the `SearchMessagesTab.vue` precedent gives the acceptance condition teeth. Residual: "resolve" still is not defined as "render". The two factories mount different components for the `conversation` route (`MainView` vs `ChatView` + `isSidebar`), so a Directory built as a sidebar peer per FR-12 has nowhere to mount in any of the four. The rendered/degraded declaration proposed in AS-14 is still needed. |
| — | AD-20 added | Not a collision I constructed; it closes a real gap (Thread Titles as message-grade content) that I had not reached. No objection. |

**Still open, unchanged — 11.** AS-1, AS-2, AS-5, AS-6, AS-7, AS-8, AS-9, AS-12, AS-13, AS-15 are untouched by r2. AS-10 is open and **escalated** by AD-6's lazy freeze.

**Open and made worse by r2 — 1.** AS-3. See the section, rewritten below.

**New in r2 — 2.** AS-16 (AD-2's third seam has no owner) and AS-17 (AD-7's new column has no upgrade default, and AD-19 forbids the fix).

---

## AS-1 — AD-1 and AD-10 name two different owners for the tag tables

**Severity: Critical.** Direct contradiction between two ADs. Both epics cannot be right and there is no third reading.

### The two permitted implementations

AD-1 says, verbatim:

> No code outside `lib/Service/ThreadService.php` calls a write method on `ThreadMapper`, `ThreadAttendeeMapper` **or the new tag mappers**.

AD-10 says a new `ThreadTagService` is created — `ConversationTagService` "is copied, not extended" — and the *Where the work lands* tree lists:

```text
Service/ThreadTagService.php         # new — room-scoped tag vocabulary + association (AD-10)
```

**E2, reading AD-10 as the specific rule that wins over AD-1's general one:** builds `ThreadTagService` with `createTag()`, `assignTag()`, `removeTag()`, `setTagColour()`, each calling `ThreadTagMapper` / `ThreadTagMapMapper` directly. This is what "copied from `ConversationTagService`" means — that class writes `ConversationTagMapper` itself. Obeys AD-10 literally, obeys the file tree literally, violates AD-1's parenthetical.

**E2 (or a later epic touching tags), reading AD-1 as the aggregate-boundary invariant that AD-10 must live inside:** builds `ThreadTagService` as validation, normalisation and cap enforcement only, and routes every write back through `ThreadService::applyTag()` / `ThreadService::setTagColour()` so the cache re-set in AD-1 fires. Obeys AD-1 literally. Makes AD-10's service a shell that owns no data.

### Where they collide

`talk_thread_tag_map` and `talk_thread_tags` get two write paths with two different cache disciplines. The tag write path that does not go through `ThreadService` never touches `thread/{roomId}/{threadId}`.

### Observable symptom

FR-25 — a Thread Manager changes the colour bound to `escalation` in a Conversation. `ThreadTagService` updates `talk_thread_tags.colour` directly. The actor's own next request re-reads through the mutation response and shows the new colour. Every other participant's Directory reads Threads through `ThreadService::findByThreadId()`, whose cache entry is 900 seconds old (`ThreadService.php:63-79`, TTL `60 * 15`) and still carries the old colour if tags were folded into `Thread::toJson()` per AD-1's field checklist. For up to fifteen minutes the same tag renders in two colours for two people in the same room — precisely the defect AD-10 says it prevents ("the same tag renders in two colours in two rows"), arriving through the cache instead of through the schema.

The symmetric failure if E2 picks the second reading: nothing breaks, but AD-10's "copied, not extended" instruction produces a class with no `insert`/`update` calls, and the next epic that needs a tag write has no precedent to follow and picks the first reading.

### Proposed AD text

Amend **AD-1**, replacing the parenthetical:

> No code outside `lib/Service/ThreadService.php` calls a write method on `ThreadMapper` or `ThreadAttendeeMapper`. `ThreadTagMapper` and `ThreadTagMapMapper` are owned by `ThreadTagService` and written from nowhere else — the tag vocabulary is a second aggregate, not part of the Thread aggregate.

Add to **AD-10**:

> Because tags are a second aggregate, a tag mutation that changes what a Thread *renders* must invalidate the Thread aggregate too. Tags are **not** carried in `Thread::toJson()` and are **not** in the `thread/{roomId}/{threadId}` cache entry; the Directory joins them per page under AD-9's fixed query budget. Any future decision to cache tags on the Thread entry requires a fan-out invalidation and must be recorded as an amendment to this AD, not made at the call site.

---

## AS-2 — AD-6's "clears existing rows" destroys the subscription state AD-7 depends on

**Severity: Critical.** AD-6 mandates a destructive action; AD-7 assumes the destroyed data survives. Both are in the spine.

### The two permitted implementations

AD-6's diagram is explicit:

```text
H["Mark all threads read, FR-29"] --> I["advances thread_read_baseline
and clears existing rows"]
```

**E3 implements FR-29 exactly as drawn:** mark-all-threads-read advances `talk_attendees.thread_read_baseline` and issues `DELETE FROM talk_thread_attendees WHERE actor_type = ? AND actor_id = ? AND room_id = ?`. Deleting is the only way to make every Thread fall back to the new baseline in one statement, and AD-6 says to do it. Obeys AD-6 to the letter.

**E4 relies on those same rows for notification targeting,** per AD-7 — `findAttendeesForNotification()` (`ThreadAttendeeMapper.php:94-113`) reads `talk_thread_attendees` and `notifyOtherParticipant()` (`Notifier.php:259-263`) uses it to pull in participants who subscribed at thread level but not conversation level. Obeys AD-7 to the letter.

### Where they collide

`talk_thread_attendees` is a single row carrying **two** independent facts: `notification_level` (subscription, written by `ThreadService::setNotificationLevel()`) and — after AD-6 — `last_read_message` / `last_mention_message` (read state). AD-7's slogan, "a row means *has state*, never *is subscribed*", is false about the row's own contents: `notification_level` is exactly subscription and it is on that row. Deleting the row to clear read state deletes the subscription with it.

### Observable symptom

A participant sets **Always notify** on the three Threads they care about (`ThreadController::setNotificationLevel`, `ThreadController.php:354`). Later they clear their badges with mark-all-threads-read from the Directory. Their rows are deleted. They are now unsubscribed from all three Threads: `findAttendeesForNotification()` returns nothing for them, so `notifyOtherParticipant()` no longer pulls them in; and their Followed Thread List (`getRecentByActor`, `ThreadService.php:140-172`) is empty because it joins on the same table. FR-29's own consequence — "Marking read does not change Thread State and does not appear as activity to other participants" — is satisfied, so the test passes. The user silently stops being notified about the Threads they explicitly asked to be notified about, and has no way to discover why.

### Proposed AD text

Replace the clearing clause in **AD-6**:

> A `talk_thread_attendees` row carries two independent facts: `notification_level`, which is subscription and is written only by `ThreadService::setNotificationLevel()`; and `last_read_message` / `last_mention_message`, which are read state. **No operation deletes a row to clear read state.** Mark-all-threads-read (FR-29) advances `talk_attendees.thread_read_baseline` and, in the same transaction, advances `last_read_message` on the participant's existing rows to `talk_threads.last_message_id`; it deletes nothing. The only deletions of this table remain `deleteByRoomId()` and `removeThreadAttendeesByAttendeeIds()` (participant removed from the Conversation), both of which are correct because they remove the participant, not their state.

Amend **AD-7**'s rule to state the invariant it actually needs:

> A row's *existence* never implies subscription; only `notification_level` does. Conversely, no operation may drop `notification_level` while editing read state, and none may drop read state while editing `notification_level`. Every writer of this table updates exactly the columns it owns.

---

## AS-3 — AD-14 now names six registries; `notifySystemMessageSent()` contains **three** gates and the amendment names two

**Severity: Critical, and r2 raised it.** In r1 this hole was masked: a new lifecycle verb died at the first gate, so it never reached the third. AD-14's amendment fixes the first gate, which makes the third gate's bug reachable for the first time. The spine's own correction is what arms it.

**This one is a "no" to the lead's re-check.** The sixth registry AD-14 now names is **not** the one this section found. They are two different arrays, at two different lines, doing two different jobs, inside one method.

### The three gates in `notifySystemMessageSent()`

Verified at `lib/Signaling/Listener.php:551-583`:

```php
protected function notifySystemMessageSent(ASystemMessageSentEvent $event): void {
    ...
    // GATE 1 — line 557-559 : the early return. AD-14 registry 5 names this.
    if ($event->shouldSkipLastActivityUpdate() === true
        && !in_array($messageType, ['message_deleted', 'message_edited', 'thread_created', 'thread_renamed'], true)
    ) {
        return;
    }
    ...
    // GATE 2 — line 571 : the relay list. AD-14 registry 5 names this too.
    if (!in_array($messageType, self::SYSTEM_MESSAGE_TYPE_RELAY, true)) {
        $this->externalSignaling->sendRoomMessage($room, $data);
        return;
    }

    // GATE 3 — line 577 : decides whether the relayed payload carries the Thread.
    //          AD-14 does NOT name this.
    $thread = null;
    if ($messageType === 'thread_created' || $messageType === 'thread_renamed') {
        $threadId = (int)$comment->getTopmostParentId() ?: $comment->getId();
        try {
            $thread = $this->threadService->findByThreadId($room->getId(), (int)$threadId);
        } catch (DoesNotExistException) {
        }
    }
    ...
    $data['chat']['comment'] = $message->toArray('json', $thread);
```

### The two permitted implementations

**E1 follows AD-14 r2 exactly:** adds `thread_closed`, `thread_locked`, `thread_reopened`, `thread_unlocked` to all six named registries — including both arrays in registry 5 (gate 1 and gate 2). It then verifies the relay: a second participant *does* receive the message. Registry checklist complete, acceptance met, ships.

**E2/E5 build the client to update `useChatExtrasStore` from the relayed system message,** per AD-15.

### Where they collide

`thread_closed` now clears gate 1 (E1 added it) and gate 2 (E1 added it), reaches gate 3, does not match the hardcoded pair, and is serialised with `$thread === null`.

`Message::toArray()` (`lib/Model/Message.php:187-222`) then omits three keys, because they sit inside `if ($thread !== null)`:

```php
$data['isThread'] = true;
$data['threadTitle'] = $thread->getName();
$data['threadReplies'] = $thread->getNumReplies();
```

Note the asymmetry that makes this survive testing: `threadId` is emitted unconditionally (line 198), `isThread` is not. A test that asserts "the relayed message carries the right thread id" passes.

`Message::toArray()` (`lib/Model/Message.php:187-222`) then omits three keys, because they are inside `if ($thread !== null)`:

```php
$data['isThread'] = true;
$data['threadTitle'] = $thread->getName();
$data['threadReplies'] = $thread->getNumReplies();
```

### Observable symptom

Two symptoms, both user-visible, from one missed line.

1. **The state change does not propagate.** The relayed comment carries no Thread object, so the store has nothing to merge; `addThread()` (`src/stores/chatExtras.ts:234`) requires a full `ThreadInfo` and cannot be fed a comment. Other participants keep seeing **Ongoing** on a Thread that was Closed ten minutes ago, until they reload — the exact failure AD-14's *Prevents* clause names.

2. **The system message renders in the wrong place, for everyone but the actor.** `src/stores/chat.ts:55-60`:

   ```js
   return threadId
       ? threadId === message.threadId
       : (!message.isThread || message.id === message.threadId || ...)
   ```

   `threadId` is always emitted (`Message.php:198`), but `isThread` is now `undefined`, so `!message.isThread` is `true` and the message is admitted into the **main chat** context as well as the thread context. "Hạnh closed this thread" appears in the room's main chat for every other participant, while the actor — who got it back through the REST response path with the Thread attached — sees it only inside the Thread. Two participants standing next to each other see the same event in two different places.

E1's acceptance — "all six registries touched in the same change", plus a relay test that the message arrives — passes throughout.

### Proposed AD text

**AD-14 registry 5 splits into 5 and 6, and the current registry 6 becomes 7.** Amend:

> Each lifecycle transition has exactly one system-message constant, and shipping it means touching **all seven** registries in the same change:
> 1. `src/constants.ts` — the constant itself.
> 2. `SYSTEM_MESSAGE_TYPE_RELAY` in `src/utils/message.ts`.
> 3. `SYSTEM_MESSAGE_TYPE_UNTRANSLATED` in the same file.
> 4. `SYSTEM_MESSAGE_TYPE_HIDDEN` in the same file.
> 5. The **inline early-return array** in `notifySystemMessageSent()` (`lib/Signaling/Listener.php:558`) — or a verb emitted with skip-last-activity-update never relays.
> 6. `SYSTEM_MESSAGE_TYPE_RELAY` in the same file (`:81`) — or the message relays as a bare refresh with no comment.
> 7. **The thread-attachment condition in the same method (`Listener.php:577`), today `$messageType === 'thread_created' || $messageType === 'thread_renamed'` — or the message relays *successfully* with `$thread === null` and the client cannot tell it belongs to a Thread.**
>
> Registries 5, 6 and 7 are three consecutive gates in one 30-line method and are extracted, in E1's change, into three named constants sitting together — `SYSTEM_MESSAGE_TYPE_RELAY_DESPITE_SKIP`, `SYSTEM_MESSAGE_TYPE_RELAY`, `SYSTEM_MESSAGE_TYPE_THREAD_PAYLOAD` — so a verb present in one is visibly absent from the others. An inline array inside a conditional is not a registry anyone can be asked to remember.
>
> **Acceptance is not "the message relayed".** It is a multi-actor test asserting a second participant's client receives the relayed comment carrying `isThread`, `threadTitle` and the new state, and that the message renders **in the Thread and not in the main chat**. `threadId` is emitted unconditionally (`Message.php:198`) while `isThread` is not, so an assertion on the thread id passes against the broken payload.

---

## AS-4 — AD-7's fence is not airtight: the two queries filter on different values

**Severity: Critical. CLOSED in spine r2.** Retained because the reasoning matters for AS-17, and because the closing rule is better than the one this section proposed.

> **Verdict on the r2 rule.** It closes the collision. It also corrects *me*: the fix proposed at the bottom of this section — change `getRecentByActor()` to `!= NOTIFY_DEFAULT` — is wrong, and would have broken a shipped behaviour. `tests/integration/features/chat-4/threads.feature:224` asserts that after `participant2` replies in a thread, `participant2` sees that thread under **subscribed threads** with `a.notificationLevel | 0`. A reply-created row sits at `NOTIFY_DEFAULT` and *must* stay in the followed list. So `NOTIFY_DEFAULT` cannot be the discriminator in either direction — the row's origin (reply vs read) is the fact that matters, and no existing column carries it. r2's explicit subscription column is the only rule that separates them. Accepted without reservation; one residual is filed as AS-17.

*Original analysis follows.*

### The two permitted implementations

AD-7's rule:

> `findAttendeesForNotification()` and `getRecentByActor()` continue to filter on `notification_level` explicitly, and each carries a test asserting that a read-created row at `NOTIFY_DEFAULT` appears in neither.

**E3 implements AD-6's lazy rows:** reading a Thread creates a `talk_thread_attendees` row at `NOTIFY_DEFAULT` — the level `ensureIsThreadAttendee()` already uses (`ThreadService.php:220`). E3 then verifies AD-7's rule: both queries do filter on `notification_level` explicitly. Compliance confirmed, no code change needed. Ships.

**E4 builds notification targeting on those rows** and confirms the same fence.

### Where they collide

The two filters are not the same filter. `Participant::NOTIFY_DEFAULT = 0`, `NOTIFY_NEVER = 3` (`lib/Participant.php:30-33`).

- `ThreadAttendeeMapper::findAttendeesForNotification()` (line 104-109): `neq notification_level, NOTIFY_DEFAULT` → a DEFAULT row is **excluded**. The fence holds.
- `ThreadService::getRecentByActor()` (line 152): `neq('a.notification_level', NOTIFY_NEVER)` → a DEFAULT row is **included**. The fence does not hold.

AD-7's own sentence — "That this does not happen today is incidental, not designed — two unrelated queries happen to filter on `notification_level`" — is half right and draws the wrong conclusion from it. They filter on the same column with different predicates, and only one of them excludes the row E3 is about to start creating.

### Observable symptom

A participant opens the Directory and reads six Threads to triage them. Six rows are written at `NOTIFY_DEFAULT`. Their Followed Thread List — `GET /chat/subscribed-threads`, `ThreadController.php:106` → `getRecentByActor` → the client's `followedThreadsList` (`src/stores/chatExtras.ts:110-119`) — now contains six Threads they never chose to follow. Repeat over a week in a room with two hundred Threads and the Followed Thread List becomes a browsing history, which is the surface FR-20 asks to make searchable. Notifications remain correct, so the failure looks like a client bug and is diagnosed nowhere near E3.

### Proposed AD text

Replace **AD-7**'s rule:

> Subscription is `notification_level > NOTIFY_DEFAULT`, and that expression is the definition — no query may substitute another. Two queries read this table for membership and both are corrected in the epic that first writes a row on read:
>
> - `ThreadAttendeeMapper::findAttendeesForNotification()` already filters `!= NOTIFY_DEFAULT` and is correct; it gains a regression test, not a change.
> - **`ThreadService::getRecentByActor()` filters `!= NOTIFY_NEVER` today and is wrong under lazy read-rows. It changes to `!= NOTIFY_DEFAULT` in the same change that ships the first read-created row, never later.**
>
> Each carries a test asserting a `NOTIFY_DEFAULT` row appears in neither. No query may treat row existence as subscription, ordering input or membership. Any new query over `talk_thread_attendees` states in a comment which of the two facts on the row it reads.

---

## AS-5 — Two Thread response shapes, both permitted by AD-12

**Severity: High.** Crashes the client store on the second shape.

### The two permitted implementations

AD-12: "Every mutation responds with the full updated Thread representation (AD-13 depends on this)." AD-13: "the client replaces its local copy with **the response**, never with what it sent."

Neither names the type, and the codebase has two:

- `TalkThread` — `Thread::toArray(Room $room)` (`Model/Thread.php:98-110`): `{id, roomToken, lastMessageId, numReplies, lastActivity, title}`.
- `TalkThreadInfo` — `ThreadController::prepareListOfThreads()` (line 260-321): `{thread: TalkThread, attendee: {...}, first: Message|null, last: Message|null}`.

**E1 ships state changes returning `TalkThread`.** "The full updated Thread representation" is the Thread. It is what changed, it needs no `getMessagesById()` + `preloadShares()` + two `MessageParser` passes, and AD-9's spirit favours the cheaper response. Permitted.

**E2 ships featuring and tagging returning `TalkThreadInfo`,** following the only precedent in the file — `renameThread` (line 248-252) and `setNotificationLevel` (line 385-389) both return `TalkThreadInfo`. Permitted, and arguably better-founded.

### Where they collide

`useChatExtrasStore.addThread()` (`src/stores/chatExtras.ts:234-240`):

```js
threads.value[token][thread.thread.id] = thread
```

It indexes `thread.thread.id`. Fed a bare `TalkThread`, `thread.thread` is `undefined` and the property access throws. If a defensive epic guards the access instead, the store stores a shape whose `.thread` is missing, and `getThreadsList()` (line 104) sorts on `b.thread.lastActivity - a.thread.lastActivity` → `NaN` → the comparator becomes non-transitive and the Directory's order is whatever the engine's sort does with `NaN`.

### Observable symptom

A Thread Manager closes a Thread from a Directory row. Either the row's action throws in the console and the Directory silently stops updating, or the Directory's entire sort order scrambles on the next render and the featured block disperses. Reload fixes it, which makes it read as a flake.

### Proposed AD text

Amend **AD-12**:

> Every endpoint that returns a Thread — list, single, and **every** mutation (state, featuring, tag, rename, notification level, mark-read) — responds with `TalkThreadInfo`, the `{thread, attendee, first, last}` envelope `ThreadController::prepareListOfThreads()` already produces. `TalkThread` is never returned alone by any endpoint. This is what AD-13 means by "the response": one shape, so the client has one merge path. A mutation that has no cheap way to populate `first`/`last` still populates them; the cost is one `getMessagesById()` for two ids, which AD-9's budget already allows.

Amend **AD-15**:

> `addThread()` is the single entry point for a Thread object into the store and validates that it received a `TalkThreadInfo`. No component or service merges a partial Thread into the store.

---

## AS-6 — Ordering and pagination: the server orders, the client re-sorts, and page 2 is undefined

**Severity: High.** Three separate under-specifications compound. E2 and E3 can disagree about what page 2 contains, and the client can discard the server's answer entirely.

### The two permitted implementations

Three gaps, each independently exploitable.

**(a) Who orders.** AD-12 says "filtering and search live in the query, not the client store (FR-17)" — it says *filtering and search*, not *ordering*. The Consistency Conventions say "`last_activity` remains the ordering key", while the Capability Map says §4.5 is "featured-first sort with an OR past the state filter". The spine states the ordering key two ways in two tables.

- **E2-server:** `ORDER BY featured DESC, last_activity DESC` in `ThreadMapper`. Obeys the Capability Map.
- **E2-client:** keeps ordering where it already is — `getThreadsList()` (`src/stores/chatExtras.ts:104`) sorts by `lastActivity` DESC in the store today, and AD-15 makes the store the owner of Thread state. Add a `featured` term to that comparator. Obeys AD-15 and the "`last_activity` remains the ordering key" convention.

**(b) Which pagination scheme.** AD-12 says the endpoint "gains optional state, tag and **pagination** parameters" without naming a scheme. `getRecentByActor()` already uses `setFirstResult($offset)` (`ThreadService.php:157`), so offset is the house style. A keyset cursor on `last_activity` is equally permitted and is the correct choice for a live-ordered list.

**(c) Where the Directory's page order survives.** The store is a **map keyed by thread id** (`threads.value[token][id]`), and `getThreadsList()` returns `Object.values(...).sort(...)`. Server order is not stored anywhere.

### Where they collide

If E2 orders server-side and the store keeps its existing `lastActivity`-only comparator — the likeliest outcome, because nobody edits a sort that already works — every Featured Thread the server carefully lifted to the top sinks back into activity order the instant it enters the store. FR-13 fails on a server that implements it correctly, and the server's own integration test passes.

If E2 orders client-side and paginates, the client can only sort what it has loaded. A Featured Thread whose `last_activity` puts it on page 3 is invisible until page 3 loads, then jumps to position 1 — "featured first" is true only after the participant has scrolled the whole list, which is the one thing the Directory exists to avoid.

If E2 uses offset pagination, the ordering key is mutable by design: FR-13 requires "a reply arriving in a listed Thread moves it to the top of its group without the participant refreshing." A reply between the page-1 and page-2 requests shifts every row down one; page 2 repeats the last row of page 1, and one Thread is never returned at all. FR-17's "appends it in order" appends a duplicate — and because the store is a map keyed by id, the duplicate is silently absorbed while the dropped Thread stays missing.

E3 compounds it: AD-9 requires unread to resolve in "one grouped query over the page's thread ids". If E3 computes unread from the ids the server returned but E2's client re-sorts and re-pages, the unread batch is fetched for a set of ids that is not the set being rendered. Rows show another row's unread count until the next fetch.

### Observable symptom

The rota Thread — featured, last replied to four months ago — is not at the top of the Directory. It is on page 4, and by the time the participant has scrolled to page 4 they have found what they were looking for another way. The single most visible requirement in §4.5 fails, and both the server and the client pass their own tests.

### Proposed AD text

New **AD-20 — The Directory's order is the server's, and it is a keyset**:

> **Binds:** FR-13, FR-14, FR-17, FR-21, FR-32; AD-9, AD-12, AD-15.
> **Prevents:** the server and the client each holding half an ordering; and offset pagination over a key that moves while the participant reads.
> **Rule:**
> 1. **Ordering is server-side and total.** The order is the tuple `(featured DESC, last_activity DESC, id DESC)` — `id` breaks ties so the order is total and stable, which offsetless paging requires. The Consistency Conventions row reading "`last_activity` remains the ordering key" is corrected to this tuple.
> 2. **The client preserves server order and never re-sorts.** `useChatExtrasStore` keeps, per token and per active filter set, an **ordered list of thread ids** alongside the id-keyed map; the Directory renders that list. `getThreadsList()`'s comparator is removed from the Directory path. A relayed reply reorders by moving one id, not by re-sorting the array.
> 3. **Pagination is keyset, not offset.** The client sends the last row's `(featured, lastActivity, id)` as an opaque cursor; the server returns rows strictly after it under the same tuple. No `setFirstResult()` on the Directory query. This is what makes FR-13's live reordering and FR-17's incremental loading compatible; offset paging over a mutable key cannot be.
> 4. **Filter and sort belong to the same request.** Changing the state filter, the tag filter or the search term discards the cursor and the id list and starts a fresh page 1. A page fetched under one filter is never appended to a list built under another.
> 5. **The unread batch is keyed to the page.** E3's grouped unread query takes exactly the id list the server returned for that page, and its result is applied to those ids only.

---

## AS-7 — AD-5's reaper against AD-1's positive cache: `validateThread()` lies for 900 seconds

**Severity: High.** Silently misroutes messages.

### The two permitted implementations

AD-1: "Every `ThreadService` mutator ends by **re-writing** the cache entry `thread/{roomId}/{threadId}` from the fresh entity, not by removing it."

AD-5: the expiry job "must also reap `talk_threads` and `talk_thread_attendees` rows whose root comment no longer exists, bounded per run."

**E1 writes the reaper inside `ThreadService`** (AD-1 requires it — the reaper writes `ThreadMapper`) and follows AD-1's mutator rule as far as it can. There is no fresh entity after a delete, so AD-1's rule is literally unsatisfiable, and the rule explicitly forbids the obvious alternative ("not by removing it"). E1 reasons that AD-1's mutator clause governs *mutations of a Thread*, not its destruction, and leaves the cache alone. Permitted — nothing in AD-1 or AD-5 says otherwise.

**Another epic touching the reaper** — or the same epic, reading AD-1's cache convention row ("the cache belongs to `ThreadService` and nothing else reads or writes that prefix") as covering deletion — calls `$this->cache->remove(...)`, matching `deleteByRoom()`'s existing `$this->cache->clear(prefix)` (`ThreadService.php:266`). Also permitted.

### Where they collide

`findByThreadId()` (`ThreadService.php:60-80`) caches the **positive** entry for `60 * 15` seconds. `validateThread()` (line 277-284) is just `findByThreadId()` with the exception swallowed. The expiry job runs every 5 minutes (`ExpireChatMessages.php:27`) and hard-deletes through `deleteCommentsExpiredAtObject('chat', '')` (`ChatManager.php:1322`) — an unscoped full sweep that reports nothing about which threads it orphaned. So the reaper cannot be told what it reaped; it must find orphans itself, and the window between reaping and cache expiry is up to 900 seconds regardless.

### Observable symptom

A Thread's root message expires and is reaped. Within the next fifteen minutes a participant posts a reply into that Thread from a client that still has it listed:

- `ChatManager::sendMessage()` line 415-416 calls `validateThread()`, which hits the warm positive cache and returns **true**.
- Line 447 stamps `METADATA_THREAD_ID = $threadId` onto the comment and it is saved that way.
- Line 464 calls `updateLastMessageInfoAfterReply()`, whose `UPDATE` matches zero rows and returns `false`.
- Line 466 sets `$threadId = Thread::THREAD_NONE`.

The comment is persisted carrying thread metadata for a Thread that no longer exists, but every downstream consumer in that request treats it as a main-chat message. The author sees their reply appear in the main chat. Anyone whose client reads `METADATA_THREAD_ID` files it under a dead Thread. There is no error anywhere.

The negative cache mirrors the problem in the other direction: `findByThreadId()` writes `''` with the same 900s TTL on a miss (line 76). Because `sendMessage()`'s `$threadId` parameter branch (line 417) never calls `validateThread()`, an unvalidated caller-supplied id reaching a guard that resolves the Thread will populate a negative entry for an arbitrary integer — and if a Thread is later created with that id, `createThread()` overwrites it (line 51), so that direction is safe. The reaped direction is not.

### Proposed AD text

Amend **AD-1**'s mutator sentence:

> Every `ThreadService` mutator ends by re-writing the cache entry `thread/{roomId}/{threadId}` from the fresh entity, not by removing it. **Deletion is the one exception and is explicit: a deleting operation writes the negative entry (`''`) for that key rather than removing it**, so the next read is a definitive "does not exist" instead of a re-populating miss. `updateLastMessageInfoAfterReply()` — which today calls `remove()` *before* `executeStatement()` (`ThreadService.php:262`), leaving a window in which a concurrent reader re-populates the stale row — is converted to re-set after the statement in the same change.

Amend **AD-5**:

> The reaper runs inside `ThreadService`, writes the negative cache entry for every id it reaps, and is bounded per run. **`validateThread()` is not permitted as the sole gate for a write that will then be persisted with thread metadata**: `ChatManager` writes the `METADATA_THREAD_ID` stamp only after `updateLastMessageInfoAfterReply()` has confirmed the row exists, so a cache lie cannot survive into stored data. Where the two disagree, the database wins and the message is recorded as a main-chat message with no thread metadata.

---

## AS-8 — AD-2 does not say what the guard reads, and in `addSystemMessage` the answer arrives after the write

**Severity: High.** The guard can be built in two places that disagree about which calls are "into a Thread", and one of them cannot refuse at all.

### The two permitted implementations

AD-2: "Locked refusal and the Closed→Ongoing revival are both applied inside `ChatManager::sendMessage()` and `ChatManager::addSystemMessage()`, never in controllers, listeners or jobs."

It does not say **which expression** identifies the Thread, and the two methods compute it differently — and in one of them, not until after the comment is saved.

`addSystemMessage()` (`ChatManager.php:410-413, 437, 445`):

```php
if ($replyTo !== null) {
    $comment->setParentId($replyTo->getId());
} elseif ($threadId !== 0) {
    $comment->setParentId((string)$threadId);
}
...
$threadId = 0;                                   // line 437 — the parameter is discarded
...
$this->commentsManager->save($comment);          // line 444 — WRITE HAPPENS HERE
$threadId = (int)$comment->getTopmostParentId(); // line 445 — only now is it known
```

`sendMessage()` (line 413-418) computes it before the save, but asymmetrically: the `$replyTo` branch derives *and validates* the id; the `$threadId` parameter branch does neither.

**E1 puts the guard at the top of `addSystemMessage()`, reading the `$threadId` parameter.** It is the named input, it is available pre-save, and refusing before writing is the only way to refuse. Permitted.

**E1 (or E2/E4, adding their own lifecycle and tag system messages) puts the guard on `$replyTo`,** because that is the branch their own calls use — `ThreadController::renameThread()` passes the root comment as `$replyTo` *and* the id as `$threadId` (`ThreadController.php:238-250`), and `addSystemMessage` prefers `$replyTo`. Permitted.

### Where they collide

The effective thread id is `$replyTo ? topmostParent($replyTo) : $threadId`, and the code only computes it post-save. A guard reading one input misses every call that arrives through the other.

Concretely, of AD-2's nine write paths, path 4 (rich object share) and path 5 (poll) reach `addSystemMessage` with `$threadId` set and `$replyTo` null; path 6 (file share) and `renameThread` reach it with `$replyTo` set. A `$threadId`-only guard lets file shares into a Locked Thread. A `$replyTo`-only guard lets polls in.

### Observable symptom

A Thread Manager locks a Thread after a heated exchange. FR-5's testable consequence is "every write is refused". A participant shares a file into the Locked Thread from the file picker and it lands. The composer refuses a typed message one second later. The Thread is half-locked, and which half depends on which epic wrote the guard for that path — E1's own Behat scenario covers the typed-message path and passes.

### Proposed AD text

Amend **AD-2**:

> **The guard reads one expression, defined once.** `ChatManager` gains a private `resolveThreadId(?IComment $replyTo, int $threadId): int` returning `$replyTo !== null ? ((int)$replyTo->getTopmostParentId() ?: (int)$replyTo->getId()) : $threadId`. Both `sendMessage()` and `addSystemMessage()` call it **before** `$comment->setParentId(...)` and before `commentsManager->save()`, and the guard evaluates its result. In `addSystemMessage()` this means the local `$threadId = 0` reset at line 437 and the post-save re-derivation at line 445 are left in place for the existing downstream logic, but the guard never depends on them — a refusal that happens after `save()` is not a refusal.
>
> `sendMessage()`'s asymmetry is also closed: the `$threadId`-parameter branch gains the `validateThread()` call the `$replyTo` branch already performs, so both branches present the guard with a validated id, subject to AD-5's rule that the database, not the cache, decides what is finally persisted.
>
> Acceptance for FR-5 is one Behat scenario **per write path**, all nine, not one scenario for the family.

---

## AS-9 — The composed notification object id has variable arity; "append-only" does not say what absence means

**Severity: High.** Breaks the two shipped mobile clients that this epic does not update.

### The two permitted implementations

AD-12: "The composed notification object identifier may gain a **trailing** position and may never be reordered or shortened — shipped clients parse it positionally, and the existing integration assertion on it must keep passing."

The actual identifier (`lib/Notification/Notifier.php:638-641`):

```php
$notification->setObject($type, $notification->getObjectId() . '/' . $message->getMessageId());
if (isset($messageParameters['threadId'])) {
    $notification->setObject($type, $notification->getObjectId() . '/' . $messageParameters['threadId']);
}
```

So: `{token}` → `{token}/{messageId}` → `{token}/{messageId}/{threadId}`, where position 3 is **conditional**, and the whole block sits inside `if (!isPreparingPushNotification() && !isSensitive())` — which is FR-35's defect.

**E4 reads "may never be shortened" as a constraint to maintain,** and emits a fixed-arity identifier, padding the thread position with `0` for non-thread activity: `{token}/{messageId}/0`. This is the reading that makes "never shortened" mean something, and it gives clients one shape to parse — which FR-35 explicitly asks for ("in the same composed form the in-app notification uses, so a client parses one shape"). Permitted.

**E4 (or E5, implementing FR-36's routing) reads it as append-only with meaningful absence,** and emits two positions for non-thread activity, three for thread activity — preserving today's behaviour exactly. This is what FR-35's other consequence asks for ("a client that never receives a thread identifier — because the activity was not in a Thread — must not misread the shorter value"). Also permitted, and the two consequences of FR-35 point in opposite directions.

### Where they collide

The existing integration assertion covers the *thread* case, which is identical under both readings. Neither reading fails it. The disagreement is entirely in the non-thread case, which no test asserts.

### Observable symptom

Under the padding reading, the shipped Android client — which AD-12 says parses positionally and which this epic does not update — reads position 3 of every ordinary main-chat push as thread id `0` and attempts to open Thread 0. Every main-chat notification on Android routes into an empty thread view instead of the conversation. Under the omission reading, a client written against the padded shape reads `undefined` and falls back to the main chat, which is correct but only by accident.

FR-36's fourth consequence — "Following one for a Thread since deleted lands in the Conversation's main chat with an explanation, not an error page" — is E5's, and it needs to distinguish "no thread id" from "thread id that no longer resolves". The sentinel `0` collapses those two cases into one.

### Proposed AD text

Amend **AD-12**:

> The composed notification object identifier is `{token}` / `{messageId}` / `{threadId}`, **variable arity, absence-significant**. Position 2 becomes unconditional (FR-35 requires the message id on push payloads for non-thread activity too). Position 3 is present **if and only if** the activity was inside a Thread; it is never padded, never filled with a sentinel, and `0` is never a valid value at any position. A new position may only be appended after position 3 and only for notifications that already carry position 3, so no position's meaning ever depends on which earlier positions are present.
>
> FR-35's payload fix splits the existing guard rather than removing it: the identifier positions are emitted for push payloads **and** for sensitive Conversations (routing information is not content, per PRD §4.8); `setParsedMessage` / `setRichMessage` stay behind the existing content guard.
>
> Acceptance is three assertions, not one: the existing thread-case assertion unchanged; a new non-thread in-app assertion of exactly two positions; and a new push-payload assertion for both cases.

---

## AS-10 — Posting a thread reply advances the conversation read marker, and r2's lazy freeze turns that into silent zero-unread

**Severity: raised to High in r2.** In r1 this broke FR-30 alone. AD-6's lazy freeze now hangs FR-27 off the same marker, so the same line of code breaks per-Thread unread as well — and which way it breaks depends on a placement decision AD-6 does not make.

### Part A — where the freeze fires is unassigned, and prose and diagram disagree

AD-6 r2 prose: "the first time a participant's conversation read marker **would advance**, the **pre-advance** value is frozen into the baseline in that same row write."

AD-6 r2 diagram, node F: "**Reading the main chat** freezes the PRE-advance marker into `thread_read_baseline` if still NULL, then advances `last_read_message`."

The prose says *any* advance. The diagram says *reading the main chat*. `ParticipantService::updateLastReadMessage()` (`lib/Service/ParticipantService.php:244-249`) is a single choke point with **six** callers, and only two of them are reads:

| Caller | What the participant did |
| --- | --- |
| `ChatController.php:1911` (`setReadMarker`) | read |
| `ChatController.php:948` (`receiveMessages`) | read |
| `ChatManager.php:474` (`sendMessage`) | **posted — including a thread reply** |
| `ChatManager.php:269` (`addSystemMessage`) | shared a file / created a poll |
| `RoomFormatter.php:323` (`UNREAD_MIGRATION` conversion) | **nothing — this is a GET render** |

**E3-choke** puts the freeze inside `updateLastReadMessage()`. One place, obeys the prose, smallest diff per AD-17. Permitted.

**E3-read** puts it on the two read paths. Obeys the diagram, and obeys AD-6's whole framing, every emphatic sentence of which is about reads ("never by reading the main chat"). Permitted.

### Part B — the collision

Under **E3-read**, `ChatManager.php:474` advances `talk_attendees.last_read_message` on a thread reply without freezing anything. The baseline stays `NULL`. AD-6's `NULL` branch falls back to `talk_attendees.last_read_message` — which is now the participant's own thread reply id, the highest comment id in the room.

Every Thread with no `talk_thread_attendees` row — i.e. every Thread they have never opened — resolves its unread against that id and reports **zero**.

### Observable symptom

Dũng (UJ-2) joins a room with 180 Threads and his first action is to reply in one. His conversation marker jumps to his own reply. His baseline is still `NULL`, so all 180 Threads he has never opened report zero unread, permanently, until he opens each one or runs mark-all. The Directory shows him no badges at all — the surface exists to tell him where he owes a reply and it tells him nothing. FR-27's "a Thread with three messages the participant has not seen reports three" fails for exactly the participants who post, and a test that reads before posting never sees it.

Under **E3-choke** the freeze does fire, but from `RoomFormatter.php:323` it fires inside a **conversation-list GET**, writing `talk_attendees` from a formatter — a persistence write from a layer the spine's own dependency graph gives no write edge.

### Part C — the r1 finding, unchanged and still open

`ChatManager::sendMessage()` line 470-472:

```php
if (!$fromScheduledMessage && $participant instanceof Participant) {
    $this->participantService->updateLastReadMessage($participant, $messageId);
}
```

AD-8 changes what is *counted* past the marker and nothing changes where the marker *goes*. Eleven unread main-chat messages, participant answers a question in a Thread, marker jumps past all eleven, new non-thread count returns 0. FR-30's "Reading a Thread does not decrease the Conversation's unread message count" is violated by *posting*, which is outside the sentence's wording and inside its intent.

### Proposed AD text

Amend **AD-6**, replacing the freeze clause:

> The baseline is frozen inside `ParticipantService::updateLastReadMessage()` — the single choke point through which all six writers of `talk_attendees.last_read_message` pass — and nowhere else. The prose and the diagram are reconciled to this: **any** advance freezes, not only a read, because a thread reply advances the same column and leaving it unfrozen makes the `NULL` fallback resolve against the participant's own reply.
>
> `RoomFormatter.php:323`'s `UNREAD_MIGRATION` conversion is the one caller that must **not** freeze: it is a legacy-marker repair inside a GET, not a read of the room. It passes an explicit flag to suppress the freeze, and that flag is the only exemption.

Amend **AD-8** (unchanged from r1):

> The counting override and the read-marker writer change together. `ChatManager::sendMessage()`'s `updateLastReadMessage()` call advances `talk_attendees.last_read_message` **only when the message is not a thread reply**; when it is, the author's own `talk_thread_attendees` row for that Thread advances instead, per AD-6, and the conversation marker stays put. This is the write-side counterpart of AD-6's read-side rule and ships in the same epic. Split across E1 and E3, FR-30 and FR-27 are both left half-implemented in a way neither epic's tests detect.
>
> `unreadCountCache` is not cleared for a message that cannot change a conversation unread count, i.e. thread replies. The cache key's semantics token is orthogonal to this and is not a substitute for it.

---

## AS-11 — AD-19 forbids backfill and AD-6 requires one; the tension is asserted resolved, not resolved

**Severity: Medium-High. CLOSED in spine r2.**

> **Verdict on the r2 rule.** It closes it. AD-19 now says "**No data backfill of any kind** — no table-wide `UPDATE` runs at upgrade time", and AD-6 ships the baseline `NULL` with a fallback to the conversation marker, so the day-one no-badge-flood property comes from the fallback rather than from a seeding statement. That is the resolution, not an assertion of one. The lazy freeze that replaces the seeding introduces a new placement question — filed as AS-10 Part A, not here.

*Original analysis follows.*

### The two permitted implementations

AD-19: "**No data backfill** — every pre-existing Thread reads as Ongoing, unfeatured and untagged... The single initialisation that does happen is AD-6's thread-read baseline, seeded from each participant's existing conversation read marker."

AD-6: "A Thread with no row resolves its unread against a new **thread-read baseline** column on `talk_attendees`, which is advanced only by mark-all-threads-read (FR-29) and on joining a Conversation."

**E3 seeds in the migration:** `UPDATE talk_attendees SET thread_read_baseline = last_read_message`, one statement, every row in the table. This is the plain reading of AD-19's "the single initialisation that does happen", it satisfies AD-6's need for a baseline on day one, and it is what "so nobody wakes to a room full of badges" requires. Permitted.

**E3 seeds lazily:** the column defaults to `0`/NULL and is resolved at read time as `COALESCE(thread_read_baseline, last_read_message)`, with a write only when the participant first interacts. This is what AD-19's "additive, defaulted, no backfill, safe to run twice" actually permits, and it is the only reading that honours "an in-place upgrade that locks a large customer table with no maintenance window". Also permitted.

### Where they collide

`talk_attendees` is the largest per-user table in a Talk deployment — one row per participant per conversation. A single unbounded `UPDATE` over it is exactly the operation AD-19's *Prevents* clause names, and AD-19 authorises it in its own last sentence. The AD contradicts its own rationale in three lines.

The two readings also produce different semantics at the edge. Under the migration seeding, a participant who joined a Conversation after the migration has `thread_read_baseline = 0` unless AD-6's "on joining a Conversation" hook fires — that hook is unowned in the spine (E3 writes it? E1 owns `ParticipantService`?). Under lazy resolution, `COALESCE` handles the new joiner for free.

### Observable symptom

Migration reading: a customer with 40,000 participant rows upgrades Clavis Talk on a Friday evening, per AD-19's stated envelope of "no maintenance window". The `UPDATE` holds locks on `talk_attendees` for the duration; every conversation-list request in the deployment blocks behind it. This is the failure AD-19 was written to prevent, delivered by AD-19.

Lazy reading, if E3 picks it and the "on joining" hook is never written because no epic owns it: a member who joins a busy room after the upgrade has `thread_read_baseline = 0`, so every Thread with no row resolves unread against message 0 and reports its full length. They join and see two hundred unread badges — the exact scenario PRD §4.7 says the design exists to prevent.

### Proposed AD text

Amend **AD-19**, replacing the last sentence:

> **No data backfill, including AD-6's baseline.** `talk_attendees.thread_read_baseline` is added as a nullable column with no `UPDATE` statement in the migration. It is resolved at read time as `COALESCE(thread_read_baseline, last_read_message)` — NULL means "never set, fall back to the conversation marker", which is the correct day-one semantics for a pre-existing participant *and* for a participant who joins afterwards, with no join hook required. It acquires a value only when FR-29's mark-all-threads-read writes one.

Amend **AD-6** to match:

> A Thread with no row resolves its unread against `COALESCE(talk_attendees.thread_read_baseline, talk_attendees.last_read_message)`. The baseline is written **only** by mark-all-threads-read (FR-29). There is no join-time write and no migration-time write; the `COALESCE` covers both cases, which removes AD-19's tension rather than asserting it away.

---

## AS-12 — Four caps, one convention, and no AD saying which layer enforces which

**Severity: Medium.** Produces caps that disagree between server and client, and one that can be enforced twice.

### The two permitted implementations

Four caps are in scope, and the spine assigns a home to one of them:

| Cap | Value | Named in | Enforcement layer named? |
| --- | --- | --- | --- |
| Featured Threads per Conversation | 5 | FR-22 | No |
| Tags per Thread | 5 | FR-23 | No |
| Distinct tags per Conversation | 20 | FR-23, AD-10 | AD-10 says copy `MAX_TAG_IDS_PER_CONVERSATION` from `ConversationTagService` (`ConversationTagService.php:27`) — a service constant |
| Rendered unread display cap | unspecified | FR-32, AD-9 | Conventions: "the cap is a shared constant, not a per-surface literal" |

**E2 enforces the featured and tag caps in the service layer,** raising typed exceptions per AD-4, following `ConversationTagService`'s precedent. Permitted.

**E2's client also enforces them,** because FR-22 requires "the limit is stated in the interface before the participant hits it" and FR-23's tag input (FR-26) must show the state of the Conversation's twenty-tag budget while typing. A client that only learns the limit from a 4xx cannot state it beforehand. Permitted, and required by the FRs.

### Where they collide

Two enforcement points for the same number in two languages, with no rule saying the server's value is the one that counts, and no mechanism for the client to learn it. AD-12 adds **two** capability flags and says nothing about carrying limits in them.

The unread cap is worse, because AD-9 and the Conventions row describe different operations. AD-9: "counting stops at FR-32's display cap rather than counting to completion" — a *server* truncation, so the API returns the cap value. The Conventions row: "**Rendered** unread counts are capped" — a *client* presentation rule. E3 implements the server cap at 99 and returns `99`. E5 renders `99+` only when `count > 99`, which the server has made impossible.

### Observable symptom

- A Thread with 5,000 unread replies renders `99`, not `99+`. A participant reads the Thread expecting ninety-nine messages.
- E2 ships the client's featured limit as 5; a later tightening to 3 on the server leaves the client offering a **Feature** action that always 4xxs, with the interface still promising five.
- The tag budget indicator in FR-26's input reads "3 of 20 used" against a server that enforces 20 — until someone changes one and not the other.

### Proposed AD text

New **AD-21 — Caps are server constants, enforced once, and published**:

> **Binds:** FR-22, FR-23, FR-26, FR-32; AD-4, AD-9, AD-10, AD-12.
> **Prevents:** a client promising a limit the server does not hold, and a display cap the client can never render.
> **Rule:**
> 1. Every cap is a PHP constant on the service that owns the aggregate — `ThreadService::MAX_FEATURED_PER_ROOM`, `ThreadTagService::MAX_TAGS_PER_THREAD`, `ThreadTagService::MAX_TAG_IDS_PER_CONVERSATION` (copied from `ConversationTagService`), `ThreadService::UNREAD_DISPLAY_CAP`. There is no TypeScript literal for any of them.
> 2. **Enforcement is server-side and single.** Exceeding a cap raises a typed exception per AD-4 with an identifier naming the cap. The client's job is to *display* the limit and pre-empt the request, never to be the enforcement point.
> 3. **The caps are published in the thread-management capability payload** (AD-12's first flag), as a `limits` object. The client reads them from there and holds no copy. This is what makes FR-22's "stated in the interface before the participant hits it" true against any server version.
> 4. **The unread cap is a server truncation that stays distinguishable.** The server counts to `UNREAD_DISPLAY_CAP + 1` and returns at most that value; the client renders `N` when `N <= cap` and `{cap}+` when `N > cap`. AD-9's "counting stops at the cap" and the Conventions row's "rendered counts are capped" are the two halves of this one rule, and the `+1` is what joins them.

---

## AS-13 — AD-1's field checklist spans two incompatible row-key conventions

**Severity: Medium.** A new column added to both places AD-1 names still fails to load, and only on one code path.

### The two permitted implementations

AD-1 requires every new Thread field to be added to `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` and `SelectHelper::selectThreadsTable()` (both branches). Six places, correctly enumerated. What it does not say is that these use **three different key conventions**:

- `Thread::createFromRow()` (`Model/Thread.php:47-56`) reads `$row['t_id']`, `$row['room_id']`, `$row['last_message_id']`, `$row['name']` — the id is prefixed `t_`, the rest are bare. Its only caller is `ThreadService::getRecentByActor()` (line 141), which aliases `t.id` to `t_id` and selects `t.name` etc. bare.
- `SelectHelper::selectThreadsTable()` aliased branch (`SelectHelper.php:58-67`) emits `th_room_id`, `th_last_message_id`, `th_num_replies`, `th_last_activity`, `th_name`, `th_id` — everything prefixed `th_`. Its only caller is `ScheduledMessageMapper` (line 51).
- The unaliased branch (line 69-75) emits bare columns plus `th_id`.

**E1 adds `state` to `createFromRow()` as `$row['state']`** — matching the bare convention its five sibling fields use — and to both `SelectHelper` branches as `state` / `th_state`. Six places touched, AD-1 satisfied.

**E2 adds `featured` the same way.** Same reasoning, same result.

### Where they collide

Neither epic is wrong, and neither notices that the aliased `SelectHelper` branch produces `th_state`, which `createFromRow()` never reads — because `createFromRow()` is not the consumer of that branch. The mismatch is silent: it is not a missing key at a call site, it is two disjoint pipelines that AD-1's checklist presents as one.

The failure arrives when a third epic connects them. E2's Directory query is the natural place to use `SelectHelper::selectThreadsTable()` (that is what the helper is for) and `Thread::createFromRow()` (that is the only row-to-entity path that is not `QBMapper`'s). Joining a `th_`-aliased select to `createFromRow()` yields a Thread whose id is `0` (`(int)$row['t_id']` on a missing key) and whose `state` is missing.

### Observable symptom

Every row in the Directory reports thread id `0` and Ongoing. Rows collapse onto one another in the client's id-keyed store map (`threads.value[token][0]`), so a page of twenty-five Threads renders as one. The Directory is empty except for a single mystery row.

### Proposed AD text

Amend **AD-1**'s field checklist:

> Every new Thread field is added, in the same change, to `Thread::addType()`, `createFromRow()`, `fromJson()`, `toJson()`, `toArray()` and `SelectHelper::selectThreadsTable()` (both branches).
>
> **These do not share a key convention today and must be reconciled before the first new field lands.** `Thread::createFromRow()` reads `t_id` plus bare columns and serves only `ThreadService::getRecentByActor()`; `selectThreadsTable(aliasAll: true)` emits `th_*` and serves only `ScheduledMessageMapper`. The first epic to add a column normalises both onto the `th_` prefix — `createFromRow()` accepts `th_id` and `th_*`, `getRecentByActor()`'s select is changed to match — as a preparatory commit, separate from and before the feature commit. A field-by-field checklist across two conventions cannot be verified by reading the diff, which is the only way this rule is ever checked.

---

## AS-14 — AD-16's "resolves under both factories" is satisfiable by a router that renders nothing

**Severity: Medium.** FR-42 passes its test and fails in the product.

### The two permitted implementations

AD-16: "Any address this feature introduces must resolve under **both** factories, and that is its acceptance condition, not an afterthought." No new named route; addresses extend `/call/<token>?…`.

**E5 satisfies the acceptance condition at the router level.** The Directory address is `/call/{token}?view=threads`, a query on the existing `conversation` route. Both factories declare `conversation` (`src/router/router.ts:69-74` and `96-101`). The memory router's `beforeEach` cancels any navigation whose `to.name !== 'conversation'` (line 118-120) — this address is `conversation`, so it survives. Test written, test passes. Permitted, and it is exactly what AD-16 asks for.

**E2 builds the Directory as a peer of the Conversation's other sidebar surfaces,** per FR-12's "registered as a peer of the Conversation's other sidebar surfaces — reachable by the same navigation, appearing in the same set". Those surfaces live in the right sidebar, which is rendered by `MainView`. Permitted, and required by FR-12.

### Where they collide

The two factories mount **different components** for the same route name:

```js
// createTalkRouter
{ path: '/call/:token', name: 'conversation', component: MainView, props: true }

// createMemoryRouter
{ path: '/call/:token', name: 'conversation', component: ChatView, props: { isSidebar: true } }
```

The Files sidebar renders `ChatView` alone. There is no sidebar tab set there, so there is nowhere for a peer surface to be a peer of. AD-16's acceptance condition — "resolves under both factories" — is about the **router**, and the router resolves it fine. The surface does not exist.

`useGetThreadId` compounds it: it is a `createSharedComposable` over `useRouteQuery` (`src/composables/useGetThreadId.ts:15-23`), so there is exactly one instance per page, bound to whichever router injected first. AD-16 tells E5 to build the new address composable "in the `useGetThreadId` mould", which propagates that binding to the new address without stating what it means when two factories exist.

### Observable symptom

A participant in the Files sidebar follows a link containing `?view=threads` — from a notification, a pasted URL, or the thread-root link FR-40 adds. The route resolves, `EventBus.emit('route-change')` fires, and `ChatView` renders the chat with no Directory anywhere. Nothing errors. FR-42's "Thread routing works in every surface that renders a Conversation" is asserted by a passing router test and contradicted by the running app.

### Proposed AD text

Amend **AD-16**:

> No new named route; Directory and Thread addresses extend the existing `/call/<token>?…` query scheme and are read through a shared composable in the `useGetThreadId` mould.
>
> **"Resolves" means renders, not routes.** The two factories mount different components for the `conversation` route — `MainView` under `createTalkRouter`, `ChatView` with `isSidebar: true` under `createMemoryRouter` — so a route test proves nothing about what the participant sees. Every address this feature introduces declares one of two behaviours for the memory-router surface, and the choice is recorded in the epic:
> - **Rendered** — the surface exists in `ChatView` too, and acceptance is a mounted-component test under `createMemoryRouter`, not a route-resolution test; or
> - **Degraded by design** — the address is accepted, the unsupported parameter is dropped, and the participant lands on the main chat for that Conversation, matching AD-18's degrade-by-omission posture.
>
> An address with neither declaration does not ship. `?threadId=` is already **Rendered** (`ChatView` handles thread context today); the Directory address is **Degraded by design** unless E2 places the Directory inside `ChatView` as well.

---

## AS-15 — AD-15's single owner against relayed messages for Threads it has never loaded

**Severity: Medium.** AD-9's query budget is defended on the server and spent on the client.

### The two permitted implementations

AD-15: "`useChatExtrasStore` owns Thread objects... Relayed state-change system messages update the store, which the components follow."

AD-9 binds the *server*: "Unread for a page of Threads resolves in a **fixed number of queries per page**, independent of page size."

**E1/E5 handle a relayed message for an unknown Thread by fetching it,** which is what the store already does — `fetchSingleThread()` (`src/stores/chatExtras.ts:248-266`), complete with a `pendingFetchSingleThreadRequests` de-duplication set and a standing `// FIXME: to be removed when chat relay provides thread data in original message` comment at line 260. Following the existing pattern is exactly what AD-15 implies. Permitted.

**E2 renders a Directory page of up to fifty rows,** each a Thread the store now holds. Permitted.

### Where they collide

The de-duplication set is keyed by thread id and cleared in `finally` (line 265), so it collapses concurrent duplicates for *one* Thread, not a burst across many. Nothing bounds the fan-out.

### Observable symptom

A moderator does UJ-1 — closing four Threads in a row while three colleagues have the Directory open. Each close relays a system message. If AS-3 is fixed and the relay carries the Thread, the store merges and nothing fetches. If AS-3 is *not* fixed, each colleague's store sees four state-change messages for Threads whose payload lacks thread data and issues four `GET /threads/{id}` calls. During a busy period in a room with fifty listed Threads, an ordinary burst of replies produces one request per relayed message per open client. AD-9's N+1 — the defect the spine names explicitly — reappears as N+1 HTTP requests instead of N+1 SQL queries, and AD-9 as written does not reach it.

### Proposed AD text

Amend **AD-15**:

> The store updates from the relayed system message's own payload and does not fetch. A relayed lifecycle message that carries its Thread (AD-14, registry 6) is merged directly; **`fetchSingleThread()` is not called from the relay path.** If a relayed message names a Thread the store has never loaded, the store records the id in a pending set and resolves it in the next Directory page request or via a single batched `GET .../threads/recent?ids=…`, never one request per message. The standing FIXME at `src/stores/chatExtras.ts:260` is closed by AD-14's registry 6, not worked around.

Amend **AD-9**:

> Unread for a page of Threads resolves in a fixed number of queries per page, independent of page size — **and in a fixed number of HTTP requests per page, independent of page size or of how many relayed messages arrive while the page is open.** The N+1 this AD prevents is not only an SQL shape.

---

## AS-16 — AD-2's third seam is named, described, and given to nobody

**Severity: High. New in r2.** Not a two-implementation collision — a two-epic *gap*, which is the same hole from the other side.

### What r2 says

AD-2 r2 is admirably honest:

> reactions, edits, deletes and pins **do not pass through either method** — `ReactionManager` and `ChatManager`'s own edit/delete/pin methods reach `commentsManager->save()` directly, so extending Locked to them requires a **third seam**, not an extra entry in the exemption list.

Then it stops. It does not create the third seam, name where it goes, or assign it to an epic. AD-2's *Binds* line still reads "FR-5", and FR-5 still reads "A Locked Thread refuses **every** write".

### The two epics

**E1 reads it as scoped out.** AD-2's rule is titled "One enforcement seam for Locked, at ChatManager", covers "posting content into a Thread — eight of the nine enumerated paths", and explicitly says the fourth category needs something AD-2 is not. E1 builds the two-method guard, tests the eight paths, ships. Defensible.

**E1 reads it as scoped in,** because FR-5 says *every* write and AD-2 *Binds* FR-5. E1 builds a third guard inside `ReactionManager` and the edit/delete/pin methods. Also defensible — and it lands in exactly the upstream-owned methods AD-17 says to touch minimally, with no AD saying what the seam looks like, so whatever E1 invents is the precedent.

### Observable symptom

A Thread Manager Locks a Thread to stop a heated exchange. Participants cannot post — and proceed to conduct the argument in reactions on the existing messages, and by editing their own earlier messages. The Thread is Locked against prose and open to everything else. FR-5's testable consequence "every write is refused" is false, and the PRD's Deferred list contains "whether reactions count as writes in a Locked Thread" as an *open product question* — which AD-2 correctly says is a one-line change **only for paths that reach the two choke points**, and reactions do not.

### Proposed AD text

Amend **AD-2**'s scope line and add the assignment:

> **Binds:** FR-3, FR-7, and FR-5 **for the eight content-posting paths only**.
>
> ... The fourth category — reactions, edits, deletes and pins — is **out of scope for the ChatManager gate and in scope for E1**, as a separately specified third seam: a single `ThreadWriteGuard::assertWritable(int $roomId, int $threadId)` called at the top of `ReactionManager::addReaction()` / `deleteReaction()` and of `ChatManager`'s edit, delete and pin methods. It resolves the thread id from the target comment's `topmost_parent_id`, raises the same typed exception as the main gate (AD-4), and shares the same exemption list (AD-14). It is a new class so AD-17's minimal-diff rule costs one line per call site.
>
> If the requester descopes reactions from Locked (PRD Deferred), the guard still ships for edits, deletes and pins; only its exemption list changes. **FR-5 does not ship as "every write is refused" until this seam exists** — an epic that implements the eight paths and stops must say so in its acceptance rather than claim FR-5.

---

## AS-17 — AD-7's new subscription column has no upgrade default, and AD-19 forbids the statement that would fix it

**Severity: High. New in r2.** The two ADs that r2 rewrote now constrain each other.

### The two permitted implementations

AD-7 r2 requires "its own explicit column on `talk_thread_attendees`". AD-19 r2 requires "**No data backfill of any kind** — no table-wide `UPDATE` runs at upgrade time" and "nullable-or-defaulted columns". Neither says what the column's default is, and on an existing deployment that default *is* the answer for every row already in the table.

**E3 ships it `DEFAULT false`,** the conservative choice for a boolean, and the one that matches AD-7's framing that subscription must be positively asserted rather than inferred. Obeys both ADs.

**E3 ships it `DEFAULT true`,** reasoning that every row that exists today was created either by `ensureIsThreadAttendee()` on a reply or by an explicit `setNotificationLevel()` call — both of which are subscriptions under AD-7's own definition. Obeys both ADs.

### Where they collide

`talk_thread_attendees` is not empty on an upgrading deployment; read-created rows are the *new* thing, so every pre-existing row is by construction a subscription. `DEFAULT false` retroactively reclassifies all of them, and AD-19 forbids the one-line `UPDATE` that would repair it.

### Observable symptom

Under `DEFAULT false`: on upgrade, every participant's Followed Thread List empties. Everyone silently unsubscribes from every Thread they had ever replied to. `getRecentByActor()` with the new predicate returns nothing, and **`tests/integration/features/chat-4/threads.feature:224` fails** — the regression guard AD-7 nominates for exactly this case does catch it, which is the good news; the bad news is that the only fix consistent with AD-19 is a schema default, so discovering it late means changing the migration rather than adding a repair.

### Proposed AD text

Amend **AD-7**:

> The subscription column ships `NOT NULL DEFAULT true`. Every row that exists at upgrade time was created by `ensureIsThreadAttendee()` on a reply or by an explicit `setNotificationLevel()`, both of which are subscriptions under this AD's own definition, so `true` is the correct value for all of them and requires no backfill — the default *is* the migration, which is what AD-19 asks for. Read-created rows are inserts made after the upgrade and set the column to `false` explicitly at insert time; they never rely on the default. `threads.feature:224` is the guard that the pre-existing case survives.

---

## Rejected candidates

Constructed, then discarded — recorded so the next reviewer does not spend the time again.

- **AD-3 `hasModeratorPermissions(true)` vs `(false)` drift.** AD-3 already names the exact expression and the exact degradation, lifted from `ThreadController::renameThread()` (line 226-231). Two epics calling one method cannot disagree. This is the spine working.
- **AD-4 folding Locked into `['error' => 'permission']`.** Explicitly forbidden in the AD's own text, with the existing value named. No permitted second reading.
- **AD-11 collation drift between title search and tag matching.** One normalisation function, one stored column, named at both sites. Airtight as written.
- **AD-18 federation affordances.** "Hides rather than renders inert" is unambiguous, and `ThreadController` already routes federated conversations to the proxy on every method (lines 78, 158, 194, 356). No second reading survives.
- **Reactions as writes in a Locked Thread.** Correctly Deferred; AD-2 makes it a one-line exemption change either way, so it does not block an epic. Not a collision.
- **Push payload size budget.** Correctly Deferred with a stated condition and an owner. A measurement, not an architectural hole.
- **`Thread::getName()`'s `'Thread #' . id` fallback (`Model/Thread.php:88-94`) leaking into search and notifications.** Real, and it interacts with AD-11's normalised column (the fallback is computed, never stored, so it is unsearchable). But it is a single-epic defect, not a two-epic collision — E2 owns both the search column and the Directory row. Worth a story-level note; not a spine hole.

---

## Summary

Against spine **r2**. AD-20 is taken (Thread Title sensitivity), so the two proposed new ADs are numbered **AD-21** (ordering/pagination) and **AD-22** (caps).

| # | Collision | Severity | State at r2 | Closes with |
| --- | --- | --- | --- | --- |
| AS-1 | AD-1 and AD-10 name two owners for the tag mappers | Critical | **Open** | Amend AD-1 + AD-10 |
| AS-2 | AD-6's "clears existing rows" destroys AD-7's subscription state | Critical | **Open** | Amend AD-6 + AD-7 |
| AS-3 | `notifySystemMessageSent()` has 3 gates; AD-14 names 2 | Critical | **Open — armed by r2** | Amend AD-14 → 7 registries |
| AS-4 | `getRecentByActor` filters `NEVER`, not `DEFAULT` | Critical | **Closed** | AD-7 r2 ✓ |
| AS-5 | `TalkThread` vs `TalkThreadInfo` mutation responses | High | **Open** | Amend AD-12 + AD-15 |
| AS-6 | Ordering, pagination and client re-sort all unassigned | High | **Open** | **New AD-21** |
| AS-7 | Reaped Thread stays valid in cache for 900s | High | **Open** | Amend AD-1 + AD-5 |
| AS-8 | Guard input undefined; unknowable pre-save in `addSystemMessage` | High | **Open** | Amend AD-2 |
| AS-9 | Notification id arity: sentinel vs omission | High | **Open** | Amend AD-12 |
| AS-10 | Thread reply advances the conversation marker; freeze placement unassigned | High (raised) | **Open — worsened by r2** | Amend AD-6 + AD-8 |
| AS-11 | AD-19 forbids the backfill AD-19 authorises | Med-High | **Closed** | AD-19 + AD-6 r2 ✓ |
| AS-12 | Four caps, no enforcement layer, no publication | Medium | **Open** | **New AD-22** |
| AS-13 | `t_id` vs `th_*` row conventions inside one checklist | Medium | **Open** | Amend AD-1 |
| AS-14 | "Resolves under both factories" ≠ renders | Medium | **Mostly closed** | AD-16 r2 ✓ (residual) |
| AS-15 | Relay path fetches per message; N+1 over HTTP | Medium | **Open** | Amend AD-15 + AD-9 |
| AS-16 | AD-2's third seam is named and given to nobody | High | **New in r2** | Amend AD-2 |
| AS-17 | AD-7's new column has no upgrade default; AD-19 forbids the fix | High | **New in r2** | Amend AD-7 |

**Two new ADs (AD-21 ordering/pagination, AD-22 caps), twelve amendments. 13 open, 4 closed.**

Close before any epic is written: **AS-3** (r2's own amendment makes the symptom reachable for the first time — the verb now survives to the gate that drops its Thread), **AS-1**, **AS-2** (both are direct AD-vs-AD contradictions), and **AS-17** (a schema default, so it is expensive to discover after the migration ships).
