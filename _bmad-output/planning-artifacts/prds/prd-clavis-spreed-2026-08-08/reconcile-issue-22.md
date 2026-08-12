# Reconciliation — Issue #22 against `prd.md` + `addendum.md`

Source: GitHub issue `ClavisTechLtd/clavis-deploy#22` (title, framing sentence, ten numbered items, one author comment).
Target: `prd.md` (FR-1 … FR-39) and `addendum.md`, dated 2026-08-08.

Method: each source unit read in Vietnamese and matched against FR *statements* and their *testable consequences* separately, because a broad FR statement with narrow consequences is what gets built. Two PRD claims about existing code were verified against `stable34` rather than taken on trust; both held (§3, §4 below).

## Verdict summary

| # | Source unit | Verdict | Requirements |
|---|---|---|---|
| — | Title | Covered (partial) | §5 NFR, §9.2 |
| — | Framing sentence | Covered (partial) | §1, §2.1, FR-13, SM-1/3/5 |
| 1 | Ongoing / Closed / Locked, "giống discord" | **Covered** | FR-1, FR-2, FR-4, FR-6, FR-7 |
| 2 | Locked blocks messaging; reopen via Ongoing | **Covered** | FR-5, FR-6 |
| 3 | Only opener or room admin may "sửa đổi thread" | Covered (partial) | FR-8, FR-9, FR-10 |
| 4 | Dedicated page peer of Shared Items; Thread button "ở các chỗ khác" | **Reinterpreted** | FR-11 … FR-16, FR-36 … FR-39 |
| 5 | Pin / unpin thread | **Covered** | FR-20, FR-21 |
| 6 | Tag / label for thread | **Covered** | FR-22 … FR-25 |
| 7 | Search to navigate to a thread | Covered (partial) | FR-17, FR-18, FR-19 |
| 8 | Push notification click enters the thread | **Reinterpreted** | FR-32, FR-33, FR-34 |
| 9 | Unread message count in the thread list | **Covered** | FR-26 … FR-30 |
| 10 | Thread title on all thread-related notifications | Covered (partial) | FR-31, FR-35 |
| — | Comment: no plugin-first path | **Covered** | addendum §8 |

**Covered 11** (6 complete, 5 partial) · **Reinterpreted 2** · **Dropped 0**

Nothing in the source is absent from the PRD. The losses are all of *qualifier* and *scope*, not of topic.

---

## Title

> "Chức năng thread đang thiếu nhiều thứ để phục vụ việc điều hướng, **quản lý dễ dàng hơn** ở các nhóm có **rất nhiều thread**"

Three claims: navigation, *easier* management, at rooms with very many threads. The PRD title carries the first and third ("Thread Management and Navigation"), and §5's NFR fixes the scale condition at a thousand Threads and two hundred participants.

**Partial on "dễ dàng hơn."** The comparative is about *effort*, and the PRD holds effort constant per thread. Bulk operations — closing or labelling many at once — are deferred to v2 (§9.2). So in the room the title describes, management is 180 individual row-menu interactions. FR-29 grants mark-as-read from the Directory but there is no equivalent close-from-the-Directory-in-bulk. §9.2 admits the deferral is "likely requested alongside auto-close for the same reason," which concedes the point.

Also note the scale figure is inferred, not sourced: open question 7 asks the requester for "real numbers from the room that prompted the issue," so the NFRs that operationalise "rất nhiều thread" are currently guesses.

## Framing sentence

> "Hiện tại Thread thiếu chức năng **đóng / phân loại** nên khi hiển thị ra nhiều **bị rác**, **khó điều hướng**."

Both named gaps are covered: "đóng" → FR-2, "phân loại" → FR-1/FR-13 (state) and FR-22/FR-14 (labels). The causal chain — no close/classify, therefore clutter, therefore hard to navigate — is reproduced faithfully and forcefully in §1 Vision and §2.1's "Keep a busy room legible."

