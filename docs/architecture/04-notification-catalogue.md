# 04 — Notification catalogue

**Lineage:** the Notice Catalogue in `mnr-forestry-me` (`app.frontend/app/Support/Notifications/`: `NoticeCatalogue`, `Notice`, `Wordings`, `TopicSync`, `NotificationDispatcher`) and its earlier form in MCRS. Acadly keeps that design and hardens it.

## 1. What is kept from the Forestry catalogue

| Idea | Kept as |
|---|---|
| Every notice is declared once, in code, with its words beside it | `Notice::make(...)` fluent declaration, one per event |
| The settings page and the wording page read the catalogue, so neither can offer a message that nothing sends | Same. The catalogue is the only source |
| Three audiences that route differently on purpose: the party it concerns, the subscribed desk, named people | `Party`, `Desk`, `Person` (§4) |
| Staff are told **by subscription, never by role**. "A permission says what somebody may do, not what they want to read" | Same. Desk subscriptions are rows, scoped by campus/division instead of region |
| `actedOnWith(capabilities)`: a summons is different from news | Same. Used to seed defaults and to flag topics nobody hears |
| The customer can reword a notice without a deploy; the event, the audience and the facts cannot change | `notice_wordings`, per institution and locale (§6) |
| A wording may only use tokens the declaration uses; a wording equal to the declaration is not stored | Same |
| Whoever caused the event is never told about it | Same |
| One delivery row per person per channel, written **before** the send. A row still queued an hour later is the report | Same, plus a monitor |
| Nothing in dispatch may throw. A dead mail server never undoes the business action | Same |
| Urgent notices pass through quiet hours | `->urgent()` |
| Push rides with the in-app notice | Same |
| Code and database are brought into line by one sync command after every deploy | `acadly:sync`, plus a readiness check (§9) |

## 2. What is changed, and why

| Forestry today | Acadly | Why |
|---|---|---|
| One central `NoticeCatalogue` file lists every notice | Each **module** declares its own notices. The catalogue is the union of the installed modules; a topic of a switched-off module is hidden and never sent, but its settings are kept | A school without Library must not carry Library notices. Modules stay sealed |
| Call sites pass an event string and a loose token array: `forReturn('return.submitted', $return, $actor, [...])` | Call sites send a **typed topic object**: `send(new LearnerMarkedAbsent(...))` | A typo or a missing token fails in the editor, not as a dash in a parent's SMS |
| Undeclared event or unsynced topic → `Log::warning` and skip | Impossible by construction; the release is not ready until catalogue and database match | Silent skips hide lost messages |
| Dispatcher knows `QuarterlyReturn` and `ReportingUnit` | Dispatcher knows only `SubjectRef` and `Scope`. Party resolvers come from modules | The kernel names no domain |
| `pushChannel()` matches on event-name prefixes | Urgency and category are declared on the notice | No string matching |
| English only; one wording | Locale files from day one (`en`, `ny`); vocabulary tokens per division | Chichewa; "Student" vs "Pupil" vs "Child" |
| Mail and in-app (push by ride-along) | Adds SMS and later WhatsApp through adapters, with **metering and caps** | Parents are reached by SMS; SMS costs money |
| No tenant | Every row is tenant-scoped; wording and subscriptions are per institution | Multi-tenant |

## 3. A notice

```php
// app.backend/modules/Attendance/Notifications/LearnerMarkedAbsent.php
final class LearnerMarkedAbsent implements Topic
{
    public function __construct(
        public readonly string $learnerId,
        public readonly string $sessionId,
        public readonly CarbonImmutable $date,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('attendance.learner_absent')
            ->named('Learner marked absent')
            ->category(Category::Attendance)
            ->subject(':learner was marked absent on :date')
            ->line(':learner was marked absent from :class on :date.')
            ->line('Contact :institution if this is not correct.')
            ->short(':learner was marked absent on :date. Open Acadly for details.')   // SMS and push
            ->action('Open attendance', Link::LearnerAttendance)
            ->toParty(GuardiansOfLearner::class)
            ->toDesk()
            ->actedOnWith(AttendancePermission::Correct)
            ->channels(default: [Channel::InApp, Channel::Sms], allowed: [Channel::InApp, Channel::Sms, Channel::Email, Channel::Push])
            ->optOut(OptOut::Allowed);
    }

    public function subject(): SubjectRef { return SubjectRef::learner($this->learnerId); }

    /** The facts this notice may name. The wording may use these tokens and no others. */
    public function tokens(TokenSource $source): array
    {
        return [
            'learner' => $source->learnerName($this->learnerId),
            'class'   => $source->className($this->sessionId),
            'date'    => $source->localDate($this->date),
        ];
    }

    public function dedupeKey(): string { return "absent:{$this->learnerId}:{$this->sessionId}"; }
}
```

