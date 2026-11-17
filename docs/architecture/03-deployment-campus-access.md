# 03 — Deployment, campus and access

## 1. One deployment per school

**Each school runs its own Acadly.** A deployment is one copy of the application, one database, one file store and one address, for one school. Nothing is shared between two schools at run time: not a table, not a login, not a file.

This replaces the shared multi-tenant model of the first draft and of the PRD (§4). It is decision D-030.

```
Deployment (one school: its own application, database, storage, address)
└── Institution             the school. Exactly one row. Name, brand, calendar, licence
    ├── Division            an education level run by the school. Pins a Profile Blueprint
    │     e.g. "Nursery" (ECD@…), "Primary" (PRIMARY@…), "Secondary" (SECONDARY@…)
    └── Campus              a physical or administrative site
          e.g. "Area 47 Campus", "Blantyre Campus"

    Division × Campus = Offering   "Primary is taught at Area 47 and at Blantyre"
```

### What follows from it

| Topic | Rule |
|---|---|
| Isolation between schools | Physical. A separate database per school. There is no `tenant_id` column and no tenant row-level security |
| The institution | A single row (`institution`). The application has no screen to create a second one |
| A school group | A group that is one school with several levels and sites is **one** deployment (divisions and campuses). Two legally separate schools are two deployments |
| Accounts | Local to the deployment. A parent with children in two different schools has two accounts |
| IQ-X operators | Have no application of their own inside a deployment and no standing account. Support access is a time-limited grant (§5.6) |
| Modules and limits | Set by a signed licence installed in the deployment (§5.5) |
| Releases | Each deployment runs a stated release. Blueprint versions ship with the release |
| Learner moving to another school | An export from one deployment and an import into the other. Later stage |

### Why Division exists

One school commonly runs nursery, primary and secondary under one name, one head office and one set of parents. Each level works differently.

- The **Profile Blueprint is pinned on the Division**, not on the Institution.
- A single-level school has exactly one division. It is created automatically and the screens do not show the concept.
- Division and Campus are **independent axes**. Neither is the parent of the other. `division_campus` (the Offering) says which levels run where.

How a learner moves between divisions and campuses is in document 09.

### What lives at which level

| Level | Examples |
|---|---|
| Institution | Licence, people, guardians, users, academic years, grading scales, announcements, library catalogue, staff profiles |
| Division | Level ladder, subjects, admission journey, assessment model, vocabulary |
| Campus | Rooms, campus calendar overrides (local holidays), devices |
| Division × Campus | Classes/streams, enrolments, attendance sessions, teaching assignments |

## 2. Multi-campus rules

1. **Campus is a dimension on records, not a silo.** One learner record, one guardian record, one staff record. Campus appears on the enrolment, the class, the session.
2. **Three kinds of record:**
   - *Institution-wide* — no `campus_id` (grading scale, subject, academic year).
   - *Campus-bound* — `campus_id` is `NOT NULL` (class, attendance session, room).
   - *Campus-portable* — the person is institution-wide; their placement carries the campus (learner → placement, staff → assignment).
3. **A campus transfer is a new placement row, not an edit** (document 09). The enrolment continues. History stays.
4. **Roles can be scoped to a campus.** A campus administrator sees only campus-bound rows of their campus plus the institution-wide reference data needed to work.
5. **Reports roll up.** Every campus-bound fact table is indexed by `(division_id, campus_id, …)` so the same query answers "this campus" and "all campuses".
6. **The calendar is institution-level with campus overrides.** No per-campus copy of the academic year.
7. **A single-campus school** has one campus, created automatically. No special code path.

## 3. Protection inside a deployment

There is no wall between schools to build, because there is one school. What remains is protection of the school's own data.

### Two database roles

| Role | Used by | May |
|---|---|---|
| `acadly_owner` | Migrations only | Create and alter tables. Owns them |
| `acadly_app` | The running application | Read and write rows. Cannot change the schema. Is not a superuser and cannot bypass row security |

Migrations run with `php artisan migrate --database=pgsql_owner`. The application never serves a request on the owner connection.

### Append-only tables

History tables (audit, attendance events, journey step completions, effect applications) are protected by `AppendOnly::protect($table)` in their migration: the application role loses `UPDATE`, `DELETE` and `TRUNCATE`, and a trigger refuses those statements for every role, the owner included.

### Scope inside the school

A user works within a scope: the whole institution, one division, one campus, one division at one campus (an offering), or their own classes. Scope is resolved on the server from the user's role assignments. An identifier sent by a client is only a selector; it is checked before use. Every list and every record access applies the scope (§5).

### Proof