Partial for the reason set out in the qualitative section below: the *felt* problem survives in prose and in SM-3, but no requirement enforces it.

## Item 1 — Ongoing / Closed / Locked (giống discord) — Covered

Named states appear verbatim in the glossary and §4.1. FR-1 defaults to Ongoing and migrates existing threads; FR-2/FR-4/FR-6 provide the transitions; FR-7 makes state visible on all four surfaces. The Discord reference was checked rather than assumed: addendum §10.1–10.2 confirms this PRD's Closed is Discord's `archived` and its Locked is Discord's `locked`, including the reply-reopens-it rule, quoted from Discord's channel resource.

One deliberate narrowing, disclosed: Discord keeps `archived` and `locked` orthogonal (a 2×2), and the PRD collapses this to a three-value line, losing the *locked-but-still-in-the-live-list* cell. Addendum §10.1 names the lost cell and routes it to pinning (FR-20 makes pinning state-independent). Since the issue itself asked for a three-way classification, the collapse follows the issue rather than Discord — correct call, and honestly recorded.

Addendum §10.1 also flags that Discord's own help centre contradicts itself on whether closing a forum post locks it, with a warning not to cite Discord as authority for coupling the two. That is the right level of care for a "giống discord" instruction.

## Item 2 — Locked blocks messaging; reopen through Ongoing — Covered

> "Khi Locked thì ko được **nhắn tin** vào đó, nếu cần lôi lại thì phải **mở ra ở trạng thái Ongoing**"

Both clauses land. FR-5 refuses writes; FR-6 restricts reopening to a Thread Manager and targets Ongoing specifically — there is no Locked → Closed transition, matching "phải mở ra ở trạng thái Ongoing". FR-6's third consequence closes the obvious loophole by stating that a Locked thread does *not* reopen by reply the way a Closed one does.

**Scope expansion, not a gap.** The issue says only "nhắn tin" (send messages). FR-5 additionally refuses file shares, rich objects, polls, scheduled-message firing, bot posts, edits, deletes and reactions. The first five are enumerations of the same act through other doors, and addendum §3.2 maps all six write paths to real call sites — including `SendScheduledMessages.php:145-179`, which has no live request and would have been missed. The last three go beyond the ask; the PRD flags editing/deleting (assumption 5) and reactions (assumption 6, "weakest assumption in the document", open question 2) rather than smuggling them in. Handled correctly.

Addendum §3.2 also catches that `shareObjectToChat()` and `createPoll()` currently *silently* reset an invalid thread to 0, so a Locked thread would post to the main chat instead of erroring — a real trap the FR-5 enumeration exists to catch.

## Item 3 — "sửa đổi thread" — Covered (partial)

> "Những người **mở thread** hoặc **quản trị của room** thì mới có quyền **sửa đổi thread**"

Two mappings, both verified against the code rather than accepted:

1. **"người mở thread" → "author of its Thread Root Message."** Verified equivalent in current Talk. Every `createThread` call site (`ChatController.php:430`, `BotController.php:206`, `SendScheduledMessages.php:177`, `SystemMessage/Listener.php:459`) keys the new thread to `(int)$comment->getId()` of the message the actor has just posted, and creation is gated on `$replyTo === 0 && $threadId === THREAD_NONE && $threadTitle !== ''`. There is no path to open a thread on somebody else's existing message. So opener and root author are the same person, and the glossary's substitution is sound rather than a paraphrase that drifted.
2. **"quản trị của room" → Conversation moderator.** Direct.

**The qualifier that got narrowed: "sửa đổi thread" is unqualified — *modify the thread* — and FR-8 enumerates a closed set: state, pin/unpin, labels.** FR-10 then covers renaming by holding existing behaviour constant. That turned out to be enough, which is worth stating because it could easily not have been: `ThreadController::renameThread()` at `:214-228` already computes `$isOwnMessage` against the root comment's actor and falls through to `hasModeratorPermissions(false)`, so upstream's rename rule *is* opener-or-moderator. §4.2's claim that this "matches the rule upstream already applies to renaming" is accurate.

