# 01 — Foundations

## 1. Stack

| Layer | Choice | Why |
|---|---|---|
| Backend language | PHP 8.4+, `declare(strict_types=1)` everywhere | Owner's strongest stack. Enums, readonly classes and first-class callable syntax are enough to make the declarations typed |
| Backend framework | Laravel 13 (API only, no Blade UI, no Filament, no Livewire) | Mature queues, mail, storage, auth. Used as a toolbox, not as an architecture |
| Frontend | React 19 + TypeScript (strict), Vite, pnpm workspace | Full control of the design. One contract with the API |
| Database | PostgreSQL 17+ | Row-level security, partial indexes, JSONB, exclusion constraints |
| Cache / queue | Redis + Laravel Horizon | Queues, rate limits, short-lived tokens |
| Files | S3-compatible private bucket | Library files, documents. Never public |
| Realtime | Laravel Reverb, only where a screen truly needs it (pickup queue) | Not a default |
| API contract | OpenAPI 3.1 generated from the code; TypeScript client generated from OpenAPI | One source of truth for both sides |

Python and C# stay out of the core. If a later service needs them (OCR, analytics), it lives behind the Integration module and an HTTP contract.

Pin exact versions in `composer.json` and `package.json` at scaffold time. Record them in `DECISIONS.md`.

## 2. Repository layout

One repository. Two deployables. One contract between them.

```
acadly-iq-x/
├── api/                          Laravel application (API only)
│   ├── app/                      Thin shell: bootstrap, providers, HTTP kernel. No business logic
│   ├── kernel/                   Namespace Acadly\Kernel\…   (shared machinery)
│   ├── core/                     Namespace Acadly\Core\…     (always-on domains)
│   ├── modules/                  Namespace Acadly\Modules\…  (optional domains)
│   ├── blueprints/               Declarations: profiles/ and lock file (see 02)
│   ├── tests/                    Architecture, isolation and cross-module tests
│   └── …
├── web/                          pnpm workspace (see 06)
│   ├── apps/console · apps/portal · apps/platform
│   └── packages/ui · packages/api-client · packages/kernel
├── contracts/
│   └── openapi.json              Generated. Committed. CI fails if it drifts
├── docs/
│   ├── product/                  PRD and concept (inputs)
│   └── architecture/             This folder (the law)
├── infra/                        Docker, Nginx, CI workflows
└── CLAUDE.md                     Short rule sheet for AI and human contributors
```

## 3. Three layers

```
MODULES   may import → Core Contracts, Kernel, other modules' Contracts (declared dependencies only)
CORE      may import → Kernel, other Core Contracts
KERNEL    may import → Laravel, PHP. Nothing else
```

- **Kernel** is machinery. It has no idea what a learner, a class or a school level is. If the word `Learner`, `Ecd` or `Attendance` appears under `kernel/`, the build fails.
- **Core** is the set of domains that every institution needs. They cannot be switched off.
- **Modules** are capabilities an institution may or may not have. A primary school is never forced to load, see or pay for a module it does not need.

### Kernel components

| Component | Owns |
|---|---|
| `Tenancy` | Scope value object, scope resolution, tenant-aware base model, RLS session variables |
| `Identity` | Users (login accounts), sessions, MFA, password reset, `IdentityProvider` socket for SSO |
| `Access` | Permission catalogue, roles, scoped role assignments, the `Access::check()` pipeline, entitlements |
| `Blueprint` | Profile and Journey definition classes, registries, the Journey engine, linter, conformance suite |
| `Notify` | Topic catalogue, audience resolvers registry, dispatcher, channel adapters, delivery ledger |
| `Audit` | Append-only audit events |
| `Events` | Domain event base class, transactional outbox, idempotent consumers |
| `Files` | Private storage, signed short-lived access, upload validation |
| `Module` | Module manifest contract, module registry, boot order |
| `Support` | Ids (UUIDv7), Money, Clock, Result types, idempotency keys |

### Core domains

| Domain | Owns |
|---|---|
| `Institution` | Tenants, institutions, divisions, campuses, settings, academic terminology overrides |
| `People` | The person record (one human, once per tenant), contact points |
| `Academics` | Academic years, periods, levels, classes/streams/cohorts, subjects/courses, teaching assignments |
| `Enrolment` | Learners, learner identifiers, enrolments, status history |
| `Guardians` | Guardian profiles, learner–guardian relationships, verification, access scope |
| `Staff` | Staff profiles, duty assignments |

## 4. Module anatomy

Every Core domain and every Module has the same shape. Learn it once.

```
modules/Attendance/
├── AttendanceModule.php       The manifest (see §5)
├── Contracts/                 PUBLIC. Interfaces, DTOs, events, enums others may use
├── Domain/                    Models, value objects, enums, domain rules. PRIVATE
├── Application/
│   ├── Actions/               One class per write use case. The only writers
│   └── Queries/               One class per read use case
├── Http/
│   ├── Controllers/           Thin. Validate → call Action/Query → return Resource
│   ├── Requests/ · Resources/
│   └── routes.php
├── Journeys/                  Journey blueprints, gates, effects owned by this module
├── Notifications/             Topic classes and templates owned by this module
├── Infrastructure/            Jobs, adapters, repositories, listeners
├── Database/                  migrations/ · factories/ · seeders/
└── Tests/                     Unit and feature tests for this module
```

