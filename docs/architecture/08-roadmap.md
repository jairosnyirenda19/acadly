# 08 — Roadmap for the backbone

Order matters. Each stage ends with something that runs and is tested. No stage starts until the gates of the one before are green.

The target of stages 0–4 is the PRD's vertical slice (§21): institution → class → learner and guardian → attendance → result → library → proof that an outsider cannot see any of it.

## Stage 0 — Walking skeleton

> **Built on 14 November 2026.** API: 13 tests, Pint, PHPStan max, `acadly:lint`, contract export. Web: generated client, type check, ESLint, 4 token tests, three app builds. Proven: a kernel file that used a module failed the layer test and the lint; a raw colour and a raw `fetch` failed ESLint. Not yet done: the first commit, a run of the CI file on GitHub, and Docker (not installed on the first developer machine; the API uses SQLite until stage 1 moves it to PostgreSQL).

- Monorepo: `app.backend/`, `app.frontend/`, `contracts/`, `infra/`, Docker Compose (PostgreSQL, Redis, object storage, mail catcher).
- Laravel shell with the three namespaces (`Kernel`, `Core`, `Modules`) and module auto-discovery.
- pnpm workspace with the three apps and four packages; design tokens and the first primitives.
- CI with every gate from document 07, even while most have nothing to check.
- One health endpoint, called from the console app through the generated client.

**Exit:** a pull request that breaks a layering rule fails CI.

## Stage 1 — Kernel

1. `Support`: Ids (UUIDv7), Clock, Money, idempotency keys.
2. `Database` and `Scope`: owner and application roles, `AppendOnly::protect()`, the Scope value object, the cross-scope test generator. *(Roles and append-only protection: built 14 November 2026.)*
3. `Identity`: users, sessions, password reset, MFA, `IdentityProvider` socket. *(Built 15 November 2026: accounts, sign-in, sign-out, password reset, `/me`. Sign-in screens for the console and the portal built the same day. Not yet: two-step sign-in, the `IdentityProvider` socket.)*
4. `Access`: permission catalogue sync, roles, scoped assignments, `Access::check()`, entitlements, support grants. *(Built 15 November 2026: catalogue, sync, roles, scoped assignments with periods, `Access::check()`, assignment guard rails, first-administrator command. Not yet: record-level rules, the licence, support grants, audit of access changes.)*
5. `Audit` and `Events` (outbox, idempotent consumers). *(Audit write side built 16 November 2026: action catalogue, hash chain, encrypted personal payloads, field classes and diffs, refusals, sign-in and access lines, `audit:verify`, nightly `audit:seal`. Not yet: the read endpoints and screens, sensitive-read auditing, watchers, the spool file, partitioning, erasure. Events built the same day: outbox, publisher, at-most-once delivery per consumer, role events. Not yet: webhooks.)*
6. `Module`: manifest contract, registry, activation check, `/me/context`. *(Built 16 November 2026: activation check with the offering socket, signed licence, switches with history, `module:` route guard, read-only mode, `/me/context`, web context hooks. Not yet: per-division switching, navigation entries, the modules screen.)*
7. `Blueprint`: definitions, compiler, lock file, linter, journey engine, conformance suite, projections.
8. `Notify`: topic contract, compiled catalogue, audiences, dispatcher, in-app + email + SMS adapters (SMS against a fake provider first), delivery ledger, sync + readiness hash.
9. `Files`: private storage, upload validation, signed access.

**Exit:** a toy module with one journey and one topic passes isolation, conformance and catalogue tests.

## Stage 2 — Core domains and the first two profiles

- `Institution`: the institution row, licence, divisions, campuses, offerings, settings.
- `People`, `Staff`: persons, invitations, activation, duty assignments.
- `Academics`: years, periods, levels, classes/streams, subjects, teaching assignments — driven by the profile's `structure`.
- Profile Blueprints: `SCHOOL_BASE`, `PRIMARY@…`, `SECONDARY@…`.
- Console: onboarding wizard, scope switcher (institution / division / campus), user and role management.

**Exit:** PRD scenario A (institution setup) passes end to end for a two-campus school with a primary and a secondary division, and the cross-scope tests pass.

## Stage 3 — Learners and families

- `Enrolment`: learners, Acadly Learner ID, identifiers, enrolments, **placements** (the timeline, document 09), duplicate flags.
- `learner.campus_transfer` and `learner.exit` journeys. (`learner.progression` lands in stage 4, when results exist to gate it.)
- `Admissions`: `admission.school` journey, idempotent conversion to learner and enrolment.
- `Guardians`: relationships and the `guardian.link` journey.
- `Portal` app: sign-in, learner switcher, profile and enrolment view.

**Exit:** PRD scenarios B (admission) and C (guardian access).

## Stage 4 — Daily operations

- `Attendance`: sessions, append-only events, correction journey, summaries, the register screen.
- `Assessment`: categories, grading scales, marks entry windows, `results.termly` journey, publication snapshots.
- `learner.progression`: year-end run, promotion, repeat, division progression with intake, completion.
- `Communication`: announcements with audiences.
- Notification topics for absence, results and announcements; preferences screens.
- `Reporting`: the MVP report list (PRD §14) as read models with logged exports.

**Exit:** PRD scenarios D, E, F. A pilot school can run a term.

## Stage 5 — Library

- Catalogue, rights metadata, private files, access rules, collections, assignments.
- Reader spike (PDF.js; Readium Web vs epub.js), then `ReaderAdapter`, progress and bookmarks.
- `library.resource` journey; access-event ledger.

**Exit:** PRD scenario G.

## Stage 6 — Widening

- `ECD@…` profile, SafeRelease module (after safeguarding review).
- `TERTIARY@…` profile and the Programmes module.
- DataImport, offline attendance sync, push channel.
- Later modules take their reserved slots: Fees, Timetable, Transport, Hostel, StrideBridge, Insights.

## What is deliberately not built early

- School-defined roles and custom fields (the model allows them; the UI waits for real demand).
- Webhooks and public API keys (the outbox is ready for them).
- Fleet tooling for IQ-X: provisioning a new school, rolling out a release to many deployments, licence issue. It lives outside the application.
- Any predictive analytics.
