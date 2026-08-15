# Adversarial Review — Clavis Talk Thread Management PRD

Target: `prd.md` (734 lines, FR-1…FR-39) and `addendum.md` (394 lines), both dated 2026-08-08.
Method: read in full; every claim the PRD makes about upstream behaviour was checked against `clavis-spreed` branch `stable34` @ `6819859`. Code citations below are verified, not repeated from the addendum.

**Verdict.** The document is well-written enough to hide that its three highest-cost features — the unread split, the Directory's default filter, and the notification fix — are each either self-contradictory, undeliverable in MVP, or built on an unpriced change to a shipped API field. 33 findings: 5 critical, 9 high, 13 medium, 6 low. Not ready for the epics workflow.

Severity key: **Critical** = ships a broken product or cannot be built as specified. **High** = a feature or section fails its own stated purpose. **Medium** = an engineer must invent a decision the PRD owed them. **Low** = hygiene.

---

## CRITICAL

### C1. FR-27 silently redefines a shipped API field and breaks the mention badge on three clients the PRD says it does not touch

**Location:** FR-27 (prd.md:408–417), §6.3 "A split notion of 'read'" (:586), §7 "Additive only" (:592).

**The failure.** FR-27 requires: "With unread messages in Thread A, reading A does not change the Conversation's main-chat unread count." Today, main-chat unread *is* thread unread. Verified:

- `lib/Service/RoomFormatter.php:330` → `$roomData['unreadMessages'] = $this->chatManager->getUnreadCount($room, $lastReadMessage);`
- `lib/Chat/ChatManager.php:1002` → `getNumberOfCommentsWithVerbsForObjectSinceComment('chat', (string)$chat->getId(), $lastReadMessage, [VERB_MESSAGE, VERB_OBJECT_SHARED])` — room-scoped, **no thread filter**. Thread replies are comments in the room, so they count.

So FR-27 has exactly two implementations and the PRD picks neither:

1. **Thread replies keep counting toward `unreadMessages`.** Then reading a thread cannot clear those messages from the main-chat count, and `unreadMessages` is permanently non-zero in any room with thread activity. The room badge becomes noise — the exact defect §1 says the feature exists to fix, relocated.
2. **Thread replies stop counting.** Then `unreadMessages` has a **new meaning** for the shipped Android, iOS and desktop clients, which §7 forbids in the same document: "No field removed, renamed, or **given a new meaning**." Their badges silently drop counts on upgrade.

Branch 2 also breaks the mention badge outright. `RoomFormatter.php:335–336`:

```php
$roomData['unreadMention'] = $roomData['unreadMessages'] !== 0 && $lastMention !== 0 && $lastReadMessage < $lastMention;
```

`unreadMention` is **gated on `unreadMessages !== 0`**. An @-mention that arrives inside a thread sets `talk_attendees.last_mention_message` but, once thread replies stop counting, leaves `unreadMessages === 0` — so `unreadMention` computes to `false`. Being @-mentioned in a thread stops showing as a mention on every client. FR-30 requires a *new* thread-mention indication and says it must not "contradict the existing Conversation-level unread badge"; it never notices that the existing badge's derivation is broken by its sibling requirement.

**Also unpriced:** `getNumberOfCommentsWithVerbsForObjectSinceComment` is a **platform** method — declared in `vendor/nextcloud/ocp/OCP/Comments/ICommentsManager.php:231`, implemented in `OC\Comments\Manager`, and **not overridden** by Talk's `lib/Chat/CommentsManager.php` (which defines only `getCommentFromData`, `getCommentsById`, `getForObjectSince`, `getCommentsWithVerbForObjectSinceComment`, `retrieveReactionsByActor`, `searchForObjectsWithFilters`). Excluding thread replies means overriding a platform interface method inside the fork — flatly against §6.1's "extend upstream structures rather than replacing them", and the single largest merge liability in the work. It appears in neither §9.1 nor the addendum's file map. §6.3 discusses this cost *conceptually* ("Conversation unread stops being one number") and prices none of it.

**Fix.** Add an FR that decides, in one sentence, whether thread replies count toward `unreadMessages`, and state the client-compatibility consequence explicitly. Add a consequence to FR-30 requiring `unreadMention`/`unreadMentionDirect` in `RoomFormatter` to be re-derived so a thread mention still surfaces on unmodified clients. Add "ownership of the room unread-count query" to §9.1 and to addendum §4. Until this is decided, FR-26…FR-30 cannot be estimated.

---

### C2. Nothing in the PRD says what the Directory shows for a pinned Closed or Locked Thread — and the addendum's design rationale depends on the answer FR-13 forbids

**Location:** FR-12 (:229–236), FR-13 (:238–245), FR-20 (:317–325), addendum §10.1 (:336).

**The failure.** Three requirements, mutually exclusive on the same row:

- FR-12: "Every Pinned Thread sorts above every unpinned Thread, **whatever their activity or state**."
- FR-13: "Opening the Directory fresh shows Ongoing Threads **and no others**."
- FR-20: "Pinning is independent of Thread State: a Closed or Locked Thread can be pinned and **stays pinned**."