Two residues:

- **Root-message expiry is unspecified.** The rename path handles it explicitly — `// Root message expired, only moderators can edit` at `:221` — so when the root comment is gone, the opener silently loses authority and only moderators remain. FR-8's consequences never mention this case, so the state/pin/label endpoints have no stated answer for it. This is exactly the kind of behaviour that gets decided by whoever writes the code first.
- **An enumerated authority set cannot absorb future modifications.** Any thread-modifying operation added later (thread deletion, moving a thread, changing a thread's root) falls outside FR-8's list by construction. §8's "Not a thread permission system" non-goal is about *who*, not *what*, so it does not cover this. Minor today — no delete-thread endpoint exists — but the source sentence was open-ended and the requirement is not.

## Item 4 — Reinterpreted (both halves)

> "Cần có 1 trang list thread riêng **ngang hàng với Shared Items** chứ ko để trong shared items nữa cho dễ nhìn, và cần đặt nút Thread **ở các chỗ khác** để điều hướng cho hợp lý"

### Half A — "ngang hàng với Shared Items"

What was asked: a thread list page that is a *peer* of Shared Items. Shared Items is a right-sidebar tab (`RightSidebar.vue`, `id="shared-items"`, `:order="5"`, sitting in a strip with chat / participants / breakout-rooms / details). The natural reading of "ngang hàng" is therefore: another tab in that same strip, at the same level, with its own order — which is precisely what does not exist today. Verified: the threads list is rendered as a lone `NcAppSidebarTab` inside a `v-else-if="contentState === 'threads'"` that *replaces the entire normal tab set*, reachable only from `SharedItemsTab`'s `showThreadsTab` emit. Addendum §1.6 describes this correctly.

What was written: the glossary preserves the requester's phrase — "A destination at the same level as Shared items, not nested inside it" — but **no FR consequence is testable for the peer relationship.** FR-11's four consequences test (a) a top-bar control, (b) reachability on narrow viewports, (c) removal from Shared items, (d) that opening the Directory does not change the open thread. The rendering surface is never specified. Every one of those consequences can pass with the Directory implemented as a full-screen route, a modal, or the same tab-set-replacing `v-else-if` it is today — none of which is "ngang hàng với Shared Items."

Defensible? Half. The "chứ ko để trong shared items nữa" half is covered emphatically and better than asked — FR-11 removes the nested list *and* the three-item preview rather than leaving a second path. But the positive structural instruction was converted into a prominence claim ("first-class destination") and then tested only via the top bar. Downstream architecture is free to satisfy the letter and miss the request.

### Half B — "nút Thread ở các chỗ khác" (plural)

What was asked: Thread buttons in other places, plural, unspecified — "để điều hướng cho hợp lý."

What was written: §4.9 picks three — top bar (FR-36), thread header (FR-37), Thread Root Message (FR-38) — plus addressability (FR-39). §4.9's description quotes the issue's phrase, so the reinterpretation is conscious.

Two problems:

- **The choice of places is entirely the PRD's and is not flagged.** Twenty assumptions are indexed in §16 and none of them is "these are the three places." Compare FR-13's filter-reset or FR-21's pin bound, both far smaller inferences, both tagged. The one place the requester *did* implicitly name — peer of Shared Items — is the one left untestable. So the document is more rigorous about small invented bounds than about the invented answer to an explicit instruction.
- **The most obvious other place is missing: the conversation list / left sidebar.** Addendum §1.6 records that a global Threads nav button already exists at `LeftSidebar.vue:254-260` over the followed-threads list, and FR-19/FR-28 extend those rows. But no FR puts a *per-conversation* Thread entry point in the conversation list — the surface a user is on before they enter a room, and the natural place to see "this room has unread threads" and jump straight in. FR-36's consequence puts that unread indication on the top bar, which is inside the room, i.e. only visible after you have already navigated. For a requester whose complaint is navigation across a room with very many threads, the outside-the-room entry point is a live omission.

## Item 5 — Pin / unpin — Covered

FR-20 gives both operations, makes pins Conversation-wide rather than personal, and makes pinning independent of state (which is also what absorbs the 2×2 cell dropped in item 1). FR-12 guarantees pinned-first ordering. FR-21 adds a bound the issue did not ask for, tagged as assumption 9 and justified — unbounded pinning would recreate the problem the Directory exists to solve. Addendum §5.2 correctly notes the bound must be enforced at write time since a sort cannot enforce a count.

## Item 6 — Tag / label — Covered

> "Cần có **tag / label** cho thread"

FR-22 … FR-25. The source offered two words and the PRD keeps one, deliberately: addendum §10.6 establishes that Microsoft Teams tags are @-mentionable groups of *people*, so "tag" on a thread would misread for anyone arriving from Teams — "The issue says 'tag / label'; only the second word survives." That is a documented, evidenced naming decision, not a silent drop.

Additions beyond the ask — colour, Conversation-scoped colour binding, case-insensitive identity, five per thread, twenty per conversation — are all tagged (assumptions 10–12) and the caps are lifted from Discord's shipped `available_tags` / `applied_tags` limits (addendum §10.5) rather than invented. §8 is explicit that free text over a moderated set is the requester's choice, with label administration named as the v2 answer and SM-C2 watching for proliferation.

## Item 7 — Search — Covered (partial)

> "Cần có chức năng search để **điều hướng về thread** cho thuận tiện"

The source names the *goal* (navigate to a thread) and not the *key*. The PRD chooses title: FR-17 (diacritic-insensitive, incremental, all states), FR-19 (same over the Followed Thread List), FR-18 (handoff to message search on empty results).

The narrowing is defensible and well-argued — §4.4 distinguishes "I remember there was a thread about X" from "find the message where someone said X" — and it is backed by a real capability rather than a hand-wave: addendum §5.4 confirms `MessageSearch.php:292-316` already puts `threadId` into the deep link and `SearchMessagesTab.vue:223-235` already reads it, so message search *already* navigates into a thread. Between FR-17 and the existing message search, the stated goal is reachable.

Two residues:

- **FR-18's handoff fires only on empty results.** A title search that returns three wrong matches dead-ends: the user sees results, so no handoff appears, and the thread they wanted is found by neither key. The partial-failure case is more common than the zero-result case.
- **Scope is room-local plus followed threads.** There is no search across threads in conversations the user does not follow. Given the issue's framing is about navigating within a busy room, this is a reasonable v1 line — but it is a line the source did not draw.

Also worth noting FR-17's diacritic-insensitivity (assumption 8) is the one requirement here with real portability risk; addendum §5.4 flags that it depends on collation across non-uniform database engines and recommends a normalised column. Good catch, correctly escalated.

## Item 8 — Push notification click enters the thread — Reinterpreted

> "**Push notifications** của tin nhắn trong thread khi click vào thì nó phải **vào trong thread luôn** (hiện tại đang nhảy vào nhóm chat chung)"

What was asked: I click a push notification and land inside the thread. The parenthetical fixes the current behaviour being complained about — it jumps to the main group chat.

What was written: FR-32 puts the Thread identifier in push payloads; FR-33 stops the message identifier being destroyed; FR-34 makes following a notification open the thread — **on web**. Its first consequence reads "Following the notification on web opens the Conversation with the Thread open and the message in view."

Why this is a reinterpretation and not coverage: a push notification is, by construction, the mobile case. The PRD excludes mobile client adoption in three places (§2.2 non-users, §9.2 out of scope, §11 follow-up epic), and §2.2 is candid that "the improved deep-linking is visible on web and desktop only." So the literal request — click push, arrive in thread — is not delivered by this PRD. Worse for verification, §11 and addendum §6 establish that the Clavis push proxy's hop to APNs/FCM is *unimplemented*, blocked on an Apple `.p8` key and a Firebase project Clavis does not have, with `NotImplementedSender` failing loudly. So FR-32 and FR-35 "can be verified at the point of payload construction but not end-to-end onto a device."

The diagnosis underneath is excellent and I confirmed the shape of it against `Notifier.php:604-642`: the link is built outside the push guard (so it carries `threadId`) while `setObject()` is inside `if (!isPreparingPushNotification() ...)`, and the second `setObject` overwrites `{token}/{messageId}` with `{token}/{threadId}` — destroying the very message id the comment two lines above says it is forwarding. Two real defects, precisely located.

**But §14's traceability note is wrong in a way that matters.** Row 8 reads "Server-side defect, not a client gap — §4.8." It is both. The server must send the identifier *and* Android/iOS must read it and route; §11 says exactly that ("Android and iOS must read the Thread identifier from the payload and route into the Thread"). A reader who consults only §14 — which is what §0 advertises it for, "so nothing is silently dropped" — will conclude item 8 ships in this epic. It does not. The sequencing decision is right; the traceability line oversells it.

## Item 9 — Unread count in the thread list — Covered

> "ở phần **list thread** cần có tính năng hiển thị **số tin nhắn unread**"

Fully covered and then some. FR-26 gives the count with the right exclusions (own messages, system messages) and the right new-member behaviour; FR-28 shows it in both list surfaces, satisfying "ở phần list thread" for the Directory and the Followed Thread List; FR-29 adds first-unread positioning and mark-as-read from the list; FR-30 adds a Conversation-level thread-unread indication. Addendum §4.6 finds that `talk_attendees.has_unread_threads` / `has_unread_thread_mentions` / `has_unread_thread_directs` already exist with **zero callers**, so FR-30 needs no migration — a genuinely valuable discovery.

**One attribution problem worth recording.** §4.7 opens "This is the behaviour the issue asks for" about *per-thread independence*. The issue asks for a displayed count. Independence is the PRD's inference — a sound one, since a count derived from the single Conversation marker would clear every badge at once and be worthless — but it is an inference, and it is the most expensive decision in the document. It drives: reinstating columns upstream created and deliberately dropped three weeks later (addendum §4.1), "the highest-write-rate table this feature touches" (§6.3), the largest single merge liability on the fork (§6.1), a risk entry, counter-metric SM-C4, and open question 1. Assumption 14 in §16 covers only the *mechanism* (lazy materialisation), not the *requirement*. So the document's single largest cost is booked to the requester rather than owned as a derivation — the one place where a load-bearing inference is presented as source text. It should be tagged and confirmed, because if the requester would have accepted a has-unread boolean, most of §6.3 evaporates.

## Item 10 — Thread title on thread-related notifications — Covered (partial)

> "**Các loại** push notifications / notifications **liên quan đến thread** phải kèm cả tiêu đề của thread để dễ nhận biết"

"Các loại" is the qualifier — *all kinds* of thread-related notifications. FR-31's statement is pleasingly broad ("A notification generated by activity in a Thread"), but **its testable consequences name only two kinds: reply and mention.** The consequences are what become stories and tests.

The shared message-notification path at `Notifier.php:275` gates on nine subjects: `reply`, `mention`, `mention_direct`, `mention_group`, `mention_team`, `mention_all`, `chat`, `reaction`, `reminder`. Every one can concern a message inside a thread. FR-31 tests two. `reaction`, `reminder` (a self-set reminder on a message inside a thread) and `chat` (the catch-all for notify-always rooms) have no stated coverage, and the three group-mention variants are not distinguished from plain `mention`.

Second residue, and this one is created by the PRD itself: **the thread lifecycle events invented in FR-2/FR-4/FR-6 generate no notification at all.** Closing, locking and reopening produce system messages (§4.1), FR-26 explicitly excludes system messages from unread counts, and nothing anywhere notifies a thread's followers that their thread was locked. The chosen design is discover-on-arrival (UJ-4's edge case). Coherent, but it means the newest kinds of "notifications liên quan đến thread" are the ones with no title requirement because they do not exist — and a member who was mid-discussion learns the thread is shut only by trying to post. Given item 2 grants moderators the power to shut a conversation, silent shutting is a defensible-but-unexamined choice that the source's "dễ nhận biết" (easy to recognise) argues against.

