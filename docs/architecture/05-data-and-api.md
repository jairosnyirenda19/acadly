# 05 — Data and API

## 1. Identifiers

- **Every primary key is a UUID, version 7**, column `id uuid`, generated in the application (`Ids::next()`). UUIDv7 is time-ordered, so B-tree indexes stay compact.
- Foreign keys are `uuid`. No auto-increment keys anywhere, including pivot tables.
- UUIDs may appear in URLs and API payloads. They are not guessable and not sequential. Authorisation never depends on an id being secret.
- Human-facing numbers (Acadly Learner ID, admission number, receipt number) are **separate columns**, never the key.

## 2. Table rules

| Rule | Detail |
|---|---|
| Names | `snake_case`, plural, prefixed by owning module: `attendance_sessions`, `library_resources` |
| Tenant column | Every tenant-owned table: `tenant_id uuid NOT NULL`, created by `$table->tenantOwned()` (adds column, `UNIQUE (tenant_id, id)`, index, RLS policy) |
| Scope columns | `institution_id` on institution-owned rows. `division_id` / `campus_id` where the record is bound to one (03 §2) |
| Foreign keys | Always declared. Composite with `tenant_id` between tenant-owned tables |
| Time | `timestamptz`, stored in UTC. Institution timezone is applied at the edge. Dates that are school-local facts (attendance date) are `date` |
| Enums | `varchar` + PHP backed enum + `CHECK` constraint. No PostgreSQL enum types (hard to change) |
| Money | `bigint` minor units + `char(3)` currency. Never float |
| JSONB | Only for genuinely variable data: settings, provider payload snapshots, compiled blueprints. Never for relationships |
| Deletion | Lifecycle status first (`archived`, `withdrawn`). Soft delete only with a stated purpose. Hard delete only through a retention job |
| Indexes | Lead with `tenant_id`. Standard path: `(tenant_id, institution_id, period_id, learner_id)` |
| Uniqueness | Business rules as database constraints where possible (partial unique index for "one active enrolment per learner per period") |

## 3. Three planes of data

| Plane | Nature | Examples |
|---|---|---|
| **Declaration** | Versioned, immutable | `blueprint_versions`, compiled notification catalogue |
| **State** | Current truth, mutable through Actions | `learners`, `enrolments`, `classes`, journey state columns |
| **Record** | Append-only history | `attendance_events`, `attendance_corrections`, `journey_step_completions`, `effect_applications`, `audit_events`, `notification_deliveries`, `result_publications`, `library_access_events` |

Record-plane tables:

- have no `updated_at`;
- reject `UPDATE` and `DELETE` through a database trigger (`$table->appendOnly()`);
- are the source from which summaries are rebuilt. A summary table (attendance summary, report card snapshot) is a cache that a command can regenerate.

Attendance is the model case (PRD §6.7): the original event is never changed. A correction is a new row that references it. The current status is a projection.

## 4. Audit

The full design is in [10-audit-trail.md](10-audit-trail.md). Summary:

`audit_events` (append-only): `id`, `tenant_id`, scope ids, `actor_user_id`, `actor_kind` (user, system, support, integration), `support_grant_id`, `action` (`learner.updated`), subject (type + id), `before` / `after` (changed fields only, sensitive fields masked), `reason`, `request_id`, `ip`, `occurred_at`.

- Written by the kernel from Actions (`Audit::record()`), inside the same transaction as the change.
- Journey steps, role changes, guardian link changes, result publication, exports and support access are always audited.
- Audit is not the debug log. Debug logs never contain personal data.

## 5. Domain events and the outbox

- A domain event is a small immutable class in a module's `Contracts/Events/`, named in the past tense: `LearnerEnrolled`, `AttendanceRecorded`, `ResultsPublished`.
- Events are written to `outbox_events` **inside the business transaction**. A worker publishes them after commit. An event is never lost and never published for a rolled-back change.
- Consumers are idempotent (`consumed_events (consumer, event_id)` unique).
- Events carry ids and facts, not whole models.
- Events do not replace validation inside the owning module. They tell others what already happened.
- The same outbox later feeds webhooks and the Stride bridge.

## 6. Writing code against the data

```php
final class RecordAttendance                       // Application/Actions
{
    public function __construct(private Access $access, private Audit $audit, private Outbox $outbox) {}

    public function handle(Principal $who, RecordAttendanceInput $input): AttendanceReceipt
    {
        $session = AttendanceSession::findOrFail($input->sessionId);
        $this->access->check($who, AttendancePermission::Capture, $session);

        return DB::transaction(function () use ($who, $input, $session) {
            // domain rules → append events → audit → outbox
        });
    }
}
```

- One Action = one use case = one transaction.
- Input and output are typed data objects. No raw request arrays below the controller.
- Queries return data objects or API resources, never models that lazy-load across modules.
- Model serialisation (`toArray`, `$hidden`) is never the API shape.

## 7. API contract

| Topic | Rule |
|---|---|
| Base | `/api/v1`. Breaking change → `/api/v2`. Additive changes stay in v1 |
| Shape | `/api/v1/institutions/{institution}/…` for institution work · `/api/v1/me/…` for portal and self · `/api/v1/platform/…` for IQ-X operations |
| Scope | The `{institution}` segment is a selector. The server checks it against memberships. Campus and division are filters and fields, validated against the user's scope |
| Format | JSON. `snake_case` fields. ISO 8601 timestamps with offset |
| Errors | RFC 9457 `application/problem+json` with a stable `code`, plus `errors` per field for validation. Journey blocks return `code: step_not_admissible` with `reason` and `remedy` |
| Lists | Cursor pagination. `?filter[status]=active&sort=-created_at`. Hard maximum page size |
| Writes | `Idempotency-Key` header required on create and on journey `perform`. Stored for 24 hours; a replay returns the first response |
| Journeys | Resources of journey subjects include `state`, `state_label`, `ladder` and `available_actions: [{step, outcomes, permitted, blocked_reason, remedy}]`. One endpoint performs: `POST …/{id}/journey/{step}` with `{outcome, input}` |
| Files | Never a storage URL. `POST …/access` returns a short-lived signed URL or a stream endpoint, after an access check and an access-event row |
| Limits | Rate limits on auth, public and sensitive endpoints |
| Docs | OpenAPI 3.1 generated from the code into `contracts/openapi.json`. Committed. CI regenerates and fails on any difference |
| Webhooks (later) | Signed, timestamped, replay-protected, retried, logged |

## 8. Offline and sync (designed now, built at P1)

Attendance capture may be queued on a device. The contract is fixed from day one so it does not need a redesign:

- The **client** generates the event UUID and the capture timestamp.
- The server stores `captured_at` (client) and `received_at` (server) separately.
- `POST …/attendance/sync` accepts a batch. Each event is insert-or-ignore on its id. The response lists accepted, duplicate and rejected events with reasons.
- Suspicious clock skew is flagged for review, never silently corrected.

## 9. Files

- Private bucket only. Object keys are random; they never contain names or tenant slugs in readable form.
- Upload pipeline: size limit → extension → MIME sniffing → format integrity check (PDF/EPUB parse) → malware scan job → only then `available`.
- Library file access goes through the Library module's access rule check on every request for a new URL. A leaked URL expires in minutes.

## 10. Retention

Each table is tagged in its migration with a retention class (`operational`, `academic_record`, `audit`, `transient`). Periods are an open product decision (PRD §20.11). The tagging is mandatory now so the policy can be applied later without archaeology.
