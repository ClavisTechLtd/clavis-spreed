---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment', 'remediation-2026-08-09']
documentsAssessed:
  - _bmad-output/planning-artifacts/prds/prd-clavis-spreed-2026-08-08/prd.md
  - _bmad-output/planning-artifacts/prds/prd-clavis-spreed-2026-08-08/addendum.md
  - _bmad-output/planning-artifacts/architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md
  - _bmad-output/planning-artifacts/epics.md
  - _bmad-output/specs/spec-clavis-spreed/SPEC.md
  - _bmad-output/specs/spec-clavis-spreed/traceability.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-08-09
**Project:** clavis-spreed

## 1. Document Inventory

### PRD
| File | Size | Modified | Status |
|---|---|---|---|
| `prds/prd-clavis-spreed-2026-08-08/prd.md` | 99,975 B | 2026-08-08 21:23 | **SELECTED** (r3, final) |
| `prds/prd-clavis-spreed-2026-08-08/addendum.md` | 53,974 B | 2026-08-08 21:23 | **SELECTED** (companion) |
| `prd.superseded-r1.md` | 76,884 B | 2026-08-08 18:07 | superseded — excluded |
| `addendum.superseded-r1.md` | 40,834 B | 2026-08-08 18:07 | superseded — excluded |
| `review-adversarial.md`, `review-code-claims.md`, `review-rubric.md`, `reconcile-issue-22.md` | — | — | review history — reference only |

### Architecture
| File | Size | Modified | Status |
|---|---|---|---|
| `architecture/architecture-clavis-spreed-2026-08-08/ARCHITECTURE-SPINE.md` | 52,853 B | 2026-08-08 21:25 | **SELECTED** |
| `reviews/review-adversarial-seams.md`, `reviews/review-reality-check.md`, `reviews/review-rubric-walker.md` | — | — | review history — reference only |

### Epics & Stories
| File | Size | Modified | Status |
|---|---|---|---|
| `epics.md` | 145,062 B | 2026-08-09 00:08 | **SELECTED** (4 epics, 27 stories, 277 ACs) |

### Spec Kernel (supplementary)
| File | Status |
|---|---|
| `specs/spec-clavis-spreed/SPEC.md` | **SELECTED** — distilled machine contract |
| `specs/spec-clavis-spreed/traceability.md` | **SELECTED** — CAP-1..CAP-12 FR→AD join table |

### UX
⚠️ **WARNING: No UX document found.** No `*ux*` artifact exists under `_bmad-output/`. UX-vs-epics alignment cannot be assessed independently; PRD UI sections are treated as the substitute source.

### Duplicate Resolution
No whole-vs-sharded duplicates exist for any document type. The `*.superseded-r1.md` files are explicitly versioned as superseded and are excluded by name; no user action required.

---

## 2. PRD Analysis

**Source:** `prds/prd-clavis-spreed-2026-08-08/prd.md` (r3, status `final`, 839 lines) + `addendum.md`
**Read:** complete, both halves (lines 1–420, 420–839).

### 2.1 Functional Requirements Extracted

Requirement statements are quoted from the PRD; each FR additionally carries 3–13 "Consequences (testable)" bullets in the source, which are the acceptance surface downstream stories must cover.

#### §4.1 Thread Lifecycle
- **FR-1: Thread State exists and defaults to Ongoing** — Every Thread has a Thread State of Ongoing, Closed, or Locked. (4 consequences)
- **FR-2: A Thread Manager can close a Thread** — A Thread Manager can set an Ongoing Thread to Closed. (5 consequences)
- **FR-3: Posting into a Closed Thread returns it to Ongoing** — Any participant who may post in the Conversation can post into a Closed Thread, and doing so sets the Thread back to Ongoing. (6 consequences)
- **FR-4: A Thread Manager can lock a Thread** — A Thread Manager can set a Thread to Locked from either Ongoing or Closed. (5 consequences)
- **FR-5: A Locked Thread refuses every write** — While a Thread is Locked, all attempts to add content to it are refused, from every entry point. **Nine distinct write paths enumerated as separate consequences**, plus edits/deletes/pins/reactions, plus the narrowly-scoped system-message exemption. (13 consequences)
- **FR-6: A Thread Manager can reopen a Closed Thread directly** — without posting a message. (4 consequences)
- **FR-7: A Thread Manager can reopen a Locked Thread** — set a Locked Thread back to Ongoing. (3 consequences)
- **FR-8: Thread State is visible everywhere a Thread appears** — on every surface that represents a Thread. (5 consequences)
- **FR-43: A Thread Manager can state a reason when locking** — optional free-text reason, shown wherever the Locked state is explained. (8 consequences) *Added in r3, resolving open question 9.*

#### §4.2 Thread Management Authority
- **FR-9: Thread management is restricted to Thread Managers** — only Thread Root Message author or Conversation moderator may change state, feature, or tag. (5 consequences)
- **FR-10: The interface offers only what the actor may do** — controls appear only for actors who may use them, not disabled ones. (3 consequences)
- **FR-11: Existing renaming authority is unchanged** — verified by existing integration coverage. (1 consequence)

#### §4.3 Thread Directory
- **FR-12: The Thread Directory is a first-class destination** — reachable directly, without passing through Shared items. (5 consequences)
- **FR-13: The Directory lists Featured Threads first, then by activity** (3 consequences)
- **FR-14: The Directory filters by Thread State, and Featured Threads survive the filter** — default filter is Ongoing. (4 consequences)
- **FR-15: The Directory filters by Thread Tag** (4 consequences)
- **FR-16: Each Directory row carries enough to decide without opening** — title, state, tags, reply count, last activity, unread count. (4 consequences)
- **FR-17: The Directory loads incrementally** — bounded first page, fetch-on-scroll. (4 consequences)

#### §4.4 Thread Search
- **FR-18: A participant can search Threads by title** — case- and diacritic-insensitive, substring, all states, live results. (5 consequences)
- **FR-19: Title search always offers the message search alongside it** — handoff available at zero, one or many results. (3 consequences)
- **FR-20: The Followed Thread List is searchable by title** — cross-Conversation. (3 consequences)

#### §4.5 Featured Threads
- **FR-21: A Thread Manager can feature and unfeature a Thread** (5 consequences)
- **FR-22: The number of Featured Threads per Conversation is bounded** — server-side constant, assumed five. (3 consequences)

