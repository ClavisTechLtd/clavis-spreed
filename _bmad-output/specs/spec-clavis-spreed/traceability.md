# Traceability — Capability × Requirement × Architecture Decision × Issue

Companion to `SPEC.md`. It holds the join no single upstream artifact carries: the PRD's §14 maps issue items to requirements only, and the architecture spine's map runs feature-group to AD only. Downstream reads this to know, for one capability, every requirement it must satisfy and every invariant it must not break.

Requirement numbers (`FR-n`) resolve in `prd.md` §4. Decision numbers (`AD-n`) resolve in `ARCHITECTURE-SPINE.md`. Issue numbers resolve in `ClavisTechLtd/clavis-deploy#22`, whose ten items are the origin of this work.

## The join

| Capability | Requirements | Governing decisions | Issue item |
| --- | --- | --- | --- |
| CAP-1 Thread lifecycle | FR-1, FR-2, FR-3, FR-4, FR-6, FR-7, FR-8, **FR-43** | AD-1, AD-4, AD-5, AD-13, AD-14, AD-19, AD-20 | 1 |
| CAP-2 Locked refuses every write | FR-5 | AD-2, AD-4, AD-14 | 2 |
| CAP-3 Thread Manager authority | FR-9, FR-10, FR-11 | AD-3, AD-4 | 3 |
| CAP-4 Thread Directory | FR-12, FR-13, FR-14, FR-15, FR-16, FR-17 | AD-9, AD-12, AD-15, AD-16 | 4 |
| CAP-5 Thread search by title | FR-18, FR-19, FR-20 | AD-11, AD-12 | 7 |
| CAP-6 Featured Threads | FR-21, FR-22 | AD-1, AD-9, AD-12, AD-19 | 5 |
| CAP-7 Thread Tags | FR-23, FR-24, FR-25, FR-26 | AD-10, AD-11, AD-12, AD-14 | 6 |
| CAP-8 Per-Thread unread state | FR-27, FR-28, FR-29, FR-32 | AD-6, AD-7, AD-9, AD-19 | 9 |
| CAP-9 Conversation unread splits | FR-30, FR-31 | AD-8, AD-12, AD-19 | 9 |
| CAP-10 Notifications name the Thread | FR-33, FR-34 (carrying FR-43's reason) | AD-14, AD-20 | 10 |
| CAP-11 Push payload identifier parity | FR-35, FR-36, FR-37 | AD-12, AD-20 | 8 |
| CAP-12 Thread navigation and addressability | FR-38, FR-39, FR-40, FR-41, FR-42 | AD-15, AD-16 | 4 |

Cross-cutting decisions bind no single capability and constrain all of them: **AD-17** (fork hygiene and minimal marked diffs), **AD-18** (federation degrades by omission), **AD-19** (additive, backfill-free migrations), **AD-12** (additive API and the two capability flags).

## Where the mapping is not one-to-one

Four places where reading a row straight across would mislead.

**Issue item 8 is not delivered by CAP-11.** The issue asks that clicking a push notification opens the thread. The reported symptom originates in the Android client's fallback path when its notification fetch fails, not in the server — Android already routes into Threads by fetching the notification over the API, and web and desktop deep-linking already works. CAP-11 makes the payload self-sufficient so that fetch is no longer needed; the mobile epic delivers the item. Treating this row as "item 8 done" is a misread.

**Issue item 9 over-delivers into two capabilities.** The issue asks for an unread count in the thread list. CAP-8 specifies per-Thread *independence*, which is a larger claim, and independence is what forces CAP-9 — the one deliberate change to a shipped API field's meaning. CAP-9 exists because of item 9 but is not asked for by it, and carries its own regression risk on three unmodified clients.

**Issue item 4 splits across CAP-4 and CAP-12.** "A dedicated thread page level with Shared items" is CAP-4; "entry points in other places, so navigation makes sense" is CAP-12, and which places was the PRD's choice rather than the issue's.

**Issue item 3 says "modify", which is read as four operations.** State, featuring, tags and renaming all fall under CAP-3. Deletion of a Thread Root Message is not an authority question but a lifetime one, settled by AD-5: author or moderator deletion tombstones the comment and the Thread survives; message *expiry* hard-deletes and requires a reaper.

## Requirements with no capability of their own

- **FR-11** (renaming authority unchanged) is a regression guard inside CAP-3, not new behaviour. Its acceptance is the existing integration coverage continuing to pass.
- **FR-36** (following a notification lands in the Thread) largely already works; it sits under CAP-11 so the payload change cannot silently break it.

## Decisions that resolved open questions

All ten questions this work carried are closed. Three were settled by the architecture workflow, seven by the requester. They are recorded here rather than dropped, because a downstream reader needs to know a decision was made and on what grounds, not merely that a question disappeared.

| # | Question | Resolved by | Resolution | Changed |
| --- | --- | --- | --- | --- |
| 1 | What advances the Conversation read marker once Threads have their own | Architecture — AD-6 | A nullable per-participant baseline, frozen lazily at one named seam on **any** advance, moved thereafter only by mark-all-threads-read | Nothing — CAP-8 held |
| 2 | Do reactions count as writes in a Locked Thread | Requester | **Refused.** The seam that refuses edits, deletes and pins is mandatory regardless, and reactions reach the comments store by the same route, so covering them is marginal | CAP-2 states reactions explicitly |
| 3 | Is a Thread identifier content in a sensitive Conversation | Requester | **No — it is routing.** An identifier names a destination without disclosing what is in it | CAP-11 and the sensitivity constraint |
| 4 | Is a reference deployment available | Clavis | **No**, and no customer is committed to answering | Success signal rewritten onto a seeded fixture; PRD §10 replaced by AC-1…AC-7, AC-W1, AC-W2 |
| 5 | Has upstream announced thread lifecycle work | Architecture — AD-17 | Nothing announced; AD-17 contains the divergence instead. Re-check before a major upstream release | Nothing |
| 6 | What is the actual scale to design for | Requester | **≥1000 Threads, 200 participants — a design target, not an observation.** The prompting room holds ~180, so it is a margin that costs nothing | Scale constraint reworded |
| 7 | Does the push size budget fit a Thread Title | Requester | **Non-blocking.** The policy is already stated; the measurement is story-level acceptance criteria, not an epic gate | CAP-10 and CAP-11 are cuttable now; assumption recorded |
| 8 | What happens when the Thread Root Message is deleted or expires | Architecture — AD-5 | Deletion tombstones and the Thread survives; expiry hard-deletes and a bounded reaper removes orphaned rows and invalidates their cache entries | Nothing — CAP-1 held |
| 9 | Should a Thread Manager state a reason when locking | Requester | **Yes, v1** — against the architecture's recommendation. Locking only; a Closed Thread is advisory and needs no explanation | **FR-43 is new.** CAP-1, CAP-2, CAP-10, AD-14, AD-20 and the schema all changed |
| 10 | Should bots change Thread State | Requester | **No, by decision rather than omission.** Reopening it means deciding which bots qualify | Non-goal reworded |

One dependent question rode on #8's neighbours rather than on a numbered slot: whether the dropped `last_mention_direct` column returns. The requester settled the client unread split as **two-way** — unread mention versus unread reply, with direct and group mentions not separated — so it does not. Two columns are reinstated, not three, and `has_unread_thread_directs` keeps its zero callers.