A pinned Closed Thread under the default filter is either shown (violating FR-13) or hidden (violating FR-12 and making FR-20's "stays pinned" cosmetic — pinned to a list it is absent from). No requirement resolves it. Neither does §4.5's description, which motivates pinning by "standing references… which by their nature stop generating replies" — the rota, the checklist — precisely the threads a moderator would Close or Lock to stop chatter in.

**It gets worse: the addendum has already assumed the answer FR-13 forbids.** addendum:336 defends collapsing Discord's `archived`×`locked` 2×2 into a three-value line by asserting the lost cell is covered: *"That case is covered by pinning a Locked Thread, since FR-20 makes pinning independent of state."* The lost cell is **locked-but-still-in-the-live-list**. That only works if a pinned Locked Thread appears under the default filter — which FR-13's testable consequence prohibits. The document's justification for its central lifecycle simplification rests on behaviour its own requirement bans.

**Downstream damage:** SM-1 is defined as "the share of Threads shown by the Directory's default filter, out of all Threads." Until C2 is resolved, the primary metric has no definition.

**Fix.** One consequence on FR-13: *"Pinned Threads are shown under every state filter, including the default, with their state marker; the filter applies to unpinned Threads only."* Then correct addendum §10.1 to cite that consequence rather than FR-20. If the opposite is chosen, delete the addendum:336 defence and re-argue the 2×2 collapse.

---

### C3. A Closed Thread with unread messages produces a room badge no participant can clear

**Location:** FR-30 (:443–452), FR-36 (:518–525), FR-13 (:238–245), FR-29 (:431–441), FR-27 (:416), UJ-1 (:55).

**The failure.** Chain it:

1. FR-30: the Conversation "reports a thread-unread indication when **at least one** of its Threads has unread messages" — no state qualifier.
2. FR-36: the top-bar control "indicates when the Conversation has Threads with unread messages."
3. The participant clicks it. FR-13: the Directory opens filtered to Ongoing, "and no others." The Closed Thread holding the unread messages is not in the list.
4. FR-29's mark-as-read affordance is "from the Directory" — unreachable for a row that is not rendered.
5. FR-27: "A Thread's count returns to zero **only** when that participant has read that Thread to its end." No other clearing mechanism exists.
6. FR-13: the filter "resets to Ongoing when they return to it later." So even a participant who found it once faces the same dead end tomorrow.

Result: a lit badge, a list that explains nothing, and no path to zero short of guessing that a state filter needs changing.

**UJ-1 manufactures this deliberately, at scale.** Hạnh closes four Threads that other people have not read. In a room with 200 participants (the §5 scale condition), every participant with unread messages in those four Threads now carries an unclearable indication. The journey the PRD uses to sell the feature is the journey that breaks it.

**Fix.** Either (a) FR-30 counts only Threads visible under the default filter — then say so, and accept that closing a Thread marks it read for everyone, which needs its own consequence; or (b) FR-13 gains a consequence that the state filter is auto-widened, or an "unread" pseudo-filter is offered, when hidden Threads hold unread messages; or (c) FR-29's mark-as-read is reachable from the indication itself. Any of the three; the PRD currently has none.

---

### C4. §10 contains no metric that can fail a release

**Location:** §10 (:635–656) in full.

Taking each against the section's own stated method — "measured on a consenting reference deployment through direct queries", no telemetry from customer servers:

- **SM-5** ("Median position, in the Directory's default order, of the Thread a participant opens"). **Unmeasurable by construction.** List position is a function of the participant's active state filter, active label filter, pagination depth and the instant of the click. No table records any of it; a direct SQL query cannot reconstruct it. Measuring SM-5 requires client-side interaction telemetry — the thing §10's first sentence says does not exist. **And its target is arithmetically excluded by FR-21:** pinned Threads sort above everything (FR-12) and are bounded "around ten" (FR-21). A room that uses its full pin budget puts every unpinned Thread at position 11 or worse, so "within the first ten rows" is unreachable no matter how good the ordering is. A target that a sibling requirement makes impossible is not a target.
- **SM-6** ("Share of Thread Directory **sessions** that use search"). Sessions are not recorded server-side. Search requests are countable; the denominator is not. Also "Reported, not targeted" — a metric with no threshold and no denominator.
- **SM-2** ("Thread creation rate… comparing the month before and the month after. Target: no decline"). Requires the reference deployment to run the old version, observed, for a month first — never stated as a prerequisite. n=1 deployment, no confidence interval, wholly confounded by seasonality and by the release itself. **And it is directionally ambiguous:** if the Directory works, people find and reuse existing Threads instead of starting duplicates — fewer new Threads. The success case and the failure case produce the same number.
- **SM-1** (live-thread density below 30%). Measurable, but: the baseline "from 100% today" is definitionally 100% because Closed does not exist yet, so the baseline carries no information; 30% is arbitrary and unjustified; it measures moderator diligence, not product value; and it is trivially satisfied by Locking rather than Closing. Its definition is undefined until C2 is resolved.
- **SM-3** (requester confirmation, "answers yes without qualification"). Single subject, and the subject is the person who filed the issue — maximally invested in a yes. No protocol, no script, no defined reading of a qualified answer. Yet it "Validates the whole document."
- **SM-4** ("above half" of Conversations with >50 Threads use a label). One reference deployment. If it has three such Conversations, the target is two. Statistically void.
- **SM-C1** (Locked count "should stay low in absolute terms"). No number. Cannot fail. This is the *only* counter-metric that counterbalances something real — it catches SM-1 being gamed by Locking — and it is left unthresholded.
- **SM-C2** (distinct labels per Conversation, "should stay well under twenty… A room with sixty labels has a taxonomy nobody agreed to"). **FR-22 caps distinct labels at twenty and refuses the twenty-first.** Sixty is unreachable; "well under twenty" watches a ceiling the code enforces. Pure decoration. Assumption 10 (:724) admits this — "the bound SM-C2 watches, **made enforceable** rather than merely observed" — and nobody went back and rewrote SM-C2 or §12's matching "sixty labels" risk (:675).
- **SM-C3** ("Median Thread Directory first-page response time… **Must not regress**"). Regress against what? There is no Thread Directory today, so no baseline exists at the moment the guardrail is needed. No absolute budget is given here or in §5. The document's single performance guardrail has neither a number nor a comparand.
- **SM-C4** is the only honest entry in the section — and it is explicitly post-hoc: it exists "to learn whether lazy materialisation actually bounds growth… **before a customer discovers it does not**." That is a load-bearing assumption validated in production.

**Coverage.** The `Validates FR-…` tags cover FR-2, 11, 12, 13, 14, 17, 20, 22 — 8 of 39. **29 FRs have no metric**, including all of FR-31…FR-35 (issue items 8 and 10) and all of FR-26…FR-30 (issue item 9, the feature §6.3 devotes an entire section to justifying the cost of).

Every primary metric is additionally conditional on open question 5 (:686), unresolved, which assumption 20 (:734) concedes would make the section "aspirational."

**Fix.** Delete SM-5 and SM-6 or specify the instrumentation they need as scoped work. Give SM-C1, SM-C2 and SM-C3 numbers, and give SM-C3 a pre-launch baseline measurement to compare against. Add release-gating acceptance criteria for FR-26…FR-30 and FR-31…FR-35. Resolve open question 5 before the epics workflow, since §10 is void without it.

---

### C5. The three-state lifecycle has no requirement for reopening a Closed Thread, and no concurrency rule anywhere

**Location:** §4.1 (:92–98), FR-2 (:112–120), FR-3 (:122–131), FR-4 (:133–141), FR-6 (:156–163), §14 row 1 (:695).

**Missing transitions.** Six ordered pairs exist. The PRD defines four:

| From → To | Requirement |
|---|---|
| Ongoing → Closed | FR-2 |
| Ongoing → Locked | FR-4 |
| Closed → Locked | FR-4 |
| Locked → Ongoing | FR-6 |
| **Closed → Ongoing by a manager** | **none** |
| **Locked → Closed** | **none** |

FR-3 is the only Closed → Ongoing path and it requires *posting a message*. FR-6 is scoped to "a Locked Thread back to Ongoing." FR-2 is scoped to "an **Ongoing** Thread to Closed."

So: **UJ-2's Dũng, having closed his own Thread, cannot reopen it without posting into it.** The moderator in UJ-1 who closes four Threads and misjudges one must post a message to undo a click. §4.1 sells Closed as "deliberately cheap to get wrong… a premature close costs one message to undo" — that is the design admitting the gap and calling it a feature. It is not: a manager acting on their own mistake should not have to add content to a conversation to do it. And a Locked Thread cannot be de-escalated to Closed at all; you must first reopen it to Ongoing, making it postable, which is the state the manager was trying to prevent.

**Missing races and actors.** The PRD defines no ordering, atomicity or conflict rule for:

- Two Thread Managers acting simultaneously (close vs lock). No last-write-wins statement, no version/etag, no error. FR-2's idempotency covers only same-op-twice.
- **Reopen-by-reply (FR-3) racing lock (FR-4).** A participant's post and a manager's lock interleave. FR-3 says the post succeeds and sets Ongoing; FR-5 says "all attempts to add content… are refused." Both are absolute; nothing makes the read-state-then-write atomic. See H2 — the 900s thread cache makes this a routine outcome, not a rare one.
- A Thread deleted or its root message deleted mid-operation (see H7).
- A moderator demoted between the interface rendering a control (FR-9) and the request arriving. FR-8's last consequence gets the server right ("evaluated per request") and says nothing about what the client does with a refusal it was never told to expect — no error shape, no recovery.
- Idempotency of lock-an-already-Locked and reopen-an-already-Ongoing: FR-2 specifies it for close, FR-4 and FR-6 are silent.
- A bot-created Thread: §2.2 allows bots to create Threads and forbids them managing state, and the glossary makes the root author the Thread Manager. So bot-created Threads have no manager but moderators — never stated, and addendum §9.1 documents an upstream defect where the bot Thread's root and its `thread_created` system message disagree about *which message is the root*, i.e. about who the author is.

§14 row 1 claims issue item 1 ("Thread classification: Ongoing / Closed / Locked") is covered by FR-1, 2, 4, 6, 7. It is not: the transition table is incomplete.

**Fix.** Add FR-2b: a Thread Manager can set a Closed Thread to Ongoing, with a system message; state whether Locked → Closed is permitted. Print the full 3×3 transition matrix in §4.1 with the authorising requirement or an explicit "not permitted" in every cell. Add one cross-cutting FR for concurrent state mutation (last-write-wins with the losing actor informed, or conditional-request semantics) that also governs pin and label writes.

---

## HIGH

### H1. §4.8's flagship failure does not exist on the surfaces this MVP ships, and the PRD admits it two sections later

**Location:** §2.1 (:43), §4.8 (:456–458), FR-34 (:493–501), FR-39 (:548), §9.2 (:626), §11 (:664).

**The failure.** §2.1 calls thread notifications "today the single most jarring failure, because notifications about thread replies land in the room's main chat." UJ-4 is built on it. Verified false for web. `lib/Notification/Notifier.php:602–610`:

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

This is **outside** any push guard — the addendum says so itself (:100, "sets the link, **outside** any push guard") and then does not draw the conclusion. The web link already carries `threadId` and `#message_{id}`, and `src/composables/useGetThreadId.ts` already routes on that query parameter. FR-39's own consequence concedes it: "the behaviour that exists today, preserved" (:548).

So FR-34's testable consequences are: web (already works), Locked-thread arrival (new, small), deleted-thread arrival (new, small). The genuinely broken path is **push → mobile**, and:

- §9.2 defers mobile client adoption to a separate epic (:626).
- §11 says the proxy→APNs/FCM hop is unimplemented, blocked on an Apple key and a Firebase project Clavis does not have, so FR-32/FR-35 "can be verified at the point of payload construction but not end-to-end onto a device" (:664).

Net user-visible improvement from §4.8 in this MVP: FR-31's thread title in notification text, plus FR-33's message-id repair which no shipped client reads yet. That is a real but modest fix, wearing the priority language of the document's worst problem. Whoever sequences epics from §2.1 will rank this first and deliver almost nothing.

**Fix.** Rewrite §2.1's eighth bullet to state that thread deep-linking works on web today and that the defect is push-specific. Re-scope FR-34's web consequence as a regression guard, not a feature. State plainly in §9.1 that §4.8's user-visible MVP value is FR-31 alone, and that FR-32/FR-33 are enabling work for the mobile epic. Re-rank accordingly.

### H2. FR-5's absolute write refusal cannot hold under the caching NFR, and three of its enumerated paths have no thread awareness at all

**Location:** FR-5 (:143–154), §5 cache NFR (:558), addendum §3.2 (:161–176), §5.5 (:265).

**The failure.** FR-5 is absolute: "all attempts to add content to it are refused, from every entry point." Two problems.

**(a) The guard reads a cache the PRD says may be 15 minutes stale.** §5: Thread data is served from a distributed cache with a 900s lifetime and negative caching, and "Every operation in this PRD that changes a Thread must invalidate it." Invalidation is not atomicity. A manager Locks a Thread; a poster's request on another PHP worker reads a not-yet-invalidated entry, sees Ongoing, and the write lands in a Locked Thread. §12's first risk treats this as a *display* problem ("watches it reappear for colleagues"); it is a *correctness* problem for FR-5's guard. FR-5 promises a property the architecture is not required to be able to deliver.

**(b) Editing, deleting and reacting have no thread context to guard on.** Verified: `lib/Controller/ReactionController.php` contains **no** `threadId` reference; nor does `lib/Chat/ReactionManager.php`. Reactions are addressed by message id. Enforcing FR-5's reaction consequence requires resolving `topmost_parent_id` → thread → state on the highest-frequency write path in the product, via `lib/Chat/CommentsManager.php:129–133` — a query the addendum notes is itself marked `FIXME: TEMPORARY method until nextcloud/server#53896 is merged`. The addendum concedes the paths are unlocated: "Editing, deleting and reacting… live on other paths again and **need locating** if those assumptions survive review" (:176). FR-5 mandates them as testable consequences of an in-scope FR anyway.

**Fix.** Add a consequence to FR-5 requiring the Locked check to read authoritative state, not cached state, and name that as an explicit exception to the cache path. Move editing/deleting/reacting out of FR-5 into a separate FR with its own scope decision, and locate the paths before committing. Extend addendum §3.2's table to six-plus-three rows with real call sites.

### H3. §9.1 and §9.2 contradict each other on the same consequence

**Location:** §9.1 first bullet (:613), §9.2 sixth bullet (:632), FR-5 (:153).

§9.1 In Scope: "Thread State… with soft close, hard lock, and write refusal at every entry point into a Thread (**FR-1 … FR-7**)."
§9.2 Out of Scope for MVP: "**Reactions in Locked Threads** — FR-5 refuses them on an assumption that may not survive review. §13."

FR-5 is in scope and mandates refusing reactions; §9.2 says refusing reactions is out of scope. An engineer reading §9 — the section whose entire job is to answer "do I build this?" — cannot answer it. Note also §9.2 lists this as a *deferral*, which is the wrong instrument: the choice is not "later", it is "yes or no", and open question 2 is marked "Non-blocking; a change here is a small change" — true of the decision, false of a scope section that contradicts itself.

**Fix.** Resolve open question 2 now (it is one line either way), delete the §9.2 bullet, and make FR-5's reaction consequence say yes or say nothing.

### H4. FR-30's "free" implementation is cheap to set and expensive to clear — and clearing it is exactly the unbounded work §5 forbids

**Location:** FR-30 (:443–452), addendum §4.6 (:231–235), §5 NFR (:556).

**The failure.** The addendum's proudest discovery is verified true: `talk_attendees.has_unread_threads`, `has_unread_thread_mentions`, `has_unread_thread_directs` exist with zero real callers — grep for `HasUnreadThread` in `lib/` and `src/` returns only the six `@method` phpdoc lines in `lib/Model/Attendee.php:69–74`. No migration needed. Correct.

The inference is wrong. addendum:235: "Writing the callers is the whole of FR-30's storage work." Setting the flags is cheap — one `UPDATE … WHERE room_id = ?` per thread message. **Clearing them is not.** To clear `has_unread_threads` when a participant finishes reading Thread A, the server must determine whether *any other* Thread in that Conversation still holds unread messages for that participant — a query across that participant's Thread Read Markers plus the Conversation-marker fallback for every Thread they have never opened, on every thread read. In a Conversation with 1,000 Threads and 200 participants (§5's stated condition), that is work proportional to the Conversation's Thread count, on a per-read path. §5: "Returning a page of Threads must not cost work proportional to the Conversation's total Thread count." The NFR forbids it and the addendum calls it free.