Rules of the shape:

- **`Contracts/` is the only door.** Another module may import `Acadly\Modules\Attendance\Contracts\*` and nothing else.
- **Actions are the only writers.** Controllers, jobs, listeners and console commands call Actions. They do not write models directly.
- **No business logic in model events.** No `saved`, `created`, `updating` hooks that do domain work. (This was the worst habit found in MCRS.)
- **No Eloquent relation crosses a module boundary.** A module stores the foreign key and asks the owning module through its Contracts. The database foreign key constraint still exists.
- **A module owns its tables.** Table names carry the module prefix: `attendance_sessions`, `library_resources`.

## 5. The module manifest

One class per module. It is a declaration, read by the kernel at boot and by the linters in CI.

```php
final class AttendanceModule implements Module
{
    public function key(): string { return 'attendance'; }

    public function tier(): Tier { return Tier::Optional; }

    /** Modules that must be enabled for this one to work. */
    public function requires(): array
    {
        return [AcademicsCore::class, EnrolmentCore::class, StaffCore::class];
    }

    /** Typed slots a Profile Blueprint must bind before this module can run. */
    public function bindingSlots(): array { return AttendanceSlot::cases(); }

    public function permissions(): array { return AttendancePermission::cases(); }

    public function topics(): array      { return [LearnerMarkedAbsent::class, AttendanceCorrected::class]; }

    public function journeys(): array    { return ['attendance.correction']; }

    /** Places a person can search (document 12). Every module ships at least one, or states why not. */
    public function searchSources(): array { return [AttendanceSessionsSource::class]; }

    /** Audit actions this module writes (document 10). */
    public function auditActions(): array { return AttendanceAudit::cases(); }

    public function settings(): string   { return AttendanceSettings::class; }

    /** Navigation entries, each guarded by a permission. Sent to the SPA. */
    public function navigation(): array
    {
        return [Nav::item('attendance.register', AttendancePermission::Capture)];
    }
}
```

From the manifests the kernel derives: the permission catalogue, the notification catalogue, the navigation payload, the route guards, the dependency graph and the entitlement list. None of those is typed by hand anywhere else.

## 6. When is a module "on"?

A module is active for a request only when **all four** are true:

```
installed            the code is in the build
∧ offered            the division's Profile Blueprint lists it as required or allowed
∧ entitled           the tenant's subscription includes it
∧ enabled            the institution administrator switched it on (required modules are always on)
```

The check runs server-side on every route of the module (`module:attendance` middleware) and inside `Access::check()`. Hiding a menu item is never the control.

## 7. Module catalogue

R = required by the profile, O = optional, – = not offered. The Profile Blueprints (document 02) are the source of truth; this table is a picture of them.

| Module | Tier | ECD | Primary | Secondary | Tertiary | Notes |
|---|---|:-:|:-:|:-:|:-:|---|
| Institution, People, Academics, Enrolment, Staff | Core | R | R | R | R | Always on |
| Guardians | Core | R | R | R | R | Tertiary binds it as "sponsor / next of kin", not as a gatekeeper |
| Admissions | Optional | R | R | R | R | Different journey per profile |
| Attendance | Optional | R | R | R | O | Mode is a binding: daily, daily + subject, per session |
| Assessment | Optional | O | R | R | R | Model is a binding: observational, marks, credit / GPA |
| Communication | Optional | R | R | R | R | Announcements |
| Portal | Optional | R | R | R | R | Guardian and learner self-service |
| Reporting | Optional | R | R | R | R | Read models only |
| Library | Optional | O | O | O | O | EduLibrary |
| DataImport | Optional | O | O | O | O | CSV templates |
| SafeRelease | Optional | O | O | – | – | Child collection and release |
| Programmes | Optional | – | – | – | R | Departments, programmes, course catalogue, course registration |
| Fees · Timetable · Transport · Hostel · StrideBridge · Insights | Later | | | | | Slots reserved. Not in MVP |

**Shared, not copied.** Attendance is one module used by four profiles. The differences are bindings (document 02 §3), never a fork and never an `if ($level === …)`.

## 8. How modules talk

| Need | Mechanism |
|---|---|
| Ask another module a question, now | Call an interface in its `Contracts/` (for example `EnrolmentDirectory::activeLearnersIn($classId)`) |
| React to something that happened | Subscribe to a domain event from its `Contracts/Events/` (delivered through the outbox, after commit) |
| Need another module's data in a report | The Reporting module reads published read models. It never writes |
| Need a new capability from another module | Add it to that module's Contracts. Do not reach around |

Dependencies must form a directed acyclic graph. A cycle means a concept is in the wrong module; move it down a layer.

## 9. Adding a module — the checklist

1. Create the folder with the standard shape.
2. Write the manifest. Declare dependencies, slots, permissions, topics, journeys.
3. Add the module to the Profile Blueprints that offer it (new blueprint version if any division is already pinned).
4. Migrations with `tenant_id` and RLS policy (document 05).
5. Actions, Queries, thin controllers, routes under the `module:` guard.
6. Tests: unit, feature, isolation (generated), journey conformance (generated).
7. Frontend module folder with the same key (document 06).
8. Run `php artisan acadly:lint`. It must pass.
