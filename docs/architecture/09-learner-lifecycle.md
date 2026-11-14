# 09 — Learner lifecycle: progression and campus transfer

A child may join at nursery and leave after Form 4. That is one learner, one identifier and one unbroken record. This document fixes how.

## 1. The promise

1. **One learner record** for the whole stay, across every division and campus of the institution.
2. **One identifier.** The Acadly Learner ID and the institution's student number are issued once and never change on promotion, on moving to another division, or on moving to another campus.
3. **No record is lost or rewritten.** Moves close one row and open the next. History is never edited.
4. **The words follow the learner.** The same person is shown as "Child", then "Pupil", then "Student", because vocabulary comes from the division of the current placement.

## 2. The model: identity, enrolment, placement

```
Learner            who the person is                      one per tenant, for life
└── Enrolment      the learner's stay at an institution   one per stay (admission → completion / leaving)
    └── Placement  where the learner is right now         one row per (year × division × campus × level × class) spell
```

| Table | Key columns | Changes when |
|---|---|---|
| `learners` | `id`, `acadly_learner_id`, `person_id` | Never (profile data may be corrected, with audit) |
| `learner_identifiers` | `learner_id`, `institution_id`, `kind`, `value` | Issued once per institution. **Institution-level, not division-level** |
| `enrolments` | `learner_id`, `institution_id`, `status`, `admitted_on`, `ended_on`, `end_reason` | The learner joins or leaves the institution |
| `placements` | `enrolment_id`, `academic_year_id`, `division_id`, `campus_id`, `level_id`, `class_id`, `starts_on`, `ends_on`, `entry_reason`, `exit_reason`, `caused_by` (journey step) | Any move |

`entry_reason`: `admission`, `promotion`, `repeat`, `division_progression`, `campus_transfer`, `class_change`, `re_entry`.

Rules:

- **A placement is never edited to move a learner.** The current one gets `ends_on` and `exit_reason`; a new one is opened. Both in one transaction.
- **Exactly one open placement per active enrolment.** Enforced by a partial unique index and an exclusion constraint on the date range.
- **Every fact points at the learner and the placement:** attendance events, marks, results, report cards, library progress. So a report can answer "by learner, for life" and "by campus, for the dates the learner was there".
- **The timeline is the record.** The learner page shows one continuous line: Nursery (Lilongwe) → Standard 1–8 (Lilongwe) → Form 1–2 (Blantyre) → Form 3–4 (Blantyre) → Completed.

## 3. The four moves

| Move | What changes | What stays | Mechanism |
|---|---|---|---|
| **Promotion / repeat** (same division, new year) | level, class, academic year | division, campus, identifiers | `learner.progression` journey |
| **Division progression** (ECD → Primary → Secondary) | division, level, class, vocabulary, the rules that apply | campus (unless also moved), identifiers, enrolment, guardians, all history | `learner.progression` journey, outcome `progressed` |
| **Campus transfer** | campus, class | division, level, identifiers, enrolment | `learner.campus_transfer` journey |
| **Class change** (same campus, same level) | class | everything else | A plain Action. Not a journey |

Leaving the school (`withdrawn`, `transferred_out`, `completed`) closes the enrolment through `learner.exit`. The identifiers are retired, never reused. If the learner returns, the same learner record gets a new enrolment with `entry_reason = re_entry` and keeps the same identifiers.

## 4. Progression

### 4.1 What the Profile Blueprint declares

```php
// in PRIMARY@2027.1
->progression(
    Progression::make()
        ->within(PromotionRule::ByDecision)                // or Automatic, or ByResults
        ->atFinalLevel(FinalLevel::ProgressOrComplete)     // ECD, Primary: may continue. Secondary, Tertiary: Complete
        ->intake(IntakeMode::ConfirmByReceivingDivision)   // or Automatic
)
->journey(JourneySlot::Progression, 'learner.progression@2027.1')
```

- The blueprint says *whether* a division can hand learners on. It names no other profile.
- **Which** division receives them is institution data: `divisions.next_division_id`, set by the institution administrator. The linter checks the receiving division's profile accepts intake.
- A school with only a primary division has no next division. Its final-level learners complete.

### 4.2 The year-end run

Progression is done for a whole division at once, then decided per learner.

```
progression_runs        division × academic year → target academic year.  draft → proposed → committed
progression_decisions   one per learner per run. Each is a journey subject
```

1. The academic officer opens a run for a division and year.
2. The system proposes an outcome per learner from the blueprint's rule.
3. The officer reviews exceptions, changes outcomes, picks target classes.
4. Commit performs the journey step for every learner. It is idempotent: a second commit changes nothing. Unique index: one decision per learner per academic year.