- Tests run against PostgreSQL as `acadly_app`. They prove the role cannot change the schema and cannot change an append-only row, and that the owner role is refused by the trigger too.
- A generated test walks every route with a user scoped to campus A and resources of campus B. Every read and write must return 404 or 403, with a full body and with an empty one, so the refusal comes before validation. A route that may answer such a user is listed in the test with its reason (`tests/Feature/CrossScopeTest.php`).

## 4. Identity

Three different things. Never merge them.

| Concept | Table | Owned by | Meaning |
|---|---|---|---|
| **User** | `users` | The deployment | A login account. Email/phone, password hash, MFA |
| **Person** | `people` | The deployment | A human known to this school. Names, date of birth, contacts |
| **Role record** | `learners`, `guardians`, `staff_profiles` | The deployment | What that person *is* here. One person can be a guardian and a staff member |

- `users.person_id` links a login to a person. A parent with children in two different schools has an account in each school's deployment.
- A person may exist with **no user** (most young learners; guardians who never activate).
- Guardian access is never inferred from surname, address or phone. It exists only as a `learner_guardian_relationships` row that went through the `enrolment.guardian_link` journey and that the parent confirmed (D-082). A phone number lets a parent sign in; it never gives access by itself.
- Parents and guardians sign in with the phone number the school keeps and a code sent by SMS (D-082).

### Sign-in

- First-party SPA: cookie session (HttpOnly, Secure, SameSite) with CSRF protection. No tokens in browser storage.
- Mobile and integrations (later): scoped API tokens.
- MFA: required for institution-administrator roles. Policy configurable for other staff.
- **SSO socket:** `IdentityProvider` contract in the kernel. `LocalPasswordProvider` ships first. IQ-X SSO and institution OIDC plug in later without touching modules (D-003).

### Learner identifier

- Primary key: UUIDv7 (internal).
- **Acadly Learner ID:** human-readable, random, with a check character, for example `AL-7K3M-92QF`. Stable for life. Never encodes school, year or campus.
- Institution admission/student numbers live in `learner_identifiers (learner_id, institution_id, kind, value)`.

## 5. Access control

### 5.1 The pipeline

Every protected operation goes through one function. Deny by default.

```
Access::check(principal, permission, target)

  1. Authenticated and account active?
  2. Scope: is the target inside a scope where the principal holds a role?
  3. Module: is the owning module active for that division?          (01 §6)
  4. Permission: does one of those roles grant it?
  5. Relation: does the record-level rule hold?                        (teacher ↔ class, guardian ↔ learner, self)
  6. Support access: if the principal is a support operator, is there a live, approved grant?
```

Journey gates are **not** in this list. "A fee is unpaid" or "the period is closed" is admissibility, not permission (02 §3.2).

### 5.2 Permissions

- Declared as PHP enums in each module's `Contracts/` and listed in its manifest: `AttendancePermission::Capture` → `attendance.capture`.
- Synced to `permissions` at deploy. The set in the database must equal the set in code; a mismatch fails the readiness check.
- Controllers and Actions reference the enum case. A permission string literal in code fails lint.

### 5.3 Roles and assignments

```
roles              (id, is_system, key, name)
role_permissions   (role_id, permission_key)
role_assignments   (id, user_id, role_id,
                    scope_type, scope_id,           -- institution | division | campus | offering | class
                    starts_at, ends_at, granted_by)
```

- A role is never global. It is always held **at a scope**. Wider scope covers narrower: the institution covers everything; a division and a campus each cover the offerings that join them; an offering covers its classes.
- **A role is a name. Its permissions are its power.** No code tests the name of a role: it asks whether a permission is held at a scope. The one system role is found by its key only to keep its permissions complete and to make the first administrator; `acadly:lint` refuses any other use.
- System roles come from the PRD (§5.2) and from Profile Blueprint role templates. A school may clone and adjust roles; it may never exceed the catalogue.
- No one can grant a permission they do not hold, or a scope wider than their own.
- Time-bound assignments (`ends_at`) serve temporary staff and support access.
- There is no generic `staff` role.

### 5.4 Relation rules

Small classes registered by modules, evaluated at step 5:

| Rule | Meaning |
|---|---|
| `TeachesClass` | Staff member has a current teaching assignment for the class/subject |
| `GuardianOf` | Active, approved relationship to the learner, and the relationship's information scope includes this data |
| `IsSelf` | The learner record belongs to the principal |
| `OwnActionsOnly` | For limited audit views |

Revoking a guardian relationship takes effect on the next request. Cached access decisions are keyed by a per-user version counter that the revocation bumps.

### 5.5 Entitlements

- A **signed licence** is installed in each deployment: the modules it includes, limits (learners, campuses, SMS allowance), and an expiry date. IQ-X signs it; the application verifies the signature and cannot be edited into a larger licence.
- Checked at step 3, separately from permissions (PRD §5.4 rule 10).
- An expired or suspended licence resolves to a restricted mode: read and export only. No data is hidden from the school.

