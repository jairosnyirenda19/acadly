# 11 — Assessment and the term grade

A teacher sets assessments during the term. The school decides whether the term grade comes from the end-of-term exam alone or from the term's work plus the exam. This document fixes the model.

## 1. Words

| Word | Meaning |
|---|---|
| **Assessment** | One piece of assessed work for one class and one subject: a test, an exam, an observation |
| **Assessment kind** | The type of assessment. A list the school owns, seeded by the division's Profile Blueprint |
| **Grading plan** | The recipe that turns a term's assessments into the term grade for a subject |
| **Part** | A weighted piece of a grading plan, for example "Continuous assessment 40%" |
| **Term grade** | The result of the plan. A projection; never typed by hand |

## 2. Assessment kinds

The kinds are data (`assessment_kinds`, per division), not an enum. Each kind has: key, label, short label, whether it is scheduled by the school, the default answer to "counts toward the term grade", the plan part it normally feeds, and the scale it uses.

Seeds by profile (a school may rename, add or retire kinds):

| Profile | Seeded kinds |
|---|---|
| Primary, Secondary | **Class test** · Weekly test · Mid-term test · End-of-term exam · Assignment · Practical |
| ECD | **Observation** · Milestone check · End-of-term progress review |
| Tertiary | Coursework · Continuous assessment test · Practical · Final exam |

- **"Class test"** is the name for the unscheduled test a teacher sets on any day (the "random" test). It is short, set by the teacher, and needs no approval to create.
- **ECD does not use marks.** Its kinds use a descriptive scale (for example *Emerging · Developing · Secure*) against learning areas, plus a short written note. The same tables hold it; only the scale and the screen differ. The profile binds `AssessmentSlot::Model = Observational`.
- The vocabulary follows the division: a nursery teacher never sees the word "exam".
- **ECD steps as designed:** three steps, drawn as strokes and always shown with their word: `/` Starting (tries it with a lot of help), `//` Growing (does it with a little help), `///` Can do it (does it alone, more than once). A caregiver may also record "Not seen". The steps are a seed; a school may rename them.
- **ECD learning areas (seed):** Talking and listening · Early numbers · Moving and doing · Playing with others · Making and pretending · Caring for self.
- **An observation is not a test of the whole room.** The caregiver records the children seen that day. No child is "missing a mark".
- **The end-of-term progress review** is one record per child: the step reached in each learning area, chosen by the caregiver with the term's observations shown in order, plus two written notes (what the family must know; what can help at home). Its journey: draft → checked by the head of nursery → shared with the family. The family sees steps and words only: no marks, no ranks, no other child.

## 3. The assessment

`assessments`: `id`, scope ids, `class_subject_id` (class × subject), `academic_period_id`, `kind_id`, `title`, `held_on`, `scale_id`, `max_mark` (null for descriptive scales), `counts` (boolean), `plan_part_key` (null when it does not count), `created_by`, journey state.

Journey `assessment.marks`:

```
draft → open (marks are being entered) → submitted → locked
                          ↑______ returned ______|
```

| Step | Who | Gates (facts) |
|---|---|---|
| create | The teacher of that class and subject | The period is open. The kind is active for this division |
| enter marks | Same teacher | The marks window is open. Each mark is within the scale |
| submit | Same teacher | Every enrolled learner has a mark or a stated reason (absent, excused, exempt) |
| return | Head of department or academic officer | — |
| lock | Head of department or academic officer. Never the person who entered the marks, while somebody else holds the permission | — |

Marks (`assessment_marks`) are rows per learner per assessment, with `placement_id` (document 09), the value, an optional reason for no mark, and who entered it. A change after submission is a correction: a new row that points at the old one, with a reason, audited.

## 4. The grading plan

One plan per subject, level and academic period: `grading_plans` with `mode`, `version`, and its `parts`.

| Mode | Term grade is |
|---|---|
| `ExamOnly` | The end-of-term exam alone. Other assessments are for practice and feedback; they are shown, they do not count |
| `Continuous` | A weighted total of parts, for example Continuous assessment 40% + End-of-term exam 60% |

A part (`grading_plan_parts`): `key`, `label`, `weight` (the weights of a plan sum to 100), `kinds` it accepts, and how several assessments inside it combine:

| Rule | Meaning |
|---|---|
| `average` | Mean of the percentage scores |
| `best_n` | Mean of the best N |
| `weighted_by_max` | Sum of marks over sum of maximum marks |
| `single` | Exactly one assessment is expected (the exam) |

Missing work inside a part, per plan: `excused → left out`, `unexcused → zero`, or `ask` (the teacher must decide before submitting).

Rules:

- **The Profile Blueprint binds what a school may choose:** `AssessmentSlot::TermGrade` = `ExamOnly`, `Continuous`, or `SchoolChooses`. Launch default for primary and secondary: `SchoolChooses`, seeded as Continuous 40 / 60.
- **The academic office owns the plan** (`assessment.plan`). A teacher sees the plan and cannot change the weights.
- **A teacher decides one thing per assessment:** does it count? If it counts, it goes into the part that accepts its kind. In `ExamOnly` mode the question is not asked for anything except the exam.
- **A plan is pinned for the period.** Changing weights after the period starts makes a new plan version, needs a reason, is audited, and recomputes the projection. It never happens silently.
- **The term grade is always computed** from locked marks and the pinned plan version. Nobody types a term grade. The screen shows the working: each part, its assessments, its score, its weight.
- **Publication snapshots the result.** `results.termly` (document 02) publishes a result set; the snapshot stores the grade, the plan version and the marks it was built from. Later corrections republish; the earlier snapshot is kept.

Gates on publishing a term result: every counted assessment is locked; every part that expects an assessment has one; no learner has an undecided missing mark.

## 5. Scales

`grading_scales`: numeric with bands (for example 80–100 = A / Distinction), or descriptive (ordered labels, no arithmetic). A descriptive scale has no term-grade arithmetic; the end-of-term progress review is its own record, written by the teacher with the term's observations beside it.

## 6. Permissions

| Code | Sentence |
|---|---|
| `assessment.create` | Set an assessment for your own classes and subjects |
| `result.enter` | Enter marks for your own subjects and classes, while the marks window is open |
| `assessment.lock` | Lock submitted marks. Never the person who entered them |
| `assessment.plan` | Set how the term grade is made: mode, parts and weights |
| `result.publish` | Make results visible to learners and guardians. Never the person who entered them |

## 7. Notices and audit

Notices: `assessment.marks_due` (to the teacher, before the window closes), `assessment.submitted` (desk), `assessment.returned` (party: the teacher), `results.published` (party: learner and guardians).

Audit: creating, submitting, returning, locking, every mark correction (old → new, reason), every plan version, every publication.

## 8. Screens

1. **New assessment** (teacher): pick the kind, class and subject, title, date, out of; answer "does it count?"; see where it lands in the term grade.
2. **Marks sheet** (teacher): one row per learner, like the register; reasons for no mark; the journey ladder; submit.
3. **Grading plan** (academic office): mode, parts, weights, rules.
4. **Learner profile**: the term's assessments and the working of each term grade.

## 9. First release, as approved (16 November 2026)

Designs: the canvas, Page 5 (Assessment: marks and term grades). In scope: scales, grading plans, setting assessments, the marks sheet, submit, lock and return, term grades with their working, class results, and the learner record's Results part. Not in scope: publishing (`results.termly`), report cards, the parent portal, ECD observations (an observational level shows no Marks screens).

**Module.** `modules/Assessment` (key `assessment`, optional tier), requiring Institution, Academics and Enrolment cores. It reads classes, subjects, teaching and terms through `AcademicCalendar` / `Timetable`-style contracts and learners through `EnrolmentDirectory`. Binding slot `AssessmentSlot::Model` (`marks` | `observational`, fallback `marks`); the Nursery profile binds `observational`.

**Tables.**
- `assessment_scales`: id, name, `overall` (`average_percent` | `average_grade` | `best_six`: how a learner is summed up across subjects; the last two need numeric grades), `pass_from` (the worst passing grade), `bands` jsonb `[{grade, word, from, to}]` (percent, inclusive, no gaps or overlaps across 0–100), `track` (`malawi`|`british`), `position`, timestamps, `retired_at`. Seeded once by `acadly:sync` (idempotent): *Primary 1 to 4* (4 80–100 Excellent, 3 60–79 Good, 2 40–59 Average, 1 0–39 Below average; average grade, higher is better; pass from 2; Standards 1 to 7), *PSLCE grades* (A 75–100 Distinction, B 60–74 Strong credit, C 50–59 Credit, D 40–49 Pass, F 0–39 Fail; Standard 8), *Percent and letters* (A 80–100 Excellent, B 70–79 Very good, C 60–69 Good, D 50–59 Pass, F 0–49 Fail), *JCE grades* (A 75–100 Distinction, B 65–74 Strong credit, C 55–64 Credit, D 40–54 Pass, F 0–39 Fail), *MSCE points* (1 75–100 Distinction, 2 70–74 Distinction, 3 65–69 Strong credit, 4 60–64 Credit, 5 55–59 Credit, 6 50–54 Weak credit, 7 45–49 Pass, 8 40–44 Weak pass, 9 0–39 Fail; best six, lower is better; pass from 8), *British primary* (Greater depth 85–100, Expected 60–84, Working towards 0–59), *GCSE 9 to 1* (9 90–100 … 1 10–19, U 0–9; a common pass from 4), *A level* (A* 90–100, A 80–89, B 70–79, C 60–69, D 50–59, E 40–49, U 0–39). Bands are starting values to check against the latest examination-board circular; the screen says so.
- `assessment_level_scales`: division_id, level_code, scale_id (primary key division+level). Seeded: Primary levels → Percent and letters; F1, F2 → JCE grades; F3, F4 → MSCE points.
- `grading_plans`: id, division_id, level_code, period_id, subject_id (null = the school plan for that level), version, mode (`continuous`|`exam_only`), parts jsonb `[{key, label, weight, kinds[], rule: average|best_n|weighted_by_max|single, best_n?, missing: left_out|zero|ask}]`, reason, created_by, created_at. Append-only: a change is a new version row; the highest version is in force. A level with no plan for the term uses the default: continuous, *Continuous assessment* 40 (all kinds except the exam, average, missing excused left out, unexcused zero) + *End-of-term exam* 60 (single). Saving needs weights summing to 100, every kind in at most one part, and a reason once the term has begun.
- `assessments`: id, class_id, subject_id, period_id, kind (key), title, held_on, out_of (1–1000), counts (bool), part_key (null when it does not count), created_by, state (journey column), journey_ref, timestamps. Kinds are a fixed list in the module for this release: `class_test` Class test, `weekly_test` Weekly test, `mid_term` Mid-term test, `end_of_term` End-of-term exam, `assignment` Assignment, `practical` Practical.
- `assessment_marks`: append-only: id, assessment_id, learner_id, value (decimal, null with a reason), reason (`absent`|`excused`|`exempt`|null), entered_by, entered_at, replaces_id (null), note. The mark in force is the latest per learner. Before submission a save adds rows only for changed marks; after locking, a change is a correction with a reason (`replaces_id` + note), audited, recomputing term grades.