Also: the deliverability of item 10 is openly unresolved. Open question 8 "Blocks FR-31 and FR-35," because the platform notifications app RSA-encrypts each payload to a bound addendum §6 estimates at "a couple of hundred bytes" — which is why the preview is already truncated to 100 characters. FR-35's assumption 19 (title wins, preview yields) is the right priority but is untested against the real limit.

Finally, FR-31's last consequence withholds the Thread Title in conversations marked sensitive — a deliberate carve-out from "phải kèm cả tiêu đề" (must include the title), justified in §6.2 and paired with FR-32's assumption 17 that routing information is not content, so a client can still route without leaking. Correct trade, correctly flagged.

## The comment — no plugin-first path — Covered

> "kiến trúc con talk & spreed này ko làm kiểu plugin first được mà phải chọc vào code"

Captured verbatim, in Vietnamese, in addendum §8 Rejected alternatives, with the reason expanded: "Talk's architecture has no extension point for thread semantics, message-send interception or list surfaces. The fork is modified directly, which is why PRD §6.1's divergence guardrails exist." That is the right treatment — the comment is the *authority* for the whole approach, and it is recorded as such with its consequence named.

One placement note: `prd.md` never mentions it. §6.1 presents fork-modification and its guardrails as a starting premise, so a reader of the PRD alone does not learn that a plugin-first approach was considered and ruled out *by the requester*, nor that the direct-modification cost was accepted with eyes open. Since §6.1's guardrails and §6.3's accepted divergences are the most consequential constraints in the document, the sentence that authorises them belongs in the PRD, not only in the companion.