Compounding: the mention flag inherits C1's derivation break, and `RoomFormatter.php:456–457` already fabricates `unreadMessages = 1` for a user who has never read the room. FR-30's "does not duplicate or contradict the existing Conversation-level unread badge" has to contend with an existing fabrication that no requirement mentions.

**Fix.** Add a consequence to FR-30 bounding the cost of *clearing* the flags, and name the mechanism (e.g. maintain a per-participant count of Threads-with-unread rather than a boolean, so clearing is a decrement). Correct addendum §4.6 to say the columns remove the *migration*, not the work.

### H5. UJ-3 requires search to override the state filter; FR-13 and FR-14 forbid it

**Location:** UJ-3 (:61), FR-13 (:243), FR-14 (:253), FR-17 (:291), FR-38 (:541).

UJ-3: Linh "types 'invoice' into the thread search box; two threads match by title, **one of them Closed**." She arrived at a Directory that FR-13 opened filtered to Ongoing.

- FR-13: "Opening the Directory fresh shows Ongoing Threads **and no others**."
- FR-14: "Label and state filters **combine**."
- FR-17: "Search covers **all** Threads in the Conversation **regardless of Thread State**."

FR-17 says search ignores state. FR-13 says the view shows Ongoing only. FR-14 establishes that filters compose. No requirement says whether search replaces, suspends or intersects with the active state filter — and the journey the PRD wrote to demonstrate the feature only works under the reading FR-13 prohibits.