**Journey** `assessment.marks@2026.1` (follow `attendance.correction` for the shape): states `marking` (initial), `submitted`, `locked` (terminal), `returned`. Steps: *submit* (from marking or returned; capability `result.enter`; gate: every learner in the class on the day has a mark or a reason), *lock* (from submitted; capability `assessment.lock`; four eyes against submit), *return* (from submitted; capability `assessment.lock`; requires a reason). Notices: `assessment.submitted` (desk, acted on with `assessment.lock`), `assessment.returned` (named people: the teacher who submitted).

**Permissions.** `assessment.create` (Set assessments for the classes and subjects you teach), `result.enter` (Enter marks for your own assessments while they are open), `assessment.lock` (Lock or return submitted marks; never your own), `assessment.plan` (Keep scales and grading plans), `assessment.view` (sight: See assessments and term grades where the role is given). Who may set an assessment for a class and subject: holds `assessment.create` at the class AND is given that subject in that class in Teaching (or holds `assessment.plan` there). Every read checks access at the class.

**Term grades.** `TermGrades` computes, never stores: for a class, period and subject, the plan in force (subject plan, else level plan, else default) and the scale of the level; only *locked* assessments that count; each part's score per its rule (percentages; `missing` handling); the term percentage = Σ weight × part score over the parts that have work (a part with no locked work makes the grade "not ready"); the grade from the scale bands. The working is returned with every grade: parts, assessments with mark/out of/percent, part score, weight, the sum, the plan version.

**Addresses.**
- `GET/PUT /assessment/scales`, `PUT /assessment/scales/{id}`, `PUT /assessment/level-scales`.
- `GET /assessment/plans?division_id&level_code&period_id`, `POST /assessment/plans` (a new version).
- `GET /assessment/mine` (the teacher's assessments this term with counts), `GET /assessment/options` (classes × subjects the viewer may set, kinds, where each kind lands), `POST /assessment/assessments`.
- `GET /assessment/assessments/{id}` (the sheet: learners, marks in force, stats, ladder, can_* flags), `PUT /assessment/assessments/{id}/marks` (save), `POST …/submit`, `POST …/lock`, `POST …/return {reason}`, `POST …/correct {learner_id, value|reason, note}`.
- The office list is a data grid (`GridDefinition` key `assessments`, `GET /assessment/grid`): columns class, assessment (subject · kind · title), teacher, held, marked (n/m), average, state; filters state, level, subject, kind, held (dates); bulk *lock* (refused per row for own marks / not submitted).
- `GET /assessment/results?class_id&period_id` (learners × subjects grades, each learner's overall result as the scale says, total marks and class position, readiness), `GET /assessment/results/working?class_id&learner_id&subject_id&period_id`.
- Learner record part `assessment` (title *Results*): band cells (class position, and the overall result), and `GET /assessment/learner/{id}` (term grades per subject with working links, and this term's assessments with the learner's mark).

### 9.1 Class position (D-076)

Every class result ranks learners by **total marks**: the term percentages added up, each subject out of 100. The highest total is first. Equal totals share a position and the next is skipped (1, 2, =3, =3, 5). A learner with any term grade not ready is not ranked until it is. Position is computed, never stored, like the grades it comes from. It shows on Class results and on the learner record; publishing it to families comes with report cards.