### 5.6 IQ-X support access

- IQ-X has no standing account in a school's deployment.
- Access needs a `support_grants` row, approved by the school's administrator: reason, approver, expiry. Every request made under it is audited with the grant id. It can be revoked at once.

### 5.7 Rules taken from eOffice IQ-X

Studied in `e-office-iq-x` (`app.backend/app/Core/Auth/PermissionsCatalog.php`, `app.frontend/src/admin/RolesPage.tsx`, its `CLAUDE.md`). These are adopted as Acadly rules.

**The catalogue**

1. **One catalogue is the source of truth:** module → code → one plain sentence that says what the code unlocks. In Acadly the sentence lives on the enum case (`AttendancePermission::Correct->help()`), and the roles screen prints it.
2. **Few codes per module.** A module's permission list is a small vocabulary (view, manage, approve, and the sights). A new code needs a reason that an existing code cannot carry. Exporting what a screen already shows needs no code of its own.
3. **Separation of duties gets its own code.** The person who prepares must not be the person who approves: `result.enter` and `result.publish`, `transfer.request` and `transfer.accept`. One tick box must never hand over both by accident.
4. **A code of a switched-off module stays in the catalogue.** Codes are pruned only when they leave the code base, never when a module is switched off; otherwise a school's role edits are deleted when the module goes off and reset when it returns. The roles screen hides codes of modules the institution does not have.

**Function, sight, and law**

5. **A permission is a FUNCTION** (may you do this kind of work). **What a person may SEE of one record is law in code** (the relation rules of §5.4), never a tick box. No role can grant a guardian another family's child or a teacher another teacher's class.
6. **The few codes that widen sight say so in capitals** and name who should hold them: `learner.all_classes` — "SEES EVERY LEARNER in the division, in any class. Grant to the academic office only." `learner.welfare` — "SEES MEDICAL AND WELFARE NOTES. Every opening is audited." A sight is never part of a default bundle; a person hands it over by hand.
7. **A person reads their own record with no permission.** A teacher's own classes, a learner's own results, a guardian's linked children need no code.
8. **Some authority comes from the assignment, not from a code.** Being the class teacher of Form 2 East comes from the teaching assignment. The code says "may take registers"; the assignment says "of which class".

**Roles**

9. **Roles are bundles a school may edit.** System roles are locked (padlock). A role that somebody still holds cannot be removed. There is no silent removal of permissions from people.
10. **Defaults are laid down only while a role holds nothing**, so a school's edits survive every sync and upgrade.
11. **A new permission must reach somebody.** eOffice learned this the hard way: a code added in a new version reached nobody on an existing install, and its own admin screen was unreachable. Acadly rule: every new enum case declares `grantTo()` (for example "whoever already holds `institution.configure`"). `acadly:sync` applies it once and records it in `permission_grants_applied`. `acadly:lint` fails on a new code with no grant rule.
12. **Acting and cover are assignments with an end date and a reason** (`role_assignments.ends_at`, `acting_for`, `reason`, `granted_by`). A substitute teacher gets the absent teacher's classes for the stated days and loses them without anyone remembering to undo it.
13. **Every change to a role or an assignment is audited:** `rbac.role_created`, `rbac.role_renamed`, `rbac.permission_changed`, `rbac.assignment_granted`, `rbac.assignment_ended`.

**The interface**

14. **The SPA gates on `can(code)` only.** A search of `app.frontend/` for a role-name comparison must return nothing; CI runs it.
15. **A refusal says it is a refusal.** A screen the user may not open says so in words. It never shows a loading state for ever.
16. **The roles screen is a matrix:** permissions down the side, grouped by module, each with its name, its sentence and its code; roles across the top, each with its holder count and how much of the matrix it covers; frozen row and column heads; a padlock on system roles. Every code must have a translated name, or `acadly:lint` fails (eOffice shipped nineteen codes that printed raw).

**What Acadly does differently**

| eOffice | Acadly | Why |
|---|---|---|
| A role is held globally | A role is held at a scope: institution, division, campus or class | Several levels and campuses in one school |
| Codes are strings (`can:registry.files`) | Codes are enum cases; a string literal fails lint | Typos fail in the editor |
| Defaults keyed by role slug in one constant | Role templates come from the Profile Blueprint | A nursery and a college need different starting roles |
| One catalogue file | Each module declares its own codes | Modules stay sealed |
| A hand-written migration grants each new code | `grantTo()` on the code, applied by sync | The rule cannot be forgotten |

### 5.8 What the frontend receives

`GET /api/v1/me/context` returns the institution, its divisions and campuses, the active modules, the vocabulary, the user's permissions per scope and the navigation. The SPA builds itself from this. It still treats every API 403 as the truth.
