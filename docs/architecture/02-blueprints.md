# 02 — Blueprints

The Blueprint pattern comes from MCRS (`TRACK_BLUEPRINT_PATTERN.md`). Acadly keeps its laws, fixes its known weak spots, and splits it into two kinds because Acadly has two different problems.

| Kind | Question it answers | Pinned on | Example ref |
|---|---|---|---|
| **Profile Blueprint** | What *is* this division? Which modules, words, structure and rules apply? | `divisions.profile_ref` | `SECONDARY@2027.1` |
| **Journey Blueprint** | How does this record *move*? Which steps, gates, outcomes and effects? | the record, `journey_ref` | `admission.school@2027.1` |

A Profile is **shape**. A Journey is **motion**. A Profile points at the Journeys its division uses.

## 1. Laws shared by both kinds

1. **A blueprint is a versioned declaration.** Identity is `KEY@VERSION`.
2. **A released version is immutable.** A change is a new version. `blueprints.lock` stores a content hash for every released version; CI fails if a released hash changes.
3. **Instances are pinned.** A division stores its profile ref. A record stores its journey ref. The engine loads by that ref, never "the current one".
4. **Moving an instance to a new version is an explicit, audited act** (`blueprint:migrate`), never a side effect of a deploy.
5. **No conditionals inside a declaration.** No `if`, no `match`, no education-level name inside a step.
6. **Nothing is declared that is not executed.** The linter fails on any declared construct that no executor consumes. (MCRS carried inert `vocabulary`, `bands`, `cure` and `sla`. Not here.)
7. **Composition order:** bindings (difference of value) → `extends` with amendments (difference of shape) → a separate blueprint (difference of ownership).

### How declarations are stored

- Authored as **typed PHP** (fluent builders, enum cases, class-strings). The IDE and PHPStan catch typos. No bare-string step, state, gate or effect names.
- **Compiled to canonical JSON** by `php artisan blueprint:compile`. The JSON is what gets hashed into `blueprints.lock`, stored in `blueprint_versions`, served to the frontend, and diffed in pull requests.
- The runtime loads the compiled form. A declaration file is never `require`d on a request path.

## 2. Profile Blueprints

### 2.1 What a profile declares

| Block | Meaning | Consumed by |
|---|---|---|
| `vocabulary` | The words this division uses: Learner/Pupil/Student, Grade/Form/Level, Term/Semester | API labels, SPA `useVocabulary()`, notification templates, documents |
| `structure` | Period model, level ladder, grouping model, teaching model | Academics core (setup wizards, validation) |
| `modules` | required / allowed list. Anything not listed is not offered | Module activation (01 §6) |
| `bindings` | Typed values for the slots each module declares | Module strategies |
| `journeys` | Journey slot → journey ref | Journey engine when a record is created |
| `roles` | Role templates seeded for a new division | Access |
| `defaults` | Initial settings (attendance statuses, grading scale seed) | Institution settings |

### 2.2 Example

```php
// api/blueprints/profiles/Secondary/V2027_1.php
return ProfileBlueprint::make('SECONDARY@2027.1')
    ->title('Secondary school')
    ->extends('SCHOOL_BASE@2027.1')

    ->term(Term::Learner, 'Student', plural: 'Students')
    ->term(Term::Level, 'Form', plural: 'Forms')
    ->term(Term::Group, 'Stream', plural: 'Streams')
    ->term(Term::Period, 'Term', plural: 'Terms')

    ->structure(
        Structure::make()
            ->periods(PeriodModel::Terms, perYear: 3)
            ->levels(['F1' => 'Form 1', 'F2' => 'Form 2', 'F3' => 'Form 3', 'F4' => 'Form 4'])
            ->grouping(Grouping::LevelWithStreams)
            ->teaching(TeachingModel::SubjectTeachers)
    )

    ->requires(AdmissionsModule::class, AttendanceModule::class, AssessmentModule::class,
               CommunicationModule::class, PortalModule::class, ReportingModule::class)
    ->allows(LibraryModule::class, DataImportModule::class)

    ->bind(AttendanceSlot::Mode, AttendanceMode::DailyAndSubject)
    ->bind(AssessmentSlot::Model, AssessmentModel::Marks)
    ->bind(AssessmentSlot::ResultDocument, ResultDocument::ReportCard)
    ->bind(GuardianSlot::Role, GuardianRole::Gatekeeper)

    ->journey(JourneySlot::Admission, 'admission.school@2027.1')
    ->journey(JourneySlot::ResultPublication, 'results.termly@2027.1')
    ->journey(JourneySlot::AttendanceCorrection, 'attendance.correction@2027.1')

    ->roles(RoleTemplate::Headteacher, RoleTemplate::AcademicOfficer, RoleTemplate::AdmissionsOfficer,
            RoleTemplate::ClassTeacher, RoleTemplate::SubjectTeacher, RoleTemplate::Librarian);
```