#### §4.6 Thread Tags
- **FR-23: A Thread Manager can tag a Thread** — max 5 tags per Thread, max 20 distinct tags per Conversation. (6 consequences)
- **FR-24: A tag's colour is fixed within a Conversation** — case-insensitive identity, fixed palette. (5 consequences)
- **FR-25: A Thread Manager can change a tag's colour for the Conversation** — propagates live. (3 consequences)
- **FR-26: Tag input surfaces existing tags before creating new ones** (4 consequences)

#### §4.7 Thread Unread State
- **FR-27: Each Thread reports a Thread Unread Count for the current participant** — excludes own messages and system messages; never-opened Threads fall back to the Conversation read marker. (6 consequences)
- **FR-28: Thread unread state is independent per Thread** — reading main chat clears nothing; leave/rejoin does not inherit stale state. (6 consequences)
- **FR-29: A participant can clear unread state without opening a Thread** — mark-one and mark-all, mark-all reaches filter-hidden Threads. (4 consequences)
- **FR-30: Conversation unread counts main-chat messages only** — and the mention indication is decoupled from that count. **The one deliberate breaking API change**; needs its own capability. (5 consequences)
- **FR-31: The Conversation reports whether it has unread Threads** — separate from main-chat unread; distinguishes replies from mentions. (6 consequences)
- **FR-32: The Directory and the Followed Thread List show unread state** — including first-unread positioning and a stable unread boundary. (8 consequences)

#### §4.8 Thread-Aware Notifications
- **FR-33: Thread notifications name the Thread** — all **nine** in-thread notification subjects, one test each; sensitive Conversations withhold the title. (4 consequences)
- **FR-34: Thread lifecycle changes notify the Thread's followers** — close, lock, reopen; respects per-Thread notification level; reopen-by-reply emits no extra state notification. (6 consequences)
- **FR-35: Push payloads carry the same identifiers as in-app notifications** — append-only composed identifier; sensitive Conversations carry identifiers but not the title. (5 consequences)
- **FR-36: Following a notification lands the recipient in the Thread** — including Locked and deleted-Thread cases. (4 consequences)
- **FR-37: Push notification text stays within its size budget** — title wins over preview; Vietnamese-safe truncation boundaries. (3 consequences)

#### §4.9 Thread Navigation Entry Points
- **FR-38: The Conversation top bar opens the Thread Directory** — capability-gated, unread-indicating, keyboard/screen-reader accessible. (3 consequences)
- **FR-39: A Thread's own header links to the Directory** (2 consequences)
- **FR-40: The Thread Root Message links to the Directory** (2 consequences)
- **FR-41: Thread destinations are linkable and restorable** — copyable addresses, reload restoration, browser back/forward. (4 consequences)
- **FR-42: Thread routing works in every surface that renders a Conversation** — including the Files sidebar's separate router instance. (2 consequences)

**Total FRs: 43** (FR-1 … FR-43, no gaps).

### 2.2 Non-Functional Requirements Extracted

From §5 Cross-Cutting NFRs (unnumbered in source — numbered here for traceability):

- **NFR-1 (Scale):** Every list surface stays usable at ≥1,000 Threads and 200 participants per Conversation. Adopted design target (open question 6, resolved).
- **NFR-2 (No unbounded work per request):** A page of Threads must not cost work proportional to total Thread count. Thread Unread Count named as the likely regression site.
- **NFR-3 (Bounded per-participant state):** Thread Read Marker growth tracks Threads *read*, not Threads that *exist*; rows reclaimed when a participant leaves a Conversation.
- **NFR-4 (Cache correctness):** Thread data is served from a distributed cache with a 15-minute TTL and negative caching. Every mutating operation must invalidate. *Flagged in-source as "the highest-risk correctness surface in the work."*
- **NFR-5 (Concurrency):** Later write wins; the earlier actor's client is corrected rather than left displaying a state the server does not hold. Applies to state, featuring and tags alike.
- **NFR-6 (Migration safety):** Schema changes apply in place on large live customer data with no maintenance window, and are safe to run twice (idempotent).
- **NFR-7 (Capability degradation):** Every new surface and changed field is gated on a declared capability and degrades to current behaviour rather than erroring.
- **NFR-8 (Accessibility):** Keyboard reachable, screen-reader announced, state never conveyed by colour alone — specifically Thread Tags, where a tag's text always accompanies its colour.
- **NFR-9 (Theme):** Tag palette and state indicators legible in light and dark themes, normal and high contrast.
- **NFR-10 (Localisation):** All new user-visible text translatable; Thread Titles and Tags never translated; Vietnamese correct in search matching, truncation and sorting.
- **NFR-11 (Real-time consistency):** State, featuring and tag changes reach other participants without a reload, over Talk's existing signalling path for thread events.

**Total NFRs: 11.**

### 2.3 Additional Requirements and Constraints

**§6 Constraints and Guardrails**
- **C-1 (§6.1):** Fork must keep taking upstream releases; no plugin extension point exists, so the fork is modified directly. Extend upstream structures rather than replacing them.
- **C-2 (§6.1):** Where upstream deliberately did *not* do something, record why we do it anyway — the live case is the per-Thread read marker, which upstream added and dropped three weeks later.
- **C-3 (§6.1):** **The repository is a shallow clone with a single commit of history. Restoring full history is a stated prerequisite for the work, not part of it.**
- **C-4 (§6.2):** Notifications respect the sensitive-Conversation setting; Thread Titles withheld wherever message previews already are.
- **C-5 (§6.2):** Nothing may move a Thread Title into any part of a push payload the Clavis push proxy can inspect. Stated as a customer commitment, not an implementation detail.
- **C-6 (§6.3):** Four costs of per-Thread unread accepted deliberately: storage/write volume, a changed API field, upstream divergence, and a split notion of "read".

**§7 API Surface and Compatibility**
- **C-7:** Additive-only, with FR-30 as the single recorded exception; no other change may follow its precedent.
- **C-8:** Composed identifier values are append-only — may add a position, never reorder or remove one.
- **C-9:** Capability-gated, with FR-30 needing its own capability separate from thread-management.
- **C-10:** New endpoints must appear in the generated OpenAPI specification; regeneration is part of the work.
- **C-11:** Errors distinguishable — Locked vs permission-denied vs not-found. FR-5 depends on it.
- **C-12:** Federation degrades, never lies — no controls that silently do nothing.

**§8 Non-Goals (8):** not a task tracker; not a moderated taxonomy; not private organisation; not automatic lifecycle; not federation; not mobile clients; not a thread permission system; not threads-of-threads.