Sending:

```php
$this->notifier->send(new LearnerMarkedAbsent($learner->id, $session->id, $session->date), actor: $who);   // from an Action
->effect(Notify::topic(ApplicationAccepted::class))                                                         // from a Journey
```

Tokens always available without declaring them: `:institution`, `:campus`, `:actor`, and the vocabulary tokens `:term.learner`, `:term.level`, `:term.period`.

### Declaration fields

| Method | Meaning |
|---|---|
| `named()` | The topic's name in lists. The event, not one message about it |
| `category()` | Grouping on the settings pages: Attendance, Academic, Admissions, Safeguarding, Library, Account, System |
| `subject()` / `line()` / `action()` | The full wording: email and in-app |
| `short()` | The wording for SMS and push. Has a length budget. Must not carry sensitive detail (marks, health, custody); it points to the portal |
| `toParty()` / `toDesk()` / `toPerson()` | Audiences (§4) |
| `actedOnWith()` | Permissions of whoever can *do* something about it |
| `channels()` | Default channels, and the ceiling a school or person may choose from |
| `optOut()` | `Allowed`, `Never` (the party cannot silence it) |
| `urgent()` | Passes quiet hours. Declares a fallback chain: if the first channel fails, try the next |

Safeguarding notices (child released, disputed pickup, emergency closure) are always `optOut(Never)` and `urgent()`.

## 4. The three audiences

They route differently on purpose.

| Audience | Who | How they are found | Can they opt out? |
|---|---|---|---|
| **Party** | The people the record is about: the learner's guardians, the applicant, the learner, the staff member whose request was decided | A resolver class from the owning module (`GuardiansOfLearner`, `TheLearner`, `TheApplicant`, `ClassTeacherOf`) | Only if the notice allows it. "Your child was released" is not a newsletter |
| **Desk** | Staff who asked to hear about this topic | `notification_subscriptions`: user × topic × scope. **Never a query on roles or permissions** | Yes. It is their subscription |
| **Person** | Named people the sender picks | Passed by the sender: `send($topic, people: [...])` | Per notice |

Rules:

- **Party resolvers re-check the relation at send time.** A suspended guardian link receives nothing. A guardian's information scope must cover the notice's category.
- **A party recipient is a person, with or without a login.** A guardian with only a phone number still gets the SMS.
- **Desk subscriptions carry a scope:** whole institution (null), one division, or one campus. A campus officer hears about their campus. Head office hears about all. This is the Forestry "region" idea on Acadly's axes.
- **Desk defaults are seeded, once.** When a staff member gains a role, they are subscribed to the topics they can act on (`actedOnWith` ∩ their permissions) at their scope. A subscription they remove is never added back.
- **Unheard topics are shown.** The notification settings page lists any summons (`actedOnWith` set) that has no desk subscriber at some scope. That is a gap an administrator must see.
- **The actor is never told about their own action.**
- **Nobody is told twice** for one event, even if they are in two audiences.

## 5. Who decides what

```
Notice declaration     product: event, audience kinds, facts, allowed channels            (code)
Institution settings   school: topic on/off (where allowed), channels within the ceiling,
                       quiet hours, its own wording                                        (database)
Subscription           staff: which desk topics, at which scope, on which channels         (database)
Personal preference    party: channel choice and opt-out, where the notice allows it       (database)
```

## 6. Wording