### 2.3 The four launch profiles

| Profile | Built how | Key differences |
|---|---|---|
| `SCHOOL_BASE` | abstract (cannot be pinned) | Shared school shape: terms, guardians as gatekeepers, class registers |
| `PRIMARY` | extends `SCHOOL_BASE` | Standard/Grade ladder, class teacher model, marks |
| `SECONDARY` | extends `SCHOOL_BASE` | Form ladder, streams, subject teachers |
| `ECD` | extends `SCHOOL_BASE` | Rooms/groups, caregivers, observational assessment, daily attendance only, SafeRelease allowed |
| `TERTIARY` | **separate blueprint** | Semesters, programmes, course registration, per-session attendance, credit/GPA, guardian is next-of-kin only |

Tertiary does not inherit. Most of its shape is different, and inheriting would mean overriding nearly everything. That is the signal that inheritance is the wrong tool.

### 2.4 Bindings: how one module serves four levels

A module declares typed slots. A profile binds them. The module picks a strategy by the bound value.

```php
enum AttendanceSlot implements BindingSlot
{
    case Mode;                       // how attendance is taken
    case ReleaseTracking;            // whether departure is a separate event

    public function accepts(): string
    {
        return match ($this) {
            self::Mode            => AttendanceMode::class,     // Daily | DailyAndSubject | PerSession
            self::ReleaseTracking => ReleaseTracking::class,    // Off | DepartureOnly | AuthorisedCollector
        };
    }
}
```

```php
// inside the Attendance module
$mode = $profile->binding(AttendanceSlot::Mode);          // an AttendanceMode
$strategy = $this->sessions->for($mode);                  // DailySessions | SubjectSessions | CourseSessions
```

**The rule:** code switches on a binding value from its own module's vocabulary. Code never switches on the education level. *Ask the blueprint what. Never ask the level who.* An architecture test fails the build if `ProfileKey` or a profile name appears outside `api/blueprints/`.

### 2.5 Customer configuration is not a blueprint

Three layers, strictly ordered:

```
Profile Blueprint   product-defined, versioned, in the repo          "what a secondary school is"
Institution settings customer-defined, in the database, validated     "we call it Grade, not Form; 2 terms"
Feature entitlement  commercial, in the database                      "this tenant bought Library"
```

Settings may only vary what the blueprint marks as overridable (`->term(..., overridable: true)`). Settings never fork logic.

## 3. Journey Blueprints

Use a journey when a record has **three or more states and a rule about who or what lets it move**. Plain create/edit screens are not journeys. Append-only fact streams (attendance events) are not journeys.

### 3.1 MVP journeys

| Journey key | Subject | States (short) |
|---|---|---|
| `admission.*` | Admission application | draft → submitted → under_review → accepted / waitlisted / rejected / withdrawn |
| `learner.progression` | Year-end decision per learner | proposed → decided → awaiting_intake → effective (see 09) |
| `learner.campus_transfer` | Transfer request | requested → approved_by_sending → accepted_by_receiving → effective / declined |
| `learner.exit` | Enrolment | active → withdrawn / transferred_out / completed |
| `guardian.link` | Learner–guardian relationship | requested → verified → active ⇄ suspended → revoked |
| `attendance.correction` | Correction request | requested → approved / declined |
| `results.*` | Result set for class × subject × period | draft → submitted → reviewed → published → correction_open → republished |
| `library.resource` | Library resource | draft → published → withdrawn → archived |
| `tenant.lifecycle` | Tenant | pending_setup → active ⇄ suspended → closed |
| `saferelease.pickup` (P1) | Pickup request | announced → verified → released / disputed / cancelled |