### 4.3 The journey

`learner.progression` — subject: a progression decision.

| State | |
|---|---|
| `proposed` → `decided` → `awaiting_intake` (only for division progression) → `effective` | `cancelled` |

Named outcomes of the **decide** step:

| Outcome | Effect on placement |
|---|---|
| `promoted` | Close current. Open next level, same division, target year |
| `repeated` | Close current. Open same level, target year, `entry_reason = repeat` |
| `progressed` | Close current. Hand to the next division (see below) |
| `completed` | Close current. Close the enrolment as completed. Record the completion |
| `left` | Close current. Start `learner.exit` |

Gates (facts, not permissions):

- the academic year is in its closing window;
- results for the final period are published, where the profile's rule needs them;
- the target level and class exist and have a seat;
- no open attendance correction or result correction for the learner.

**Division progression** has one extra step, **confirm intake**, performed by an officer of the receiving division (unless the blueprint says `Automatic`). On confirmation:

- a placement opens in the receiving division with `entry_reason = division_progression`;
- there is **no new admission application** and no new learner record;
- guardian relationships carry over unchanged;
- the receiving division's blueprint now governs the learner (attendance mode, assessment model, vocabulary);
- guardians receive the `enrolment.progressed` notice.

If the receiving division declines, the decision returns to `decided` for another outcome. Nothing is left half-moved.

## 5. Campus transfer

`learner.campus_transfer` — subject: a transfer request.

```
requested → approved_by_sending → accepted_by_receiving → effective
                 └→ declined            └→ declined              cancelled (before effective)
```

| Step | Actor | Notes |
|---|---|---|
| request | Officer at the sending campus, or a guardian through the portal (if the institution allows) | States target campus, effective date, reason |
| approve | Sending campus | |
| accept | Receiving campus | Picks the target class |
| take effect | System, on the effective date | Closes and opens the placements |

Gates:

- the receiving campus offers the learner's division and level (`division_campus` offering);
- the target class has a seat;
- the effective date is inside an open academic period and not in the past;
- no attendance session is open for the learner on the effective date at the sending campus.

Effects when it takes effect:

- close the placement at the sending campus (`exit_reason = campus_transfer`);
- open the placement at the receiving campus (`entry_reason = campus_transfer`);
- notices to guardians (party), to both campus desks, and to the new class teacher;
- audit event with both campuses.

**What happens to the data**

- Attendance, marks and results earned at the old campus stay attributed to the old campus through their placement. Campus reports stay correct for past dates.
- Marks entered so far in the current period travel with the learner as history; the receiving campus continues the period. The assessment module reads all placements of the enrolment for the period when it builds the result.
- Staff at the old campus stop seeing the learner's current data from the effective date. They keep what they recorded.
- Staff at the new campus see the learner's earlier history read-only, under the `learner.history.view` permission.

A transfer and a division progression can happen together (finish primary in Lilongwe, start Form 1 in Blantyre): the intake step of the progression simply names a different campus. One move, one new placement.

## 6. Access and scope during moves

- Guardians never lose access during a move. Their link is to the learner, not to a placement.
- A staff member's access to a learner is derived from the learner's **current** placement and the staff member's scope. History access is a separate permission.
- All four moves are Actions or journey steps. None is a direct edit of `placements`.

## 7. Tables added

| Table | Plane | Purpose |
|---|---|---|
| `placements` | State (rows closed, never rewritten) | The learner's timeline |
| `progression_runs` | State | Year-end batch per division |
| `progression_decisions` | State + journey | One decision per learner per year |
| `campus_transfer_requests` | State + journey | Transfer requests |
| `enrolment_completions` | Record (append-only) | Completion of a division or of the institution, with date and basis |

## 8. Notices added to the catalogue

`enrolment.promoted` · `enrolment.repeated` · `enrolment.progressed` · `enrolment.completed` · `transfer.requested` · `transfer.approved` · `transfer.declined` · `transfer.effective` · `progression.intake_waiting` (summons to the receiving division).

## 9. Acceptance checks

- A learner admitted to nursery and moved through primary to secondary has one `learners` row, one identifier of each kind, one enrolment and a gap-free chain of placements.
- A prior-year report card opens unchanged after any move.
- Committing a progression run twice creates no duplicate placements.
- A campus transfer to a campus that does not offer the division is blocked with a reason and a remedy.
- Attendance totals for the old campus are the same before and after the transfer takes effect.
- A guardian sees the full timeline and is told about every move.
