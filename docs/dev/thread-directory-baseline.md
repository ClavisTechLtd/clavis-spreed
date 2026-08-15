# Thread Directory pre-work baseline (Epic 1, Story 1.1)

This is the committed artefact required by Story 1.1 AC5 and AC6. It records
the state of the **current, unmodified** nested threads list — before any
story in Epic 1 changes that list, its row shape, or its query — so that
later stories (starting with Story 1.8, which adds a Thread State field to
the row) have something concrete to regress against, and so PRD AC-2's
response-time requirement is a criterion that can actually fail.

Per the architecture spine's operational envelope: "AC-2's pre-work
response-time baseline must be captured on it before the first Directory
change ships" — this file is that capture point.

## How to reproduce this baseline

1. Enable `debug` mode on the target instance (`config.php`:
   `'debug' => true`) — required by `talk:developer:seed-threads`'s
   `isEnabled()` gate, matching the existing `talk:developer:age-chat-messages`
   precedent.
2. Seed the fixture:
   ```
   php occ talk:developer:seed-threads --threads=1000 --participants=200
   ```
   This creates a new Conversation, prints its token, and populates it with
   1000 Threads (root messages posted by a round-robin of 200 synthetic
   participants) with `last_activity` deterministically spread across the
   preceding year. Re-running with `--token <token>` adds more Threads /
   participants to the same Conversation instead of creating a new one — see
   `lib/Command/Developer/SeedThreadsFixture.php`.
3. **AC5 — response-time baseline.** As one of the seeded participants,
   request the first page of the *current* nested threads listing (the
   existing `GET .../threads/recent` endpoint, unmodified by this story) some
   number of times (a sample size in the 20-50 range is a reasonable
   default) and record the wall-clock response time for each request.
   Compute the median and 95th percentile from the samples.
4. **AC6 — pre-read baseline.** Immediately after step 2 (before any
   participant has read anything), count the rows in `talk_thread_attendees`
   for the seeded Conversation's room id:
   ```sql
   SELECT COUNT(*) FROM oc_talk_thread_attendees WHERE room_id = <seeded room id>;
   ```
   and compare against `participants × Threads` for that Conversation. Per
   architecture decision AD-6, a `talk_thread_attendees` row is written only
   when a participant actually reads a Thread, so this count is expected to
   be at or near zero immediately after seeding — record the actual number
   regardless of whether it matches that expectation.
5. Commit the resulting numbers into the table below, in the same change (or
   a fast-follow, clearly flagged) — do not let this baseline drift out of
   sync with the fixture that produced it.

## Results

**Status: pending a real run.** This sandbox has no PHP interpreter, no
database, and no running Nextcloud instance (verified during Story 1.1's
implementation — see the story file's Dev Notes → "Environment constraints"
for the full explanation), so the tooling above exists and is ready to run,
but the actual measurement could not be executed here. The numbers below
are placeholders and **must not be read as real data**.

| Metric | Value | Notes |
| --- | --- | --- |
| Fixture size | 1000 Threads / 200 participants (target) | Produced by `talk:developer:seed-threads --threads=1000 --participants=200` |
| Sample size | _pending_ | Number of first-page requests sampled |
| Median response time | _pending_ | milliseconds |
| 95th percentile response time | _pending_ | milliseconds |
| `talk_thread_attendees` row count immediately after seeding | _pending_ | AC6 pre-read baseline |
| Participants × Threads | _pending_ (target 200 × 1000 = 200,000) | For comparison against the row count above |
| Measured on | _pending_ | Date, environment (CI job / local dev box), commit hash |

**Before Epic 2 begins:** re-run steps 2-4 above in an environment with PHP,
a configured database, and this seeding command available, and fill in the
table. Epic 2 (the Thread Directory epic) changes the list this baseline
describes, so the numbers must be captured against the *unmodified* list —
once Epic 2 lands, this baseline can no longer be captured for the first
time; it can only be reproduced from before that point.