**§9.2 Out of Scope for MVP (8 items),** of which three are *decisions* rather than deferrals: bots changing Thread State (decided), migration-time reclassification (declined by requester), and personal featuring/tags/ordering (non-goal).

**§9.3 Known Limitation:** day one after upgrade, the Directory shows all existing Threads as Ongoing. Both offered mitigations were declined by the requester.

**§10 Acceptance Criteria:** AC-1 … AC-7 demonstrated on a seeded fixture (1,000 Threads / 200 participants / a year of activity), plus AC-W1 and AC-W2 as watch-only items. **AC-2 carries a hard sequencing obligation: the baseline must be captured on the current nested list before the first Directory change ships, or the criterion cannot fail.**

**§12 Risks:** 10 named risks with mitigations, led by FR-30's client regression, Locked leaking through an unguarded path, and cache staleness.

**§13 Open Questions:** all 10 closed — 3 by architecture (AD-6, AD-17, AD-5), 7 by the requester. Resolutions recorded rather than deleted.

**§16 Assumptions Index:** 23 entries, 3 struck through as resolved. **20 assumptions remain live**, of which the PRD itself names two as highest-leverage: item 1 (personas inferred, no customer research) and item 23 (a seeded fixture is representative enough to accept against).

### 2.4 PRD Completeness Assessment

**Verdict: PASS — unusually strong.** This PRD exceeds the bar for epic and story derivation.

Strengths, stated concretely because they are what makes the coverage check in §3 meaningful:
- Every FR carries explicit "Consequences (testable)" — the PRD hands stories their acceptance criteria rather than leaving them to be invented. 43 FRs carry roughly 190 testable consequences.
- Vocabulary is fixed in §3 and enforced as a discipline rule, so terminology drift between PRD, architecture and epics is detectable rather than a matter of taste.
- Traceability is bidirectional: §14 maps all ten issue #22 items to FRs, and §16 indexes every assumption to its origin.
- Two places where the specification is deliberately *narrower* than the issue are stated in the document rather than hidden (§9.1 honesty note on §4.8; §14 row 8).
- Adversarial review history is real, not decorative: three factual claims about the codebase were corrected, one requirement removed for a non-existent premise, and FR-5's path count went from six to nine as a direct result.

Weaknesses found, none of them blocking:
- **PRD-D1 (Cosmetic, non-blocking):** §9.3 says "All forty-two requirements can pass" while the document defines 43 FRs. Stale text from before FR-43 was added in r3. Also §0 and §16 are consistent at 43; only this sentence is stale.
- **PRD-D2 (Non-blocking, flagged for the coverage check):** The §5 NFRs are unnumbered in the source. This makes NFR-to-story traceability weaker than FR-to-story traceability by construction — a story cannot cite "NFR-4" because the PRD does not name it that. Assessed in §3 whether epics carry the NFR obligations anyway.
- **PRD-D3 (Live risk, correctly disclosed):** 20 assumptions remain unconfirmed, and the PRD names item 1 (personas, driving all five user journeys and therefore the whole document) as the highest-leverage. Every journey, and thus every "Realises UJ-n" trace, rests on inferred personas with no customer research behind them. The PRD discloses this rather than concealing it, but it does not stop being true.
- **PRD-D4 (No UX artefact):** §4.3, §4.6 and §4.9 specify interface behaviour in prose with no wireframe, no layout, and no component inventory. There is no UX document in the project (§1). For the Directory in particular — a new first-class surface with filtering, search, incremental loading and a six-field row — this leaves layout decisions to implementation.

---

## 3. Epic Coverage Validation

**Source:** `epics.md` (2,111 lines, 4 epics, 27 stories, 277 acceptance criteria). Read complete.

**Method note.** The epics document carries its own FR Coverage Map at §"FR Coverage Map", claiming 43/43. That claim was **not taken at face value.** Every FR was traced from the map to the specific story and acceptance criteria that implement it, and every PRD "Consequences (testable)" bullet was checked for a corresponding AC. The map is accurate; the gaps found below are in consequences the map does not track.

### 3.1 Coverage Matrix — verified against stories, not against the map

