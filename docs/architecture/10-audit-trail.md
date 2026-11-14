# 10 — Audit trail

**Lineage:** the audit trail in `e-voucher-system/core` (`app.backend/app/Services/Auditor.php`, `Models/AuditEvent.php`, `Http/Controllers/Api/V1/AuditController.php`, `app.frontend/src/features/audit/`), plus the sealing ideas already used in MCRS and Forestry M&E (hash chain, nightly seal, tamper and mass-delete notices). Acadly keeps what works and closes the gaps.

## 1. What is kept from e-voucher

| Idea | Kept as |
|---|---|
| One call at the moment an action is performed | `Audit::record(...)` from Actions and journey steps |
| **Outcome on every event**: success or failure. Failed sign-ins are events too | `outcome`: `done`, `failed`, `refused` |
| **Old → new diff** per field, built from a snapshot taken before the change | `changes: {field: {old, new}}` |
| Secrets are masked in the diff. The fact of the change stays | Field classes: `secret`, `personal`, `plain` (§4) |
| **The row stays readable for ever.** Subject label, actor role and session are copied onto the row, so a reader a year later needs no join and loses nothing when the subject is deleted | Same: `subject_label`, `actor_label`, `actor_roles` snapshots |
| **No foreign key on the actor.** Removing a user never removes or blocks their history | Same |
| Session id on the row, to group everything one sign-in did | `session_id`, plus `request_id` and `device_id` |
| Time is written by the application in UTC, never by a database default | Same, and the connection is pinned to UTC |
| Feed with filters, a stats call over the same filters, and a CSV export **that audits itself** | Same |
| A per-record feed ("everything that happened to this farmer") | Per subject, per actor, per session, and **per learner** |
| An event drawer that shows the old → new table | Same |

## 2. What is made stronger

| e-voucher today | Acadly | Why |
|---|---|---|
| "Quiet on failure": an exception while auditing is swallowed | A state change and its audit line are written **in the same transaction**. If the line cannot be written, the change does not happen | An action with no record is worse than a refused action |
| Nothing proves the log was not edited | **Hash chain** per tenant, **append-only** by database trigger and by privilege, **nightly seal** kept off the database, `audit:verify`, and a tamper notice (§5) | The log must be evidence, not a claim |
| Actions are free strings; the module is guessed from the prefix | Actions are **enum cases** declared by each module, each with a plain sentence | Typos fail in the editor; the screen never prints a raw code |
| Subject label guessed from a list of attribute names | The subject implements `Auditable::auditLabel()` | No guessing |
| Snapshot kept in a static array | The Action takes the snapshot (`$audit->watch($model)`) and passes a typed `Diff` | No hidden state between requests |
| Only writes are audited | **Sensitive reads** are audited too: welfare notes, any use of a sight permission, exports, library file opens, support access | Child data: who looked matters as much as who changed |
| First role name only | All roles held **at the scope of the action**, plus `acting_for` and `support_grant_id` | Scoped roles, cover and support access |
| No tenant | Every line carries tenant and scope; viewing is scoped like any other data | Multi-tenant |
| No watchers | Detectors raise notices (§6) | Someone must be told |
| Personal values sit in the row for ever | Personal values live in an encrypted side record that can be erased without breaking the chain (§4) | Erasure requests and retention |

## 3. The event

`audit_events` — append-only, partitioned by month.

| Group | Columns |
|---|---|
| Identity | `id` (UUIDv7), `seq` (per tenant, no gaps), `prev_hash`, `hash` |
| When | `occurred_at` (UTC, set by the app), `recorded_at` |
| Where | `tenant_id`, `institution_id`, `division_id`, `campus_id` |
| What | `module`, `action` (enum value), `outcome` (`done` / `failed` / `refused`), `refusal` (which check refused: permission, scope, module, gate + gate name) |
| Who | `actor_user_id` (no FK), `actor_kind` (`user`, `system`, `support`, `integration`), `actor_label`, `actor_roles` (snapshot), `acting_for_user_id`, `support_grant_id` |
| How | `session_id`, `request_id`, `device_id`, `ip`, `user_agent`, `channel` (`console`, `portal`, `api`, `job`) |
| On what | `subject_type`, `subject_id`, `subject_label`; **`learner_id`** when the event concerns one learner |
| Detail | `changes` (plain fields), `payload_id` (encrypted personal fields), `reason`, `journey_ref`, `journey_step`, `journey_outcome`, `meta` |

- `learner_id` gives one indexed question that a school asks often: *show everything that ever happened to this child's record, and who looked at it.*
- `reason` is mandatory for the actions that the catalogue marks so: attendance correction, result republication, guardian link suspension, role grant with a sight, support access, blueprint migration.
- A refused attempt is an event. "Tried to open welfare notes — refused, no permission" is exactly what an investigator needs.

## 4. Field classes and personal data

Every audited model declares a class per field. The diff builder obeys it.

| Class | In the diff | Example |
|---|---|---|
| `secret` | Masked both sides. Only the fact of change is kept | Password, MFA secret, tokens |
| `personal` | Stored in `audit_payloads`, encrypted with the tenant's key. The event keeps only the payload's hash | Date of birth, phone, address, welfare notes, marks |
| `plain` | Stored in `changes` | Class, status, campus, role name |