FR-38 adds a third answer for the same surface: "A distinct route opens the Directory **filtered to nothing**, positioned on this Thread's row" — a third default state for the same Directory, incompatible with FR-13's "opening fresh."

**Fix.** One consequence on FR-17: *"An active state or label filter is suspended while a search term is present, and restored when it is cleared; results show state."* Then make FR-13's "fresh" and FR-38's "filtered to nothing" consistent with it, or delete FR-38's phrase.

### H6. The label cap is a one-way door: a room can permanently exhaust its label budget with typos

**Location:** FR-22 (:354), FR-14 (:254), §8 (:601), §4.6 (:340), §9.2 (:630).

FR-22: "A Conversation may accumulate up to twenty distinct Thread Labels; creating a twenty-first is refused."
§8: "There is **no label administration surface**, no per-Conversation approved set, no renaming a label across Threads, no label hierarchy." §9.2 defers "deleting one everywhere" to v2.
FR-14: "Only labels **in use** in this Conversation are offered."

FR-14 governs *offering*, not existence. Nothing says whether a label with zero Threads still occupies one of the twenty slots. If it does — and FR-23's colour binding requires the label↔colour row to persist independently of any Thread, which is why addendum §5.1 says identity must be "a first-class row rather than a string on the thread" — then twenty typos permanently exhaust a Conversation's label budget with no recovery path in v1. §4.6 names near-duplicate labels as "the recognised failure mode" and offers input suggestion as the mitigation; suggestion does not un-create `escalaton`, which is the exact typo UJ-5 stages (:67).

Note also that §4.6's mitigation doctrine — "in input, **not enforcement**" — is contradicted two requirements later by FR-22's two hard enforced caps.