| FR | PRD requirement (short) | Epic coverage — verified location | Status |
| --- | --- | --- | --- |
| FR-1 | Thread State exists, defaults Ongoing | E1 · Story 1.2 · AC1–AC6 | ✓ Covered |
| FR-2 | Thread Manager closes a Thread | E1 · Story 1.4 · AC1, AC7, AC8, AC14 | ✓ Covered |
| FR-3 | Posting into Closed returns to Ongoing | E1 · Story 1.5 · AC1–AC7 | ✓ Covered |
| FR-4 | Thread Manager locks from Ongoing or Closed | E1 · Story 1.4 · AC2, AC5 | ✓ Covered |
| FR-5 | Locked refuses every write | E1 · Story 1.6 · AC1–AC13 (nine paths) **+** Story 1.7 · AC1–AC7 (edits/deletes/pins/reactions) | ✓ Covered |
| FR-6 | Manager reopens Closed directly | E1 · Story 1.4 · AC3 | ✓ Covered |
| FR-7 | Manager reopens Locked | E1 · Story 1.4 · AC4 | ✓ Covered |
| FR-8 | State visible on every surface | E1 · Story 1.8 · AC1–AC10 | ✓ Covered |
| FR-9 | Authority restricted to Thread Managers | E1 · Story 1.3 · AC1–AC5, AC8–AC10 | ✓ Covered |
| FR-10 | Controls absent, not disabled | E1 · Story 1.3 · AC7 **+** Story 1.4 · AC17 | ⚠ Covered, placement unspecified — see G2 |
| FR-11 | Renaming authority unchanged | E1 · Story 1.3 · AC4 | ✓ Covered |
| FR-43 | Optional free-text lock reason | E1 · Story 1.4 · AC9–AC13 **+** Story 1.8 · AC2 | ✓ Covered |
| FR-36 | Notification lands in the Thread | E1 · Story 1.9 · AC1–AC5 | ✓ Covered |
| FR-12 | Directory is a first-class peer | E2 · Story 2.4 · AC1, AC2, AC5, AC6, AC7 | ✓ Covered |
| FR-13 | Featured first, then activity | E2 · Story 2.1 · AC6, AC7 | ✓ Covered |
| FR-14 | State filter, Featured survive it | E2 · Story 2.3 · AC2–AC6 | ✓ Covered |
| FR-15 | Tag filter | E2 · Story 2.8 · AC6–AC10 | ✓ Covered |
| FR-16 | Row carries six fields | E2 · Story 2.4 · AC8–AC11 (5 fields) **+** E3 · Story 3.6 · AC9 (unread field) | ✓ Covered — split declared |
| FR-17 | Incremental loading | E2 · Story 2.2 · AC5, AC6 **+** Story 2.3 · AC8 | ✓ Covered |
| FR-18 | Title search, diacritic-insensitive | E2 · Story 2.6 · AC1–AC7, AC13 | ✓ Covered |
| FR-19 | Always-available message-search handoff | E2 · Story 2.6 · AC8–AC10 | ✓ Covered |
| FR-20 | Followed Thread List searchable | E2 · Story 2.6 · AC11, AC12 | ✓ Covered |
| FR-21 | Feature and unfeature | E2 · Story 2.1 · AC2–AC5, AC11 | ✓ Covered |
| FR-22 | Featured count bounded | E2 · Story 2.1 · AC8, AC9 | ✓ Covered |
| FR-23 | Add/remove tags, both caps | E2 · Story 2.7 · AC4–AC9 | ✓ Covered |
| FR-24 | Colour fixed within a Conversation | E2 · Story 2.7 · AC10–AC14 | ✓ Covered |
| FR-25 | Manager recolours a tag | E2 · Story 2.8 · AC1–AC5 | ✓ Covered |
| FR-26 | Input surfaces existing tags first | E2 · Story 2.7 · AC15–AC17 | ✓ Covered |
| FR-38 | Top-bar control | E2 · Story 2.4 · AC2–AC4 **+** E3 · Story 3.6 · AC10 (unread indication) | ✓ Covered — split declared |
| FR-39 | Thread header links to Directory | E2 · Story 2.5 · AC5 | ✓ Covered |
| FR-40 | Thread Root Message links to Directory | E2 · Story 2.5 · AC6 | ✓ Covered |
| FR-41 | Addresses linkable and restorable | E2 · Story 2.5 · AC7–AC10 | ✓ Covered |
| FR-42 | Addresses resolve in every surface | E2 · Story 2.5 · AC2–AC4 | ✓ Covered |
| FR-27 | Thread Unread Count per participant | E3 · Story 3.2 · AC1–AC10 | ✓ Covered |
| FR-28 | Unread independent per Thread | E3 · Story 3.3 · AC9–AC12 **+** Story 3.1 · AC5 | ✓ Covered |
| FR-29 | Mark one read, mark all read | E3 · Story 3.4 · AC1–AC7 | ✓ Covered |
| FR-30 | Conversation unread counts main chat only | E3 · Story 3.3 · AC1–AC8, AC13, AC14 | ✓ Covered |
| FR-31 | Conversation reports unread Threads | E3 · Story 3.5 · AC1–AC9 | ✓ Covered |
| FR-32 | Unread in both list surfaces | E3 · Story 3.6 · AC1–AC12 | ✓ Covered |
| FR-33 | Nine subjects name the Thread | E4 · Story 4.1 · AC1–AC6 | ✓ Covered |
| FR-34 | Lifecycle notifies followers | E4 · Story 4.2 · AC1–AC9 | ✓ Covered |
| FR-35 | Push payloads carry identifiers | E4 · Story 4.3 · AC1–AC7 | ✓ Covered |
| FR-37 | Push text within size budget | E4 · Story 4.3 · AC8–AC12 | ✓ Covered |

**Two stories carry no FR and are architecture-driven rather than requirement-driven** — legitimate, and both are traced:
- **Story 1.1** — the four prerequisites of AD-1, AD-9, AD-17 and PRD §10's fixture.
- **Story 1.10** — AD-5, which resolved PRD open question 8.
- **Story 3.1** — AD-6 and AD-7, the enabler Stories 3.2–3.6 all stand on.

**FRs in epics but not in the PRD:** none. No invented requirement.

### 3.2 Coverage Statistics

- **Total PRD FRs: 43**
- **FRs covered in epics: 43**
- **Coverage percentage: 100%** — verified story-by-story, not merely asserted by the coverage map.
- Distribution: Epic 1 — 13 FRs / 10 stories / 96 ACs · Epic 2 — 20 FRs / 8 stories / 96 ACs · Epic 3 — 6 FRs / 6 stories / 58 ACs · Epic 4 — 4 FRs / 3 stories / 27 ACs.

### 3.3 Missing Requirements

**No FR is missing.** What is missing sits one level down — in PRD consequences and NFR obligations that the FR-level coverage map does not track. Six are material and are listed in §5 as findings G1–G6.

### 3.4 Non-Functional and Acceptance-Criteria Coverage

| Obligation | Claimed home | Verified in stories | Status |
| --- | --- | --- | --- |
| NFR-1 Scale | Epic 2 query design, retested Epic 3 | 2.2 AC4, 2.4 AC13, 3.2 AC7/AC9 | ✓ |
| NFR-2 No unbounded work | Epic 2, Epic 3 | 2.2 AC4, 3.2 AC7 | ✓ |
| NFR-3 Bounded per-participant state | Epic 3 alone | 3.1 AC3, AC4, AC5 | ✓ |
| NFR-4 Cache correctness | Epic 1, inherited by all | 1.4 AC15/AC16, 1.5 AC6, 1.10 AC4, 2.1 AC10, 3.4 AC6 | ⚠ **Gap on tag mutations — G3** |
| NFR-5 Concurrency | Epic 1, "enforced by every subsequent epic" | 1.4 AC14 **only** | ⚠ **Asserted, not tested for featuring or tags — G4** |
| NFR-6 Migration safety | All four migrations | 1.2 AC1/AC2, 1.4 AC9, 2.1 AC1, 2.6 AC2, 2.7 AC1, 3.1 AC1/AC2 | ⚠ **Mechanism covered, outcome untested — G5** |
| NFR-7 Capability degradation | Epic 1 and Epic 3 | 1.2 AC6, 3.3 AC1, AC14 | ✓ |
| NFR-8/9/10 A11y, theme, l10n | "every user-visible story" | 1.8 AC6/AC7, 2.4 AC4/AC12, 2.7 AC14, 3.6 AC10/AC12, 4.1 AC6, 4.3 AC11 | ⚠ **Claim is false for nine user-visible stories — G8** |
| NFR-11 Real-time consistency | Epic 1 relay, Epic 2 recolour | 1.8 AC8, 2.8 AC3 | ✓ |
| **PRD AC-1** cluttered room → short list | — | **nowhere** | ❌ **G1** |
| PRD AC-2 Directory not slower | Story 1.1 baseline + re-measures | 1.1 AC5, 2.2 AC8, 2.4 AC13, 3.2 AC9 | ✓ Sequencing obligation correctly enforced |
| PRD AC-3 returning participant | Epic 3 | 3.6 AC11 | ✓ |
| PRD AC-4 one room reads as two | Epic 2 | 2.8 AC11 | ✓ |
| PRD AC-5 state grows with engagement | Epic 3 | 1.1 AC6, 3.1 AC4 | ✓ |
| PRD AC-6 unread split consistent | Epic 3 | 3.5 AC9 | ✓ |
| **PRD AC-7** requester recognises problem solved | — | **nowhere** | ❌ **G7** |
| PRD AC-W1 badge regression watch | Epic 3 | 3.3 AC14 | ✓ |
| PRD AC-W2 moderation replacing conversation | — | nowhere (post-release watch item) | ⚠ Acceptable, but see G7 |
| AD-18 Federation degrades | Epics 2 and 3 | 1.2 AC6, 2.1 AC11, 2.4 AC14 | ⚠ **Per-Thread unread affordance missing — G6** |