---

## Qualitative intent the FR structure silently dropped

The framing sentence's complaint is perceptual: with many threads displayed, the room is **"rác"** — garbage, clutter — and therefore **"khó điều hướng."** The question is whether the PRD keeps that felt problem in view or converts it into requirements that could all pass while the room still feels like garbage.

**What is preserved, genuinely.** §1 Vision is the strongest part of the document on exactly this point: "an archaeological record," "A room with two hundred threads is a room where nobody uses threads, because finding the one you want costs more than starting a new one," "The feature defeats itself at exactly the scale it was built for." §2.1's first job-to-be-done is "Keep a busy room legible," marked as "the primary driver of the issue." UJ-1's climax is stated perceptually — "the list now shows only what is actually live, and she can see it in one screen without scrolling." SM-3 is explicitly designed as the backstop for this exact failure: the requester, asked a month later whether the room is now navigable, answering yes without qualification — "the only metric that catches getting every requirement right and the product wrong." A PRD that names that risk in its own metrics section has not been careless about intent. The risk register even names the recursion: "Label proliferation reproduces the original problem... a sixty-label filter is the unnavigable list again."

**What the FR structure nonetheless drops.** All 39 requirements can pass while the room still reads as clutter, for three reasons.

1. **The entire decluttering mechanism is one FR, and nothing feeds it.** FR-13's default-Ongoing filter is the only requirement that removes anything from view. But no requirement *produces* Closed threads. Closing is manual by explicit non-goal ("Not automatic lifecycle. ... Every state change is a person deciding"), bulk close is deferred to v2, and FR-1 migrates *every* pre-existing thread to Ongoing. So on ship day, the 180-thread room the issue was filed about opens its shiny new Directory and shows 180 Ongoing threads — the same undifferentiated list, one surface over. The clutter does not decrease by a single row until a human performs 180 manual closes through a row menu. SM-1's target of "below 30% within a month" is therefore not a property of the software; it is a forecast of unbudgeted moderator labour, and §9.2's own note concedes a moderator facing a thousand stale threads "will ask for [auto-close] within a release." The requirement set delivers the *capacity* to declutter and declines to deliver the *decluttering*, which is what the framing sentence actually complains about.

