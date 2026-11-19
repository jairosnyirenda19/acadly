# Acadly IQ-X — contributor rule sheet

The full law is in `docs/architecture/`. Read `README.md` there first. This file is the short version.

## What this is

Education platform (ECD, primary, secondary, tertiary). **One deployment per school**: its own application, database and storage. There is no tenant column. Laravel API-only modular monolith in `app.backend/`. React + TypeScript SPA workspace in `app.frontend/`. PostgreSQL. UUIDv7 keys. No Filament, no Livewire, no Inertia.

## Rules you must not break

1. **Layers:** Modules → Core → Kernel. Never the other way. Kernel names no module and no education level.
2. **Sealed modules:** import another module only through its `Contracts/`. No cross-module Eloquent relations.
3. **Actions are the only writers.** Controllers, jobs and listeners call Actions. No business logic in model events.
4. **Never branch on education level or profile.** Declare a binding slot in the module and bind it in the Profile Blueprint.
5. **Multi-state processes are Journeys.** State changes only through `JourneyEngine::perform()`. Gates are facts, not permissions. Outcomes are named, not boolean.
6. **Released blueprint versions are immutable.** Make a new version.
7. **The application runs as `acadly_app`**, which cannot change the schema. Migrations run as `acadly_owner`. Scope (division, campus, class) is resolved on the server.
8. **All access goes through `Access::check()`** with a permission enum case. No string permissions. No role-name checks.
9. **History is append-only.** Corrections are new rows.
10. **Notifications:** `Notifier::send(new Topic(...))`. Never pass recipients or channels at the call site.
11. **External I/O after commit**, on the queue, idempotent.
12. **Frontend:** generated API client only. Check permissions, modules and bindings — never roles, levels or state literals. No string literals for user-facing text. Semantic tokens only.

13. **The learner record holds everything about a learner.** A module that keeps anything about a learner adds its part: implement `LearnerRecordPart` (Enrolment Contracts), tag it, and give the record screen its tab. A test fails the build when a module that tells families about a learner has none.

14. **Divisions and campuses are walls on top of the roles (D-099).** A request works in one place (the `X-Acadly-Place` header); `Access::allows()` refuses anything outside it. A list behind a whole-school check must still keep to the place, record by record. A new route is covered by `BoundaryTest`: it fails the build on any leak. A division has only the parts its profile lists.

15. **Dashboards and Today are built from the modules (D-100).** A module adds its charts through `DashboardPanels` and its to-do items through `TodayItems`, for the scope it is given: counts only, never a learner named, and every division apart. The school view shows records only in the overview's read-only lists. Charts use the chart tokens, never teal, marigold or red as a series.

## Design

- `PRODUCT.md` says who the product is for and how it speaks. `DESIGN.md` is the visual law ("The Register Book"). Read both before any UI work.
- Radius 0. No shadows. Two rule weights. Teal means done, marigold means attention required, red means absent, blocked or wrong. A state always has a mark or a word as well as a colour.
- A new screen is designed on the design canvas and approved before it is coded.
- **Wording:** professional, clean terms on serious matters (status, security, access, audit, results, attendance, safeguarding, errors). No conversational phrasing. See `PRODUCT.md`, Brand Commitments.
- **Disclose nothing about the build:** no framework, language, server, file path, module list, release identifier or developer command in any screen, response, header or error message.

## Before opening a pull request

```
cd app.backend && php artisan acadly:lint && vendor/bin/pint --test && vendor/bin/phpstan && php artisan test
cd app.frontend && pnpm lint && pnpm typecheck && pnpm test
```

## When unsure

- New capability → is it a new module, or a binding on an existing one? See `01-foundations.md` §7 and `02-blueprints.md` §2.4.
- New process → is it a Journey? See `02-blueprints.md` §3.
- New table → `05-data-and-api.md` §2.
- An architectural choice → add a row to `docs/architecture/DECISIONS.md` in the same pull request.