---

## 4. UX Alignment Assessment

### 4.1 UX Document Status

**Not Found.** No `*ux*.md` document, no `ux-designs/` folder, no sharded UX index anywhere under `_bmad-output/`. The `bmad-ux` workflow has not been run for this feature.

### 4.2 Is UX implied?

**Yes, heavily.** This is a user-facing web feature that introduces an entirely new interface surface. UI-bearing requirements: FR-8, FR-10, FR-12 … FR-19, FR-21 … FR-26, FR-32, FR-38 … FR-41 — **26 of 43 FRs carry visual or interaction obligations.** The Thread Directory alone is a new destination with a state filter, a tag filter, live-as-you-type search, an empty state with a handoff control, incremental loading, and a six-field row that must truncate gracefully under five tags. None of it has a wireframe, a layout, or a component inventory.

### 4.3 Alignment Issues

**No misalignment was found, because there is nothing to misalign.** This is the important distinction, and it is favourable rather than alarming:

- The epics document **records the absence deliberately** in its own §"UX Design Requirements" section rather than papering over it, and enumerates which FRs carry the UI burden in the contract's absence.
- It states the fallback explicitly: "UI-affecting requirements are therefore carried entirely by the PRD and the architecture", and names the nine specific FRs.
- Architecture supports the UI obligations it can: AD-10 makes the tag palette a bounded index precisely so NFR-8/NFR-9 contrast is achievable by construction rather than by review; AD-15 makes one store the single client owner so three surfaces rendering the same Thread cannot diverge; AD-16 makes addresses query parameters so both router factories resolve them.
- It carries a concrete correction that a UX document would otherwise have got wrong: `ThreadHeader.vue` is filed under `src/components/RightSidebar/Threads/` but renders in `TopBar.vue:54` and `ChatView.vue:27`, never in the sidebar — "do not infer placement from the directory layout."

### 4.4 Warnings

- ⚠ **W-1 (Moderate): Layout and interaction design are delegated to implementation for four stories** — 2.4 (Directory surface), 2.6 (search field, empty state, handoff control), 2.7 (tag input and suggestion affordance), 3.6 (unread indicators and mention distinction). Each has behavioural ACs; none has a visual contract. The practical risk is rework after the first demo, not incorrectness.
- ⚠ **W-2 (Moderate): The lifecycle-control affordance has no specified location on any surface** — this is finding **G2** below, and it is the one place where the missing UX contract has produced a real functional hole rather than only a stylistic one.
- ℹ **W-3 (Informational): Architecture supports every UX obligation the PRD states.** No case was found where a UI requirement lacks architectural support. The gap is design, not feasibility.

---

## 5. Epic Quality Review

Validated against `bmad-create-epics-and-stories` standards: user value, epic independence, story independence, forward dependencies, sizing, AC quality, database-creation timing, and starter-template handling.

### 5.1 Compliance Checklist

| Check | Epic 1 | Epic 2 | Epic 3 | Epic 4 |
| --- | --- | --- | --- | --- |
| Epic delivers user value | ✓ | ✓ | ✓ | ✓ (honestly qualified) |
| Epic functions independently of later epics | ✓ | ✓ | ✓ | ✓ |
| Stories appropriately sized | ⚠ two oversized | ⚠ one oversized | ✓ | ✓ |
| No hidden forward dependencies | ✓ | ✓ (all declared) | ✓ (one declared) | ✓ |
| Tables created when first needed | ✓ | ✓ | ✓ | n/a |
| Clear, testable acceptance criteria | ✓ | ✓ | ✓ | ✓ |
| Traceability to FRs maintained | ✓ | ✓ | ✓ | ✓ |

**Structural quality is high.** Specifically:

- **All 277 ACs are well-formed Given/When/Then** — 277 `Given`, 277 `When`, 277 `Then`, zero malformed. No vague criterion like "user can login" appears anywhere; ACs cite file paths, line numbers, column names and constant names.
- **Migrations are distributed to the story that first needs them** — state (1.2), lock reason (1.4), featured (2.1), normalised name (2.6), tag tables (2.7), thread-attendee columns and baseline (3.1). This is the *correct* pattern, not the "Epic 1 Story 1 creates all tables" antipattern.
- **Starter template correctly handled** — the architecture declares "Starter template: none. This is a brownfield fork", and the epics record it verbatim with the commit it forks from. Brownfield expectations are met: Story 1.1 covers integration with existing systems, and six migration/compatibility obligations are distributed across stories.
- **Epic ordering is load-bearing and justified in prose**, not asserted: Epic 2's keyset cursor is built on the tuple `(featured, last_activity DESC, id DESC)`, so featuring must precede paging — that is why Story 2.1 precedes 2.2, and the document says so.
- **Epic 3's separation from Epic 2 is argued rather than assumed** — the overlap in `ThreadService`, `ThreadController` and `useChatExtrasStore` is acknowledged and the counter-argument given: Epic 3 is the only epic that breaks an API contract, and folding it in would bind an additive Directory to a breaking change behind one flag that could no longer tell a client which of the two it is talking to.

### 5.2 🔴 Critical Violations

**None.** No technical epic without user value, no hidden forward dependency, no epic-sized story that cannot be completed, no circular dependency.

### 5.3 🟠 Major Issues