Because the chain covers the payload **hash**, not the payload, a personal payload can be erased later (retention, a lawful erasure request, or destroying the tenant key) and the chain still verifies. The line then reads: *detail erased on 12 March 2031 under retention rule R-3*. That erasure is itself an audit event.

## 5. Tamper evidence

Four locks on one door.

1. **Append-only by trigger.** `UPDATE` and `DELETE` on `audit_events` raise an error.
2. **Append-only by privilege.** The application's database role has `INSERT` and `SELECT` on the table and nothing else. The migration role is separate and is not used at run time.
3. **Hash chain.** Per tenant: `hash = sha256(prev_hash ‖ canonical(event))`, with a gapless `seq`. The writer takes a transaction-level advisory lock for the tenant, so order is exact. A removed line breaks `seq`; a changed line breaks `hash`.
4. **Nightly seal, kept elsewhere.** `audit_seals`: tenant, date, last `seq`, head hash, line count, signed with the platform key. The seal is written to object storage with a retention lock, and mailed to the tenant owner (`system.audit_sealed`). A later seal with fewer lines, or a head that does not extend the earlier one, proves removal even if someone with full database access rewrote the chain.

`php artisan audit:verify {tenant?}` walks the chain and checks it against the stored seals. It runs nightly before sealing. A failure raises `system.audit_tampered` to the tenant owner and to platform operations, and marks the tenant's audit screen in red until a platform operator records an investigation.

## 6. Watchers

Small detectors run on the event stream and raise notices through the notification catalogue.

| Detector | Raises |
|---|---|
| Many deletions, archive actions or role changes by one person in a short time | `system.mass_change_detected` |
| Many exports, or an export of a whole division | `system.bulk_export` |
| Repeated failed sign-ins for one account or from one address | `account.signin_attack` |
| A sight permission was granted | `access.sight_granted` (to the institution administrator and the head) |
| Support access started or ended | `access.support_opened` / `access.support_closed` |
| Many refused attempts by one person | `access.repeated_refusals` |
| Chain verification failed | `system.audit_tampered` |

Thresholds are institution settings with product defaults.

## 7. Writing audit lines

```php
final class CorrectAttendance                                  // an Action
{
    public function handle(Principal $who, CorrectAttendanceInput $in): AttendanceReceipt
    {
        $this->access->check($who, AttendancePermission::Correct, $event);      // a refusal is audited by Access itself

        return DB::transaction(function () use ($who, $in, $event) {
            $before = $this->audit->watch($event);
            // … append the correction row …
            $this->audit->record(
                action:  AttendanceAudit::MarkCorrected,
                subject: $event,
                changes: $before->diff(),
                reason:  $in->reason,                                          // required by the catalogue for this action
            );
        });
    }
}
```

Rules:

- **Every Action declares its audit action**, or carries `#[NotAudited('why')]`. An architecture test fails on an Action with neither.
- **Journey steps audit themselves.** The engine writes the line with the journey reference, step and outcome. Modules do not repeat it.
- **`Access::check()` audits refusals.** No module writes its own "denied" line.
- **Reads marked sensitive audit themselves** through the Query class (`#[AuditedRead(LearnerAudit::WelfareOpened)]`).
- **Events with no transaction** (failed sign-in, a refused request) are written directly. If the database is unreachable they go to a local spool file and are replayed in order; the gap is itself recorded.
- **The audit action catalogue** is declared per module, like permissions: enum case → sentence → whether a reason is required → field of the sentence that names the subject. `acadly:lint` fails on a case with no sentence or no translation.

## 8. Reading the trail

| Endpoint | Purpose |
|---|---|
| `GET …/audit` | Feed. Filters: module, action, outcome, actor, subject, learner, campus, session, channel, from, to, text |
| `GET …/audit/stats` | Counts over the same filters: lines, people, refused, failed, exports |
| `GET …/audit/subjects/{type}/{id}` | Everything about one record |
| `GET …/audit/learners/{id}` | Everything about one learner, reads included |
| `GET …/audit/sessions/{id}` | One sign-in, in order |
| `GET …/audit/export` | CSV of the filtered set. Writes `audit.exported` with the filters |
| `GET …/audit/seal` | Last seal, last verification, chain status |

- One query builder serves feed, stats and export, so the three can never disagree.
- Viewing needs `audit.view` at a scope. A teacher may see their own actions only. Opening the audit trail is an audited read.
- Each record page shows its own activity feed (the last lines for that subject) to those permitted.

## 9. Retention

- Lines are kept for the retention period of their class (an open product decision, PRD §20.11). Nothing is deleted inside that period by any screen or role.
- Old partitions are moved to cold storage together with their seals, and stay verifiable.
- Personal payloads follow their own, shorter rule (§4).

## 10. Screens

- **Audit trail** (console): seal status band, counts, filters, the ledger of lines, and a drawer with who / how / old → new / chain position.
- **Activity** on each record page.
- **My activity** for every staff member.
- **Sign-ins and devices** for administrators.