- The **declared wording** lives in the notice (English). Translations live beside it: `modules/Attendance/Notifications/lang/ny.php`, keyed by event.
- An institution may **reword** a notice on the Notice wording page. Stored in `notice_wordings` per institution × event × locale. Each column overrides one part; null means "as declared".
- A wording that names a token the notice does not declare is refused at the form.
- A wording equal to the declaration is not stored, so an improved declared sentence still reaches every school that did not reword it.
- The words may change. The event, the audience and the facts may not.
- A token with nothing behind it prints as a dash, never as `:token`. Token filling is one pass, so a value that contains a colon is never read as a token.

## 7. Pipeline

```
Action / Journey effect
   │  notifier->send(topic, actor)       inside the DB transaction: one outbox row, nothing else
   ▼
after commit → queue: ResolveNotice
   1. load the notice (compiled catalogue) and the institution's wording
   2. module active here? topic switched on?
   3. resolve Party, Desk, Person → recipients. Drop the actor. Drop duplicates
   4. apply preferences, quiet hours (unless urgent), quota, undeliverable addresses
   5. write notification_deliveries rows, status `queued`  (insert-or-ignore on dedupe key)
   ▼
queue: DeliverNotice (one per row)
   6. fill tokens, render per channel and locale
   7. ChannelAdapter->send() → provider
   8. settle the row from the provider response and later callbacks
   9. on failure: retry with backoff → fallback channel if urgent → failed
```

- No recipients at step 3 writes one row with status `unaddressed`. "Nobody was told" is a visible fact.
- A scheduled check raises a system notice for rows still `queued` after one hour.
- Nothing in this pipeline can fail the business transaction.

## 8. Channels, providers, cost

```php
interface ChannelAdapter
{
    public function channel(): Channel;                         // InApp | Email | Sms | Push | WhatsApp
    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt;
    public function parseCallback(Request $request): ?DeliveryUpdate;
}
```

- MVP: `InApp`, `Email`, `Sms`. `Push` rides with `InApp` when the person has a device. `WhatsApp` is a later adapter.
- `notification_routes` picks the provider per tenant and channel. The product is not tied to one SMS company.
- Every paid send writes `notification_usage`. A quota check stops non-urgent paid sends at the tenant's cap and raises a system notice to the administrator.
- Addresses that cannot be reached (reserved test domains, invalid numbers) are filtered before a delivery row is written for that channel.

## 9. Keeping code and database the same

- `php artisan acadly:sync` runs after every deploy. It brings permissions, default roles and notification topics into line with the code: created, updated, pruned.
- The compiled catalogue has a hash. The readiness probe compares it with the hash the last sync stored. **A mismatch means the release is not ready.**
- `php artisan catalogue:lint` checks:
  - event keys are unique;
  - every `:token` in every wording and translation is declared by the topic;
  - `short()` exists when SMS or push is allowed, and fits the length budget;
  - every party resolver is registered by an active module;
  - `optOut(Never)` notices have a fallback chain;
  - every `Notify::topic()` in a journey points at a notice of a module that journey's module depends on.

## 10. Tables

| Table | Notes |
|---|---|
| `notification_topics` | Mirror of the catalogue: key, label, category, audiences, default channels, acting capabilities. Written only by sync |
| `notification_subscriptions` | Desk: user × topic × scope (institution / division / campus), optional channels. Partial unique index for the whole-institution row |
| `notification_preferences` | Party: person × topic × channel |
| `institution_topic_settings` | Institution × topic: enabled, channels, quiet hours |
| `notice_wordings` | Institution × event × locale: subject, lines, short, action label, updated by |
| `notification_deliveries` | One row per recipient × channel: event, audience, recipient snapshot, rendered subject and body, subject reference, scope, status, failure reason, settled at, dedupe key |
| `notification_usage` | Metering for paid channels |
| `notification_routes` | Tenant × channel → provider |
| `in_app_notifications` | The bell |

Delivery statuses: `queued`, `sent`, `delivered`, `failed`, `suppressed`, `unaddressed`.

## 11. Pages generated from the catalogue

- **Notification settings** (console): topics by category, who is subscribed at which scope, unheard topics.
- **Notice wording** (console): declared words, the school's words, allowed tokens, restore to declared.
- **My notifications** (console and portal): the person's own subscriptions or preferences.
- **Delivery log**: per learner, per topic, per channel, with failures.
- `docs/generated/notification-catalogue.md`.