**G1 — PRD AC-1 has no home in any epic or story.**
`AC-1` is the criterion that validates §9.3's entire accepted day-one limitation: "reducing the default-filter list to twenty Threads or fewer takes only row-menu interactions — no navigation away from the Directory, no bulk tooling, no per-Thread page." PRD §9.3 rests on it ("AC-1 demonstrates that the manual path is walkable at the target scale"), and both deferred mitigations — migration-time reclassification and bulk close — are gated on it failing. Every other seeded-fixture criterion is cited in a story AC: AC-2 four times, AC-3 once, AC-4 once, AC-5 twice, AC-6 once, AC-W1 once. `AC-1` appears **zero** times in `epics.md`.
**Impact:** the only demonstration that the manual close path is walkable at 1,000 Threads is not scheduled. If it is not walkable, that is discovered by a customer rather than by the fixture built specifically to discover it.
**Recommendation:** add an AC to Story 2.3 (state filter, where the shortening list is observable) or a final Epic 2 acceptance AC, citing *(AC-1)*.

**G2 — No acceptance criterion places the lifecycle controls on any surface.**
Story 1.4 AC17 says the state-change controls "are absent, not disabled" for a non-manager — it never says **where they are** for a manager. Story 2.4's Directory-row AC (AC8) enumerates display fields only: title, state, tags, reply count, last activity. Story 1.8 AC2 says the thread header *shows* state; it does not say the header offers a menu. Searching all 277 ACs, "without opening" attaches only to **mark-read** (Story 3.4 AC1) — never to close or lock.
Meanwhile the PRD requires both placements in its journeys: **UJ-1** — "she opens the row's menu and picks **Close**"; **UJ-2** — "He opens the thread, uses the thread header menu, and picks **Close**". And PRD AC-1 requires closing from Directory rows *without opening the Thread*.
**Impact:** a build satisfying every existing AC could ship lifecycle controls only on a per-Thread page and pass. That would satisfy FR-2, FR-4, FR-6, FR-7 and FR-10 as written, fail UJ-1 and UJ-2, and make PRD AC-1 unreachable. G1 and G2 are the same hole seen from two directions.
**Recommendation:** add one AC to Story 2.4 placing a per-row lifecycle menu (close, lock, reopen, feature, tag) gated on Story 1.3's manager getter, and one AC to Story 1.8 or 2.5 placing the same menu on the thread header.

**G3 — No cache-invalidation acceptance criterion on tag mutations.**
NFR-4 is named in the PRD as "the highest-risk correctness surface in the work", and AD-1 requires every mutator to end by removing `thread/{roomId}/{threadId}`. Five stories carry an explicit invalidation AC — 1.4 AC15, 1.5 AC6, 1.10 AC4, 2.1 AC10, 3.4 AC6. **Stories 2.7 and 2.8 carry none**, and they are the two tag-mutating stories.
This is not an oversight of symmetry; it is structurally ambiguous. AD-1 and AD-10 make `ThreadTagService` a **separate aggregate** that "does not write thread rows", while `ThreadService` "is the sole writer … of the Thread cache". Thread Tags render on the cached `TalkThreadInfo`. So when a tag is added, removed or recoloured, **no artefact says who invalidates the Thread cache entry**, and the aggregate rule appears to forbid the service doing the writing from doing it.
**Impact:** the exact failure NFR-4 exists to prevent — a tag change that succeeds for the actor and is invisible to everyone else for up to 900 seconds. Story 2.8 AC3 requires the recolour to reach others "without a reload" via the relay set, which would mask the stale cache in the live case and expose it on the next page load.
**Recommendation:** add an invalidation AC to Stories 2.7 and 2.8 in the multi-actor form of 2.1 AC10, and state in whichever artefact owns it whether `ThreadTagService` calls a `ThreadService` invalidation method or emits an event the aggregate owner listens to.

### 5.4 🟡 Minor Concerns

**G4 — NFR-5 (last-write-wins) is tested for state only.**
NFR-5 says it "applies to state, featuring and tags alike", and the epics' own non-functional coverage paragraph claims it is "enforced by every subsequent epic's mutations". The only concurrency AC in the document is Story 1.4 AC14, which exercises two managers changing **state**. No AC exercises concurrent featuring (Story 2.1) or concurrent tagging (Stories 2.7, 2.8). Story 2.1 AC10 covers the cache for featuring, not the concurrency.
**Recommendation:** add a concurrency AC to Story 2.1 and one to Story 2.7 in the shape of 1.4 AC14 — client replaces its copy with the response, never with what it sent.

**G5 — NFR-6's outcome is untested; only its mechanism is.**
All six migration ACs assert additive / defaulted / safe-to-run-twice / no-backfill — the *mechanism*. None asserts the *outcome* NFR-6 states: "applies to a Conversation with a large Thread history **without a maintenance window**". PRD §12 is explicit that this "needs a rehearsal against realistic data volume, not a fresh install." Story 1.1 AC4 builds exactly the fixture such a rehearsal needs; nothing then rehearses on it.
**Recommendation:** add an AC to Story 3.1 — the largest and riskiest migration, reinstating dropped columns plus a new baseline column — requiring the migration to be timed against the seeded fixture and its duration recorded.

**G6 — Federation degradation is absent from Epic 3.**
AD-18 names four affordance families the client must hide on a federated Conversation: "state, featuring, tag **and per-Thread-unread**". Stories 2.1 AC11 and 2.4 AC14 cover the first three. No Epic 3 story carries a federation AC, so per-Thread unread counts and the thread-unread indication are unspecified on a federated Conversation — where, by PRD §2.2, threads do not work at all.
**Recommendation:** add a `features-local` hiding AC to Story 3.6.

**G7 — PRD AC-7 and AC-W2 have no recorded owner.**
AC-7 — the requester being walked through AC-1 and AC-3 on the fixture before release — is described in the PRD as "the only criterion that catches getting every requirement right and the product wrong". It is not a story, and it is not recorded as a release gate anywhere. AC-W2 is legitimately post-release and watch-only, but AC-W1 *is* homed (Story 3.3 AC14), so the asymmetry is unexplained rather than deliberate.
**Recommendation:** record AC-7 as an explicit release gate in the Epic 4 closing notes or in the sprint plan, not as a story.