2. **The surface where the clutter is most visible is untouched.** "Khi hiển thị ra nhiều bị rác" — when many are displayed, it is garbage. Verified in `src/stores/chat.ts:55-61`: in main-chat context the client shows non-thread messages *plus every thread's root message* (`message.id === message.threadId`). So each thread leaves one permanent artefact in the main timeline, and a room with 180 threads has 180 root messages interleaved through its scroll-back forever. No requirement removes, collapses, folds or de-emphasises the root message of a Closed or Locked thread. FR-7 does the opposite — it *adds* a state marker to the Thread Root Message in the main chat, putting more furniture on the cluttered surface. The PRD's whole answer to clutter lives on a new, separate surface; the surface the user looks at all day is left exactly as noisy, plus badges. (To the PRD's credit, state-change system messages do *not* leak into the main chat — they carry a `threadId` and the same filter excludes them — so §4.1's system messages are contained. The root-message accumulation is the real residue.)

3. **The requirements measure structure, not felt legibility.** FR-15 enriches each row with title, state, labels, reply count, last activity and unread count — six fields where there was one, which is more to read per row, mitigated only by SM-C3 watching response time rather than density. FR-13's own consequence *requires* the filter to signal "that Threads are being hidden rather than absent," so the hidden clutter stays advertised. And the one metric that would catch a still-cluttered room, SM-3, rests on assumption 20 — that a reference deployment exists and the customer will answer — which open question 5 flags as unresolved and asks Clavis to settle "before the epics workflow runs." Remove that assumption and the perceptual check disappears, leaving SM-1 and SM-5, both of which a moderator's manual labour could satisfy in a room that still feels bad.

