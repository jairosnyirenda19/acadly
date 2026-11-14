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

`assessments`: `id`, `tenant_id`, scope ids, `class_subject_id` (class × subject), `academic_period_id`, `kind_id`, `title`, `held_on`, `scale_id`, `max_mark` (null for descriptive scales), `counts` (boolean), `plan_part_key` (null when it does not count), `created_by`, journey state.

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