**G8 — The claim that NFR-8/9/10 are "acceptance criteria on every user-visible story" is false.**
Present in: 1.8, 2.4, 2.7, 3.6, 4.1, 4.3. **Absent from nine user-visible stories:** 1.4 (state controls, lock-reason input), 1.9, 2.1 (featuring control), 2.3 (filter UI), 2.5 (entry points), 2.6 (search field, empty state), 2.8 (tag filter UI), 3.4 (mark-read controls), 3.5 (conversation-list indication).
**Recommendation:** either add the criteria to those nine, or narrow the claim to the stories that actually carry it. Leaving a false blanket claim is worse than either.

**G9 — Story sizing: three stories are oversized.**
Story 1.4 (18 ACs) spans four state transitions, FR-43's reason with its own migration, six-registry system-message registration, concurrency and cache. Story 2.7 (18 ACs) spans a two-table migration, a new service, tag CRUD, two caps, palette colour binding and input suggestion. Story 2.4 (14 ACs) spans the surface, the top-bar control, Shared-items removal, row rendering and responsive behaviour. Median across the document is 10.
**Recommendation:** consider splitting 1.4 into transitions-plus-registration and lock-reason; 2.7 into tag storage-and-CRUD and colour-and-suggestion. Not blocking — the ACs are individually crisp — but these are the three most likely to overrun a sprint slot.

**G10 — Three stories use an engineer persona rather than a user persona.**
Stories 1.1, 1.10 and 3.1 are "As a Clavis engineer". By the letter of the standard these are technical stories without user value. All three are justified — 1.1 carries four architecture-mandated prerequisites, 1.10 implements AD-5's resolution of open question 8, and 3.1 is the enabler Stories 3.2–3.6 stand on — and a brownfield fork legitimately needs them. Recorded as a deliberate, defensible deviation rather than a defect, so a later reader does not mistake it for an oversight.

**G11 — Declared forward references (3), all acceptable.**
Story 2.4 → Story 3.6 (FR-16's sixth field, FR-38's unread indication); Story 3.3 AC12 → Story 3.4 (the second path to zero); Story 1.4 → Stories 1.6/1.7 (the epic gate). Every one is stated inline in the document with the degradation named. None is a hidden dependency, and in each case the earlier story ships working without the later one. The Story 1.4 gate is the strongest of the three: it states plainly that the story "ships a Locked state that does not yet refuse anything" and that "the epic does not ship without both" 1.6 and 1.7.

**G12 — Cross-artefact drift (documentation only).**
- PRD §9.3 says "All forty-two requirements can pass" — the PRD defines **43**. Stale text from before FR-43 was added in r3; §0 and §16 are correct.
- `ARCHITECTURE-SPINE.md` AD-14's heading reads "registered in all **five** places" while its own rule body and the epics correctly enumerate **six**. The epics use six, so nothing downstream is wrong — but the heading will mislead whoever reads only headings.
- `traceability.md` still assigns FR-36 to CAP-11 with the rationale "it sits under CAP-11 so the payload change cannot silently break it", while `epics.md` deliberately moved FR-36 to Epic 1 Story 1.9 for that same reason. Substance agrees; the join table no longer reflects where the work lives.

---

## 6. Summary and Recommendations

### 6.1 Overall Readiness Status

# ⚠ NEEDS WORK — targeted, not structural

Read that verdict precisely, because "NEEDS WORK" overstates it if taken alone. **The planning artefacts are in the top decile of what this workflow normally assesses.** All 43 functional requirements trace to a named story and specific acceptance criteria, verified individually rather than accepted from the coverage map. All 277 acceptance criteria are well-formed Given/When/Then. Migrations are distributed to the story that first needs them rather than front-loaded. Epic ordering is argued from a technical constraint rather than asserted. Two epics openly state that they deliver less than the originating issue asked for, rather than concealing it. No critical violation was found in any category this workflow checks.

What "NEEDS WORK" refers to is **twelve findings, three of them Major, all of them fixable by editing `epics.md` — none requiring re-planning, re-architecture or a return to the PRD.** Estimated effort: two to three hours.

**Epic 1 Stories 1.1 through 1.3 are unblocked and can start today.** None of the twelve findings touches them.

### 6.2 Critical Issues Requiring Immediate Action

There are no Critical (🔴) findings. The three Major (🟠) findings below should be fixed before the affected stories are cut into story files:

1. **G2 — no AC places the lifecycle controls on any surface.** Highest-value finding in this assessment. A build satisfying every existing AC could ship Close and Lock only on a per-Thread page, pass FR-2/FR-4/FR-6/FR-7/FR-10 as written, and still fail UJ-1, UJ-2 and PRD AC-1. Affects Stories 1.4, 1.8, 2.4.
2. **G3 — no cache-invalidation AC on tag mutations, and the aggregate rules make ownership ambiguous.** `ThreadTagService` is forbidden from writing thread rows; `ThreadService` solely owns the Thread cache; tags render on the cached representation. No artefact says who invalidates. This is precisely the NFR-4 failure the PRD calls the highest-risk correctness surface. Affects Stories 2.7, 2.8.
3. **G1 — PRD AC-1 is unscheduled.** The demonstration that the manual close path is walkable at 1,000 Threads — the evidence §9.3's entire accepted limitation rests on — is not in any story. Every other fixture criterion is. Affects Epic 2.

### 6.3 Recommended Next Steps

1. **Fix G2** — add one AC to Story 2.4 placing a per-row lifecycle menu gated on Story 1.3's manager getter, and one to Story 1.8 or 2.5 placing the same menu on the thread header. *(~30 min)*
2. **Fix G3** — add multi-actor invalidation ACs to Stories 2.7 and 2.8 in the shape of 2.1 AC10, and resolve in the architecture or the epic notes whether `ThreadTagService` calls a `ThreadService` invalidation method or emits an event the aggregate owner consumes. *(~30 min, plus one architecture sentence)*
3. **Fix G1** — add an *(AC-1)*-citing acceptance criterion to Story 2.3 or as an Epic 2 closing criterion. *(~10 min)*
4. **Fix G4, G5, G6** — one concurrency AC each on Stories 2.1 and 2.7; one migration-rehearsal AC on Story 3.1 against the Story 1.1 fixture; one `features-local` hiding AC on Story 3.6. *(~30 min)*
5. **Fix G8** — either add NFR-8/9/10 criteria to the nine user-visible stories that lack them, or narrow the blanket claim in the non-functional coverage paragraph to match reality. *(~20 min)*
6. **Record G7** — AC-7 (requester walkthrough before release) as an explicit release gate in the sprint plan, not as a story.
7. **Correct G12's three documentation drifts** — PRD §9.3's "forty-two", AD-14's "five places" heading, and `traceability.md`'s FR-36 row. *(~10 min)*
8. **Decide on G9** — split Stories 1.4 and 2.7, or accept them as oversized with eyes open. A judgement call, not a defect.
9. **Accept G10 and G11 as recorded deviations** — no action; they are documented here so a later reader does not re-litigate them.
10. **Optional: run `bmad-ux`** for the Thread Directory before Story 2.4 is implemented. Not blocking — the epics record the absence deliberately and architecture supports every UI obligation — but Stories 2.4, 2.6, 2.7 and 3.6 currently delegate layout to implementation, and that is where rework after the first demo would come from.