### 3.2 The four questions

Every step answers them in this order. They are never fused.

| # | Question | Name | Answered by |
|---|---|---|---|
| 1 | Can this happen at all? | **Admissibility** | Gates — pure facts about the world, each with a reason and a remedy |
| 2 | May *you* do it? | **Authority** | A permission at the right scope, plus structural checks (four-eyes, not-own-record) |
| 3 | What follows? | **Consequence** | Effects — ordered, idempotent, boundary-classified |
| 4 | What is now true? | **Commitment** | Transition — new state, step completion row, domain events |

### 3.3 Example

```php
// api/modules/Admissions/Journeys/School/V2027_1.php
use Acadly\Modules\Admissions\Journeys\School\{State as St, StepId as S, OutcomeId as O};

return JourneyBlueprint::make('admission.school@2027.1')
    ->subject(AdmissionApplication::class)
    ->states(St::class, initial: St::Draft, terminal: [St::Rejected, St::Withdrawn, St::Converted])
    ->milestone(AdmissionMilestone::Decided, St::Accepted, St::Rejected, St::Waitlisted)

    ->stage('review', 'Review and decision', fn (Stage $stage) => $stage
        ->step(
            Step::make(S::Decide)
                ->from(St::UnderReview, St::Waitlisted)
                ->actor(Actor::Officer)
                ->action(Verb::Decide)
                ->gate(RequiredFieldsComplete::class)
                ->gate(NoOpenDuplicateFlag::class)
                ->capability(AdmissionPermission::Decide)
                ->outcome(
                    Outcome::make(O::Accepted)
                        ->effect(RecordAdmissionDecision::class)
                        ->effect(Notify::topic(ApplicationAccepted::class))
                        ->transition(Transition::to(St::Accepted)->emits(AdmissionDecisionRecorded::class))
                )
                ->outcome(
                    Outcome::make(O::Waitlisted)
                        ->effect(RecordAdmissionDecision::class)
                        ->transition(Transition::to(St::Waitlisted))
                )
                ->outcome(
                    Outcome::make(O::Rejected)
                        ->effect(RecordAdmissionDecision::class, RejectionArgs::reasonFromInput())
                        ->effect(Notify::topic(ApplicationRejected::class))
                        ->transition(Transition::to(St::Rejected))
                )
        )
        ->step(
            Step::make(S::Convert)
                ->from(St::Accepted)
                ->actor(Actor::Officer)
                ->action(Verb::Record)
                ->gate(SeatAvailable::class)
                ->capability(EnrolmentPermission::Create)
                ->outcome(
                    Outcome::make(O::Converted)
                        ->effect(CreateLearnerFromApplication::class)       // idempotent: PRD FR-ADM-005
                        ->effect(OpenEnrolment::class, EnrolmentArgs::fromProduced(CreateLearnerFromApplication::class))
                        ->transition(Transition::to(St::Converted)->emits(LearnerEnrolled::class))
                )
        )
    );
```

Notice what is absent: no `if`, no profile name, no string ids.

### 3.4 Engine

One engine for every journey and every subject type. It lives in `kernel/Blueprint/Engine` and names no module.

```php
interface JourneyEngine
{
    /** Read-only. What may this principal do to this record, and if not, why not? */
    public function available(JourneySubject $subject, Principal $who): StepAvailability;

    /** The single mutating entry point for journey state. */
    public function perform(JourneySubject $subject, Principal $who, StepRef $step, OutcomeRef $outcome, array $input = []): Receipt;
}
```

`perform()`:

1. Load the blueprint by the subject's pinned `journey_ref`.
2. Check the step is reachable from the current state.
3. **Admissibility** — evaluate gates. Blocked → `StepNotAdmissible` with reason and remedy.
4. **Authority** — `Access::check()` at the subject's scope, then structural checks.
5. Inside one database transaction: run **internal effects** in order → write state → append `journey_step_completions` → write outbox events → write audit event.
6. After commit: run **after-commit effects** (notifications, external calls) through the queue.

Supporting executors (all real, none decorative):