**Fix.** Add a consequence to FR-22 stating whether a label with no Threads counts toward the twenty, and if it does, add the one operation that makes the cap survivable: a label unused by any Thread is reclaimed automatically. That is not "label administration" and does not reopen §8.

### H7. Thread Manager authority silently evaporates when the Thread Root Message is gone — upstream already handles this and the glossary asserts the opposite

**Location:** §3 Glossary "Thread Root Message" and "Thread Manager" (:74, :80), FR-8 (:184–193), FR-34 (:501), FR-21 (:327).

Glossary: "a Thread **cannot exist without** it." Upstream disagrees. `lib/Controller/ThreadController.php:216–222`:

```php
try {
    $comment = $this->chatManager->getComment($this->room, (string)$threadId);
    $isOwnMessage = $comment->getActorType() === $attendee->getActorType()
        && $comment->getActorId() === $attendee->getActorId();
} catch (NotFoundException) {
    // Root message expired, only moderators can edit
}
```

Threads outlive their root messages routinely — message expiration, moderator deletion, retention policy. When that happens, `$isOwnMessage` stays `false` and **authorship-derived authority disappears**: the Thread's creator can no longer rename it, and under FR-8 would silently lose the ability to close, lock, pin or label their own Thread. The PRD asserts the Thread Manager rule "Mirrors the authority rule upstream already applies to renaming" (:180, FR-10) — the mirror is accurate right down to this exception, and the PRD states neither the exception nor the rule that root deletion is even possible. It is **untagged**: not an `[ASSUMPTION]`, not an open question, not in §16.

Unspecified consequences of root deletion, all reachable today: does the Thread's state survive? Does a pinned Thread whose root is deleted continue to occupy one of FR-21's ~ten pin slots? Do its labels persist and continue to count toward FR-22's twenty? Do its Thread Read Markers get reclaimed? Does it still appear in the Directory and in FR-30's unread indication? FR-34 handles exactly one corner — following a notification to "a Thread since deleted" (:501) — which proves the authors knew the case exists.

Worse under FR-5: "Editing **or deleting** a message that already exists in a Locked Thread is refused" (:152). So the root of a Locked Thread cannot be deleted, but the root of a Closed or Ongoing Thread can — orphaning its state, pins, labels and markers. Retention policy and moderator deletion do not consult Thread State.

**Fix.** Correct the glossary: a Thread survives the deletion or expiry of its Thread Root Message. Add an FR defining, for that case, who the Thread Manager is (moderators only, matching upstream), what happens to state/pin/labels/markers, and whether the Thread remains listed. Tag it in §16 if any part is inferred.

### H8. FR-21's bound is "around ten" — unimplementable, and it defeats SM-5

**Location:** FR-21 (:327–334), assumption 9 (:723), SM-5 (:648).

FR-21's consequences require the limit to be (a) named in the refusal error, (b) "stated in the interface before the participant hits it", (c) "a server-side constant, identical for every Conversation." The assumption then supplies: "a hard bound **around ten**." You cannot name "around ten" in an error, state it in the interface, or declare it as a constant. The one requirement in §4.5 that exists solely to fix a number does not contain one.

And whatever number is chosen collides with SM-5 (see C4): pins occupy the top of the default order, so a pin budget of ten makes SM-5's "within the first ten rows" unreachable for any unpinned Thread.

**Fix.** Pick an integer. If SM-5 survives C4, set the pin bound below it and say why.

### H9. UJ-4 is staged on a client the PRD says it does not modify

**Location:** UJ-4 (:63–64), §2.2 (:48), §7 preamble (:590), §11 (:666).

§7: "This is a public OCS API consumed by **three shipped clients that this epic does not modify**." §11 and §9.2 name Android and iOS as the deferred pair — so the third unmodified client is Talk Desktop, a separate Electron application with its own notification handling.

§2.2: "the improved deep-linking is visible on **web and desktop** only."
UJ-4: "Minh has **the desktop client** open in the background… He clicks it and lands in that thread, scrolled to the new message."

The journey that justifies the entire §4.8 feature runs on a client the PRD does not touch, does not scope, does not test, and does not name in §11's dependency list. Either desktop notification handling is in scope (a scope leak, un-estimated, and §7's "three clients" count is wrong) or UJ-4 describes behaviour this epic does not deliver. Combined with H1 — the web path already works — UJ-4 as written demonstrates nothing the MVP adds.

**Fix.** Name the three unmodified clients explicitly in §7. Restage UJ-4 on the web client, or add Talk Desktop to §11 with its own scope statement and verification plan.

---

## MEDIUM

### M10. §12 says the comparable-product research is pending; §4.1 and addendum §10 present it as complete and decisive

§12 (:677): "Mitigation: comparable-product research, **pending at the time of writing**, feeding a naming decision before the interface strings are written."
§4.1 Notes (:176): "Comparable-product research (addendum §10) **found** that Closed carries three incompatible meanings… The requester has **confirmed** Ongoing / Closed / Locked."

The document contradicts itself about whether its own central piece of evidence exists. A reader auditing risk coverage will conclude the naming risk is open when §4.1 has closed it, and will not trust the rest of §12's status claims. **Fix:** rewrite §12's entry to state the research is complete, name the residual risk (the issue-tracker reading of "Closed"), and point at FR-7's mitigation consequence as the control.

### M11. FR-3 cites Discord as authority for a rule the addendum explicitly warns against citing Discord for

FR-3 (:131): "This asymmetry matches Discord's documented rule and is deliberate, not an oversight — see addendum §10."

addendum §10.1 (:340) carries a ⚠️ recording that Discord's own help centre contradicts itself on whether closing a forum post also locks it, and concludes: "Unresolved. **Do not cite Discord as authority** for coupling close with lock."

And the quoted rule (addendum §10.2) is about setting the `archived` field via API — "When setting `archived` to `false`, when `locked` is also `false`, only the `SEND_MESSAGES` permission is required" — which is adjacent to, not the same as, "posting into a Closed Thread auto-reopens it." The precedent is thinner than the sentence claims. The underlying design is still defensible on Zulip's evidence (addendum §10.3), which is genuinely strong. **Fix:** cite Zulip's published reasoning for the advisory-marker design and Discord for the permission asymmetry only, and drop "matches Discord's documented rule" from FR-3.

### M12. Consequences an engineer cannot write a passing test for

Every one is filed under a heading that reads **"Consequences (testable)"**:

- FR-7 (:174): "The Closed indicator **conveys** that the Thread can still be posted in and that posting reopens it, rather than **reading as** a shut Thread." A comprehension outcome. No string, no protocol, no oracle. This is the sole mitigation for the naming risk §4.1 spends a paragraph on.
- FR-13 (:244): "the filter **makes clear** that Threads are being hidden rather than absent."
- FR-15 (title, :257): "Each Directory row carries **enough to decide** without opening."
- FR-16 (:272): "The first page returns **without the participant waiting** on the full Thread count." No threshold. Also in tension with FR-13's requirement to convey *how many* are hidden, which needs the count §5 forbids computing.
- FR-25 (:382): "presented as a new label, **visually distinct** from picking an existing one."
- FR-29 (:438): "The boundary remains visible **while the participant reads** and does not jump as new messages arrive." Unbounded duration.
- FR-30 (:452): "surfaces it in a way that **does not duplicate or contradict** the existing Conversation-level unread badge." A design instruction with no defined contradiction predicate — and per C1 the existing badge is what breaks.
- FR-31 (:470): "the truncation does not push out the message preview **entirely**." One surviving character passes. Vacuous.
- FR-35 (:508): "A notification for a Thread with a maximum-length title and a maximum-length message preview **is delivered**." §11 (:664) says delivery to a device is unverifiable — no Apple key, no Firebase project, `NotImplementedSender`. The PRD requires a test its own dependency section says cannot run.
- FR-10 (:209): "Renaming behaviour and permissions are **identical** before and after this work, verified by the existing integration coverage." Circular: existing coverage passing does not establish identity, and FR-1 adds a state field to the Thread representation that `renameThread` returns (`ThreadController.php:258–261`), so the response is *not* identical.
- §5 (:555): "Every list surface must stay **usable** in a Conversation with at least a thousand Threads." No latency budget anywhere in the document; the only number is SM-C3, which has no number either.

**Fix.** Replace each with a mechanical assertion (a required string or ARIA label, a millisecond budget, a pixel/character bound, a named oracle), or move it to a design-intent note outside the "Consequences (testable)" list. Do not leave prose under a heading that promises tests.

### M13. "Storage is the reason upstream removed per-Thread markers" is an uncited motive, stated at two different confidence levels, and load-bearing

§4.7 (:392): "**Storage is the reason** upstream removed per-Thread markers, and it is addressed by materialising a Thread Read Marker only for Threads a participant has actually read."
§6.3 (:582) and §12 (:673) hedge the identical claim: "This is the cost that **plausibly** drove upstream to drop these columns."

Verified: `Version22000Date20250623142327.php:87–103` created `last_read_message`, `last_mention_message`, `last_mention_direct`, `read_privacy` on `talk_thread_attendees`; `Version22000Date20250710124258.php:48–61` dropped all four. The *fact* is right; the *reason* is unevidenced — no issue, PR or commit message is cited. Nowhere is it tagged `[ASSUMPTION]`.

It matters because the mitigation is derived from it. If upstream dropped the columns for a **semantic** reason — they could not define the fallback for never-opened Threads, which is *precisely* the PRD's own open question 1 (:682) — then lazy materialisation solves a problem upstream did not have and leaves the one it did. That is not a hypothetical: open question 1 exists, is unresolved, and is deferred to architecture.

**Fix.** Cite the upstream PR or issue, or downgrade §4.7 to "plausibly" and tag it in §16. Then check open question 1's three options (addendum §4.4) against whatever upstream actually said.

### M14. Search-as-you-type across 1,000+ Threads with no debounce, rate limit or budget

FR-17 (:292): "Results appear as the participant types, without a submit action."
FR-16 (:275): "Filtering and searching apply across **all** Threads in the Conversation, not only those already loaded" — so the query is server-side, not client-store.

Under §5's condition (1,000 Threads, 200 participants) that is one server round-trip per keystroke against `talk_threads.name`, with FR-17's diacritic-insensitivity requiring either a collation-dependent comparison or a normalised column (addendum §5.4). No debounce interval, no minimum term length, no rate limit, no response budget is required anywhere. FR-19 extends the same behaviour across *every* Conversation for the Followed Thread List, which loads in the left sidebar at app start.

**Fix.** Add consequences to FR-17: a minimum term length, a debounce interval, and a response-time budget under the §5 scale condition. Add a bound to FR-19's cross-Conversation query.

### M15. The cache inventory is incomplete — the unread-count cache is untouched and directly affected

§5 (:558) and addendum §5.5 (:265) name exactly one cache: `talk.threads`, prefix `thread/`, 900s, negative caching. §5 calls invalidation "the single highest-risk correctness surface in the work."

There is a second, and the unread work lands on it. `lib/Chat/ChatManager.php:109,134` → `$this->unreadCountCache = $cacheFactory->createDistributed(CachePrefix::CHAT_UNREAD_COUNT)`, TTL **1800s** (`:1003`), keyed `{roomId}-{lastReadMessage}`, cleared at `ChatManager.php:214, 318, 362, 482, 659` and `ParticipantService.php:755–756`. The key encodes the room and the read marker but **not** the thread — so any per-Thread count layered on this mechanism collides, and C1's redefinition of `unreadMessages` changes the cached value while leaving the key identical: stale for up to 30 minutes across an upgrade.

**Fix.** Add `CachePrefix::CHAT_UNREAD_COUNT` to §5's NFR and to addendum §5.5, with its TTL, its six clear sites, and a statement of whether per-Thread counts are cached at all. If they are, the key must include the thread id.

### M16. The Directory and an open Thread are two orthogonal bits of state in one URL, and the PRD requires three incompatible behaviours of it

- FR-11 (:227): "Opening the Directory does not change which Thread, if any, the user has open."
- FR-37 (:533): "Taking it leaves the Thread open behind the Directory, so **dismissing** the Directory returns the participant to where they were."
- FR-39 (:549–551): "The Thread Directory has an address that reopens it on the same Conversation… **Browser back and forward** move between the main chat, a Thread, and the Directory in the order visited."

Today the scheme is `/call/{token}?threadId={id}#message_{messageId}` and there is no thread route (addendum §1.5; `src/router/router.ts` has no thread or directory entry). FR-39 requires the Directory to be a history entry; FR-37 requires it to be a dismissible overlay over a preserved Thread. Overlay-dismissal and browser-back are different models, and no requirement says what the address looks like when a Thread and the Directory are both open — nor which of the two the back button unwinds first.