### 6.4 Findings by Severity

| Severity | Count | IDs |
| --- | --- | --- |
| 🔴 Critical | 0 | — |
| 🟠 Major | 3 | G1, G2, G3 |
| 🟡 Minor | 9 | G4, G5, G6, G7, G8, G9, G10, G11, G12 |
| ⚠ Warnings (UX) | 3 | W-1, W-2 (= G2), W-3 |
| ℹ PRD observations | 4 | PRD-D1 … PRD-D4 |

### 6.5 Final Note

This assessment identified **12 findings across 4 categories** (coverage consequences, non-functional obligations, epic quality, cross-artefact drift), plus 3 UX warnings and 4 PRD observations. Zero are Critical. Three are Major and share a single theme: **the artefacts are excellent at specifying what the server must do and weaker at specifying where the participant touches it** — G2 (control placement), G1 (the walkability demonstration) and W-1 (four stories delegating layout) are the same absence expressed three ways, and all three trace back to no `bmad-ux` contract having been produced.

The second theme is narrower and sharper: **cross-cutting obligations are enforced where they were introduced and asserted everywhere after.** NFR-4 is rigorously covered in Epic 1 and missing on tag mutations; NFR-5 is tested for state and asserted for featuring and tags; NFR-8/9/10 are claimed on every user-visible story and present on six of fifteen. The pattern is consistent and worth naming, because the fix is mechanical once seen.

Address G1, G2 and G3 before the affected stories are cut. Everything else can be folded in as those stories are written. **Epic 1 Stories 1.1–1.3 may begin now.**

---

**Assessed:** 2026-08-09 · **Assessor:** BMad Implementation Readiness workflow · **Artefacts:** PRD r3 (final), ARCHITECTURE-SPINE r3 (final), epics.md (4 epics / 27 stories / 277 ACs), SPEC kernel + traceability

---

## 7. Remediation Log — 2026-08-09

The three Major findings were fixed in `epics.md` after this assessment was written. This section records what changed so the report above stays readable as the assessment it was, rather than being quietly rewritten.

### G2 — lifecycle control placement · **RESOLVED**

Four new acceptance criteria place the controls, one clarifying note explains why placement is acceptance rather than styling:

| Location | Added | Covers |
| --- | --- | --- |
| Story 1.4 · **AC17** (new) | Thread header menu carrying all four transitions, with the lock action offering AC10's reason field and AC11's bound | UJ-2 |
| Story 1.4 · AC18, AC19 | renumbered from the previous AC17, AC18 — no content change | — |
| Story 1.4 · note | states that FR-2/FR-4/FR-6/FR-7 specify transitions without specifying the affordance, so an implementation offering them only on a per-Thread page passes every other criterion and still fails UJ-2 | — |
| Story 1.8 · **AC11, AC12** (new) | row menu on today's threads list; closing from the row without opening the Thread, row updates in place, Thread leaves an Ongoing-filtered list as it goes | UJ-1, FR-10 |
| Story 2.4 · **AC15** (new) | Directory row inherits the same menu, plus Story 2.1's feature/unfeature, gated on Story 1.3's store getter, absent not disabled | UJ-1, FR-10 |

Story 2.7's tag action is recorded as joining that same menu rather than adding a second affordance.

### G1 — PRD AC-1 unscheduled · **RESOLVED**

**Story 2.4 · AC16** (new) carries the demonstration: from the seeded Conversation with every Thread Ongoing, reducing the default-filter list to twenty or fewer using **only row-menu interactions**, with the walk timed and the figure recorded. The AC states in-line why it exists — it is the evidence PRD §9.3 rests on when it accepts the day-one limitation and declines both bulk close and migration-time reclassification.

`AC-1` now appears three times in `epics.md`: once as a tagged criterion citation and twice in prose. It previously appeared zero times.

### G3 — tag cache invalidation and ownership · **RESOLVED**

The ambiguity is closed by naming the seam, not just by adding criteria. **Decision recorded: `ThreadTagService` calls an explicit invalidation method on `ThreadService`.** The cache keeps one owner per AD-1, and the tag aggregate never touches the `thread/{roomId}/{threadId}` key itself.

| Location | Added |
| --- | --- |
| Requirements Inventory · *Write seams and enforcement* | new binding bullet stating the seam, the fan-out on recolour, and why AD-1's aggregate separation would otherwise read as a licence to skip invalidation |
| Epic 2 · implementation notes | two bullets — the shared cache owner, and the Directory row carrying the management menu |
| Story 2.7 · **AC19, AC20** (new) | invalidation via the named `ThreadService` method, `remove()` never `set()`; multi-actor scenario |
| Story 2.8 · **AC12, AC13** (new) | recolour invalidates **every** Thread carrying the tag, bounded rather than a Conversation-wide sweep; asserted both after the relay is consumed **and** after a fresh page load, because the relay masks a missed invalidation in the live case |

### Post-remediation integrity check

| Check | Result |
| --- | --- |
| Stories | 27 (unchanged) |
| Acceptance criteria | **277 → 286** (+9) |
| Given/When/Then well-formed | 286 / 286 / 286 |
| AC numbering contiguous within every story | ✓ all 27 stories, no breaks |
| Cross-references resolve (1.4 AC10/AC11, 1.8 AC11, 2.4 AC15, 2.7 AC19) | ✓ all four |
| FR coverage | 43 / 43 unchanged — no FR added, moved or dropped |
| Story headings changed | none |
| `sprint-status.yaml` drift | none — all 27 story keys still match |

### Still open

`G4` … `G12` are unchanged and remain as written in §5.4. None blocks cutting story files; each is best folded in as its affected story is written. **G7** (PRD AC-7, the requester walkthrough) still needs recording as a release gate rather than a story.

**Revised status: READY to cut story files.** Epic 1 Stories 1.1–1.3 were already unblocked; Stories 1.4, 1.8, 2.4, 2.7 and 2.8 now are too.