**Judgment.** The intent is not silently dropped — it is loudly preserved in §1, §2.1 and SM-3, more explicitly than most PRDs manage. But it is preserved as *narrative and metric*, never as *requirement*: no FR is falsifiable against "the room feels cluttered," and the single mechanism that would make the room feel different (fewer things shown) depends on human effort the document deliberately declines to reduce in v1. The gap to close before the epics workflow is not another capability; it is either (a) an in-scope path from 180 stale Ongoing threads to a short list — bulk close, or a one-time migration heuristic, or auto-close accepted into v1 — or (b) an explicit acceptance criterion that the Directory's default view in the reference deployment's largest room fits one screen, which would make FR-13's promise testable instead of aspirational.

---

## Cross-cutting notes

- **Traceability table (§14) is accurate for nine of ten rows.** Row 8 overstates (see item 8). Row 3's "Mirrors existing renaming authority" is verified true. Row 10's note correctly surfaces the size-budget risk.
- **Assumption tagging is uneven in one direction.** Twenty assumptions are indexed, including small bounds (pin limit, label caps, filter reset). Two larger inferences are untagged: the choice of navigation entry points (item 4) and the requirement of per-thread unread independence (item 9). Both are inferences the requester should confirm, and both are more consequential than several that are tagged.
- **The addendum carries claims the PRD depends on, and the four I spot-checked all held:** rename authority at `ThreadController.php:214-228`, thread-creation call sites keying to the actor's own comment, the `Notifier.php:604-642` push-guard defect, and the main-chat/thread message filter at `chat.ts:55-61`. Line-number drift caveat in the addendum preamble is appropriate; the pointers were accurate at `6819859`.