**Fix.** Add a consequence to FR-39 stating the address shape for each of the four states (main chat; Thread; Directory; Directory over Thread) and which transitions push history entries.

### M17. FR-38 needs a server capability no requirement provides

FR-38 (:541): "A distinct route opens the Directory filtered to nothing, **positioned on this Thread's row**."

Under FR-16's incremental loading, that row may be on page seven of a filtered, sorted, pinned-first list. Positioning on it requires computing a Thread's offset under a given filter and sort — a "find the rank of thread X" operation. addendum §5.3 records that `getRecentByRoomId()` "clamps its limit to 1–50 and takes **no offset**", and `getRecentByActor()` takes limit and offset only. Nothing in FR-11…FR-16 requires a rank or seek-to-row capability, and offset-only pagination cannot provide one cheaply.

**Fix.** Either add the capability as a consequence of FR-16 (with its cost acknowledged against §5's no-unbounded-work NFR), or weaken FR-38 to "opens the Directory with this Thread's row loaded and highlighted, fetching the page containing it."

### M18. FR-5's scheduled-message failure needs a sender-notification path that is in no scope list

FR-5 (:150): "A message scheduled into a Thread before it was Locked does not post when its time arrives; it is **marked failed with a reason the sender can see**."

The firing path is `lib/BackgroundJob/SendScheduledMessages.php` — no request context, no session, no client to return an error to (addendum §3.2 flags exactly this: "**No live request.**"). "A reason the sender can see" therefore requires either a new notification type or a new failure state surfaced in the scheduled-messages UI, plus a web client change to render it. Neither appears in §9.1, in §14, or in the addendum's file map. §9.1's notification bullet covers only thread title, thread id, identifier preservation and size budget.

**Fix.** Add the sender-visible failure surface to §9.1 as its own line item, or reduce the consequence to "the scheduled message is recorded as failed with a machine-readable reason" and defer the surfacing.

### M19. FR-1's "every endpoint that returns a Thread" reaches signalling and the federation proxy, both of which the PRD disclaims

FR-1 (:109): "The Thread representation returned by the API includes the state on **every endpoint that returns a Thread**."

Two of those are outside the PRD's declared boundary:

- **Signalling.** addendum §9.2 documents `lib/Signaling/Listener.php:621–635` emitting `threadInfo` with `'first' => $thread->toArray($room)` — a `Thread` where the REST shape puts a `ChatMessage`, and `'last' => null`. So the signalling payload does not match `TalkThreadInfo` and is already malformed. §5's real-time NFR (:564) requires state/pin/label changes to reach other participants "over the signalling path Talk already uses for thread events," so FR-1 and the NFR both land on a payload the PRD files under "unrelated upstream defects, neither is in scope" (addendum:313).
- **Federation proxy.** `lib/Federation/Proxy/TalkV1/Controller/ThreadController.php` and `UserConverter::convertThreadInfo()`. §7 (:596) requires federation to "degrade, never lie" and present threads "as it does today." FR-1 requires state on every endpoint. Which wins for a federated Thread is unstated — and `renameThread` is `#[FederationSupported]` and proxies at `ThreadController.php:202–206`, so state/pin/label endpoints that are *not* proxied will make FR-9's "offer only what the actor may do" require the client to distinguish per-conversation which management controls exist. No FR says that.

**Fix.** Scope FR-1 to the five REST endpoints in addendum §1.3, explicitly. Add a consequence to FR-9 that in a federated Conversation only renaming is offered. Decide whether fixing the signalling `threadInfo` shape is in scope, since §5's real-time NFR depends on it.

### M20. §14 omits FR-3 — the document's most surprising semantic appears in no traceability row

Verified: FR-1…FR-39 are all present and uniquely numbered; **FR-3 appears nowhere in §14's table.** Row 1 ("Thread classification: Ongoing / Closed / Locked") lists FR-1, FR-2, FR-4, FR-6, FR-7. Row 2 lists FR-5, FR-6.

FR-3 — posting into a Closed Thread reopens it — is what makes Closed mean something different from every issue tracker the addendum surveys, is the mechanism §4.1 leans on to make closing "a habit rather than a decision," and is the reason FR-7 needs its untestable "conveys" consequence. §0 (:14) claims "every one of those ten is traced to a requirement here, and §14 maps them explicitly **so nothing is silently dropped**." The table maps issues→FRs only, so it structurally cannot detect a requirement that no row claims. **Fix:** add FR-3 to row 1 and add a reverse check (every FR appears in at least one row) to the PRD's own completeness claim.

### M21. FR-9 forbids disabled controls; FR-5 mandates a disabled composer

FR-9 (:200): "A non-manager viewing a Thread sees no state, pin or label controls — **not disabled ones**."
FR-5 (:154): "The web composer is **disabled with a visible explanation** when the open Thread is Locked."

Two opposite doctrines for unavailable affordances in the same feature, with no rule for which applies where. Both are defensible (hide what you never have; disable-and-explain what you normally have and temporarily lack) but the PRD states neither principle, so the two requirements read as a contradiction to anyone implementing them.

**Fix.** State the principle once in §4.2 or §5: authority-based unavailability hides; state-based unavailability disables and explains. Then both requirements follow from it.

### M22. Assumption 15 implies a Thread Read Marker write on every post, which §6.3's cost model does not include

FR-26 (:403): "The count excludes the participant's own messages. `[ASSUMPTION: own messages never count as unread, matching Conversation-level behaviour.]`"

Conversation-level behaviour achieves this by *advancing the sender's read marker on send* — `ChatManager.php:269` and `:474` call `participantService->updateLastReadMessage(...)`. Matching it per Thread means writing the Thread Read Marker on every **post**, not only on every read, and materialising a row for any Thread a participant posts into. §6.3 (:582) models writes as read-driven only: "one row per participant per Thread they read, **updated every time they read further**." Under FR-3, any participant may post into a Closed Thread, so the materialisation path widens too.

**Fix.** Add the send-path write to §6.3's cost model and to the FR-26 consequence, or specify the alternative (compare actor id at count time rather than advancing a marker) and its cost.

---

## LOW

### L23. §16 is complete against the inline tags — and that is a narrower claim than it appears to make

Verified: 23 `[ASSUMPTION` occurrences in `prd.md`, of which two are meta (:16 describing the convention, :713 the index header), leaving 21 real tags; §16 has 20 numbered entries, with FR-22's pair (:353, :354) correctly merged into item 10. **The index is complete and accurate against what is tagged.**

The dishonesty is in what was never tagged. Untagged decisions that are load-bearing and inferred: the Thread Manager rule's behaviour when the root message is gone (H7); whether thread replies count toward `unreadMessages` (C1); whether pinned non-Ongoing Threads appear under the default filter (C2); upstream's motive for dropping the marker columns (M13); §5's "a thousand Threads and two hundred participants," which open question 7 (:688) concedes is inferred from "groups with very many threads" while §5 asserts it as "the condition the requirements are written against, **not a stretch target**." An index of tags is not an index of assumptions, and §0 (:16) invites the reader to treat it as one.

**Fix.** Tag the five above and add them to §16, or add a sentence to §16 stating that it indexes tagged assumptions only and that untagged inferences exist.

### L24. Assumption 1 is flagged as the document's foundation and then built on anyway

Assumption 1 (:715) — personas and journeys inferred, no interviews, no tickets, no usage data — describes itself as "**Highest-leverage item in this list: the journeys drive the whole document**." §2 (:32) asks to "Confirm or replace before the epics workflow consumes §2.3." Yet §9 commits all 39 FRs, §10 derives every metric from those journeys, and §14 declares full issue coverage.

Meanwhile the requester was demonstrably reachable: §4.1 (:176) records them confirming the state names, and open questions 2, 3, 4 and 7 are all assigned to them. If the requester could confirm three interface words, the personas driving 39 requirements could have been confirmed too. The `[ASSUMPTION]` mechanism is being used where a question would have done.

**Fix.** Gate the epics workflow on §2 confirmation explicitly in §9, or downgrade the FR set to the subset that survives if the personas are wrong.

### L25. FR-33's "as it does today" is factually wrong for two cases

FR-33 (:490): "A notification about a message outside a Thread carries the message identifier **as it does today**."

Verified at `lib/Notification/Notifier.php:633`: the whole enrichment block, including `setObject(..., objectId . '/' . messageId)`, is guarded by `if (!$this->notificationManager->isPreparingPushNotification() && !$participant->getAttendee()->isSensitive())`. Today the message identifier is absent for push notifications **and** for participants in sensitive Conversations. The addendum quotes the guard (:116) and describes it only as a push guard, missing the `isSensitive()` half.

So "as it does today" describes one of three cases. And no FR says whether a sensitive non-push notification should gain the message identifier — assumption 17 (:481) reasons only about the thread identifier and the title.

**Fix.** Correct FR-33's consequence to name the three cases (in-app non-sensitive, in-app sensitive, push) and state the intended shape for each. Correct addendum §2 to describe the guard as push-or-sensitive.

### L26. Open question 1 is deferred, but FR-26 and FR-27's consequences are already written as if it were resolved

Open question 1 (:682) — what advances the fallback baseline once Threads have their own markers — is "**Deferred to the architecture workflow by the requester**" and simultaneously "must be settled there, not discovered in code." But FR-26's last consequence (:406) and FR-27's second (:414) already assert specific behaviour that presupposes an answer, and addendum §4.4's three options produce *different* observable behaviour for the never-opened-Thread case. The architecture workflow will inherit testable consequences that may contradict the decision it is being asked to make.

**Fix.** Mark FR-26's fallback consequence and FR-27's main-chat consequence as provisional pending open question 1, or resolve it in the PRD (addendum §4.4 already argues for option 3 and the argument is sound).

### L27. Asymmetric idempotency

FR-2 (:120): "Closing a Thread that is already Closed succeeds without duplicating the system message." FR-4 and FR-6 are silent on locking an already-Locked Thread and reopening an already-Ongoing one. Three requirements, one idempotency rule. **Fix:** state it once for all state transitions.

### L28. Reply count and unread count are computed on different populations

FR-15 (:259) requires each row to show "reply count"; upstream serves this from `talk_threads.num_replies`. FR-26 (:404) requires the unread count to exclude system messages. §4.1's assumption 3 (:98) adds a system message on every state change. If `num_replies` counts system messages, a Thread closed and reopened twice shows a reply count of 4 and an unread count of 0, and §4.1 already concedes the pattern "does add noise to short threads." **Fix:** state whether reply count excludes system messages, and align it with FR-26.

### L29. The document is still marked provisional while handing itself downstream

`status: draft` (:3) and "*Working title — confirm.*" (:10), against §0's instruction that "downstream BMad workflows… turn it into architecture and stories" and §9's commitment of 39 FRs. Two open questions (5 and 8) are marked as blocking — 5 voids §10, 8 "Blocks FR-31 and FR-35" — and neither is resolved. **Fix:** either resolve the blocking questions and lift the draft status, or state in §0 which sections downstream workflows may not yet consume.

---

## Summary

| Severity | Count | Findings |
|---|---|---|
| Critical | 5 | C1–C5 |
| High | 9 | H1–H9 |
| Medium | 13 | M10–M22 |
| Low | 6 | L23–L29 |

**What the PRD gets genuinely right** (so the criticism above is calibrated, not indiscriminate): the notification defect at `Notifier.php:633–642` is real and correctly diagnosed — the second `setObject` does overwrite the first, destroying the message id. The three dead columns on `talk_attendees` have zero real callers and do fit FR-30's shape, saving a migration. The dropped marker columns were created 2025-06-23 and dropped 2025-07-10, as claimed. The Thread Manager rule does mirror `ThreadController::renameThread`'s authority check, exception included. The `threads` capability does ship unconditionally at `Capabilities.php:130`, so §7's new-flag requirement is correct. addendum §10's comparable-product research is cited, load-bearing on the naming decision, and honest about its own contradictions — which is exactly why C2 and M11, where the PRD ignores the addendum's own warnings, are worth flagging.

**Before the epics workflow can run:** C1 (decide what `unreadMessages` means), C2 (one consequence on FR-13), C3 (a clearing path for hidden unread), C5 (complete the transition matrix and add a concurrency rule), H3 (resolve the reactions scope contradiction), H8 (pick an integer). C4 needs §10 rewritten or replaced with acceptance criteria, which assumption 20 already anticipates.