| Executor | Does |
|---|---|
| `SlaClock` | Scheduled. Finds steps past their declared SLA. Emits escalation topics |
| `CureWindows` | Scheduled. Opens, counts and lapses cure rounds |
| `ObligationRunner` | Scheduled. Calendar duties declared on a journey or profile |
| `Projection` | Read-only. Ladder, "what happens next", state label, Mermaid diagram |

### 3.5 Contracts

```php
interface JourneySubject            // implemented by the model that travels
{
    public function journeyRef(): JourneyRef;
    public function journeyState(): string;
    public function scope(): Scope;               // tenant / institution / division / campus
}

interface Gate                       // pure, cheap, explains itself
{
    public function check(Context $ctx, ?GateArgs $args = null): GateResult;   // ok() | blocked(reason, remedy)
}

interface Effect                     // idempotent, boundary-classified
{
    public function boundary(): Boundary;                                       // Internal | AfterCommit
    public function apply(Context $ctx, ?EffectArgs $args, IdempotencyKey $key): EffectResult;
}
```

- Gates and effects are referenced by **class-string**, with typed argument objects. The container resolves them. No name registry to keep in sync.
- The state column on a subject has **no public setter**. Only the engine's `Commitment` writes it. A database trigger rejects an `UPDATE` of a journey state column unless the session flag `acadly.journey_commit` is set.
- The idempotency key is `hash(subject, journey_ref, step, outcome, effect_index)`, stored in `effect_applications` under a unique index.

### 3.6 Fixes over the MCRS implementation

| MCRS weak spot | Acadly rule |
|---|---|
| Bindings duplicated in an enum and in the blueprint | Bindings live only in the blueprint. Slots are typed by the owning module |
| Bare strings for states, steps, gates, effects | Enum cases and class-strings. PHPStan max level |
| Track checks leaking into UI code (`mechanism === A64 && state === 'VALIDATED'`) | The API returns `available_actions` with reasons. The UI renders them. ESLint forbids comparing state literals |
| Steps fired from `saved` hooks | Model events may not hold business logic. Architecture test |
| Two paths to change state | One path (`perform`). Database trigger guards the column |
| Declared but unused blocks | Linter fails on unconsumed constructs |
| Child blueprint replaces a whole step and silently loses effects | `->amend(Step, fn)` patches a step (add/remove gate, add/remove effect). `->replace()` must be explicit. `blueprint:diff` prints the effective result |
| Blueprint is arbitrary PHP loaded at runtime | Typed PHP is compiled to canonical JSON. Runtime loads JSON. Hash locked |
| Engine bound to one model class | `JourneySubject` contract and morph columns from day one |
| Cross-track "key states" as strings | `milestone(EnumCase, …states)`. Cross-journey code asks `hasReached(AdmissionMilestone::Decided)` |

### 3.7 Generated from blueprints

- Permission entries for every step capability.
- Notification topic wiring for every `Notify::topic()`.
- `available_actions` in API resources.
- Progress ladder and "what happens next" text.
- Mermaid state diagrams in `docs/generated/`.
- Conformance tests per step: gate blocks, missing permission blocks, effects replay safely, after-commit effects do not run before commit, every state reachable, every non-terminal state has an exit.

### 3.8 Tables

| Table | Purpose |
|---|---|
| `blueprint_versions` | kind, key, version, compiled JSON, content hash, released_at |
| `journey_step_completions` | Append-only. subject (morph), journey_ref, step, outcome, from_state, to_state, actor, input snapshot, at |
| `effect_applications` | Append-only. idempotency_key (unique), effect, subject, produced reference, meta |
| `blueprint_migrations` | Audited moves of an instance from one version to another, with basis and actor |

### 3.9 Commands

```
php artisan blueprint:compile        compile PHP declarations to JSON, update lock for unreleased versions
php artisan blueprint:lint           reachability, exhaustiveness, unbound slots, unconsumed constructs, module offers
php artisan blueprint:diff A B       effective difference between two versions
php artisan blueprint:release REF    freeze a version (writes the hash to blueprints.lock)
php artisan blueprint:diagram REF    Mermaid output
php artisan blueprint:migrate        move pinned instances, audited
```
