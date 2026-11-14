# 12 — Search everything

One bar at the top of every console screen finds learners, guardians, staff, classes, applications, assessments, library resources, notices and screens. It shows a person only what that person may already see.

**Lineage:** the global search in eOffice IQ-X (`app.backend/app/Core/Search/`: `SearchSources`, `SearchSource`, `SearchHit`, `SearchQuery`; one source per add-on under `Addons/*/Search/`; the owner rule "every add-on is searchable, and search never leaks"). Acadly adopts that seam and its ten rules, and adds scope, typed keys and screens as results.

## 1. The seam

The kernel owns `Search`. It keeps a list of **sources** and knows nothing about what is behind each one. A module registers its sources in its manifest.

```php
interface SearchSource
{
    public function key(): SearchKey;                      // enum case, unique: 'learners'
    public function module(): string;                      // the owning module's key
    public function label(Vocabulary $words): string;      // "Students", "Pupils", "Children"
    public function available(Principal $who, Scope $scope): bool;
    /** At most $query->limit + 1 hits. The extra one only says there are more. */
    public function search(Principal $who, Scope $scope, SearchQuery $query): array;
}
```

```php
final readonly class SearchHit                 // built by hand, field by field
{
    public function __construct(
        public string $id,
        public string $kind,                   // 'learner', 'class', 'screen', …
        public string $title,                  // "Chikondi Banda"
        public RouteRef $opens,                // a typed route key + params, never a URL string
        public ?string $reference = null,      // "SA-2019-0412"
        public ?string $subtitle = null,       // "Form 2 East · Blantyre"
        public ?string $state = null,          // a word or two: "Active", "Under review"
        public ?string $day = null,            // Y-m-d, when the list row shows one
    ) {}
}
```

## 2. The rules (each one is pinned by a test)

Taken from eOffice:

1. **Every module ships its source on day one**, in its own namespace. A module with nothing to find is on a short exception list, with its reason. A test fails otherwise.
2. **Module off → the source is never asked.** Checked on every call.
3. **No permission → the group is ABSENT**, never an empty group. A module that is closed to you must look like one that is not installed. `available()` asks the same question as the module's own list screen.
4. **A source starts from the module's own scoped query**, the one its list screen uses. Never a raw table query.
5. **A source matches only fields that the list shows this reader.** Never matched, for anyone: dates of birth, addresses, welfare and medical notes, marks and grades, reasons, written notes, tokens. A hit on a hidden field says "a match exists", and that is the leak.
6. **A hit is a `SearchHit`.** Never `toArray()`, never a figure. A column added to a table next year cannot appear in search by accident.
7. **No counts of what is hidden. The typed text is written nowhere:** not to the log, not to the audit trail, not to analytics.
8. **Fast:** a trigram index on every matched column, in the module's own migration.
9. **Words** come from translation and from the division's vocabulary.
10. **Proof:** a feature test of the doors and the hidden fields, a browser test, and the reader's new place added to the **search walk** (§7).

Added by Acadly:

11. **Scope is part of every search.** The reader's institution, division and campus scope apply, and so do the relation rules: a class teacher finds the learners of their own classes; a campus officer finds their campus; an academic officer with the sight `learner.all_classes` finds the division.
12. **Phone numbers are matched only for readers whose list shows phones** (the office, never a subject teacher), and only as an exact or prefix match on the full number.
13. **Screens are results too.** A `ScreensSource` in the kernel answers from the navigation the reader already has: "Take the register", "New admission", "Roles and permissions". It finds nothing the reader cannot open.
14. **A source that fails or is slow is named, and the rest still answer.** Each source runs in its own transaction with a statement time limit (1.5 seconds). The response lists `failed` sources so the screen can say "Library did not answer".
15. **Opening a hit is an ordinary page open.** The page does its own access check and its own audit. Search grants nothing.
16. **The guardian and learner portal has no search over people.** Its search covers the family's own children, notices and the library only.

## 3. Matching and ranking

`SearchQuery` holds: the trimmed text, the words (lower case, accents and apostrophes folded, so "ngoma" finds "Ng'oma"), a safely escaped `ILIKE` pattern, and the limit.

- Minimum 2 characters. Maximum 80.
- Every word must match, in any order, **at the start of a word**. "ban" finds "Banda"; it does not find "Kabanda"… unless nothing better exists and trigram similarity is high (typing errors: "chikodi" still finds "Chikondi").
- Order inside a group: exact reference (student number, admission number) → name starts with the text → every word starts a word → close spelling. Then by name.
- Groups come back in a fixed, role-sensible order: Screens, Learners, Classes, Guardians, Staff, Admissions, Assessments, Library, Notices.
- The bar shows up to 5 hits per group. "See all" opens the search page for that one group (`?in=learners`), paged.

## 4. Storage and speed

- Each searchable table has a `search_text` column: lower case, unaccented, the list-visible fields only (for a learner: names, student number). It is a generated column or is kept by the Action that writes the row.
- Index: `GIN (search_text gin_trgm_ops)`, created by the migration helper `$table->searchable([...])`, which also records the table in the search linter's list. Reference columns keep a plain B-tree index for exact and prefix matches.
- Row-level security applies as on every query; the planner combines the tenant filter with the trigram index. Where a tenant is very large, the helper can build the index with `btree_gin` so `tenant_id` leads.
- Extensions used: `pg_trgm`, `unaccent`, optionally `btree_gin`. No external search engine in the MVP. The seam allows one later without changing any source's rules.
- Library content (the text inside a PDF or EPUB) is **not** searched in the MVP. Titles, authors, subjects and levels are.

## 5. API

| Endpoint | Answer |
|---|---|
| `GET /api/v1/institutions/{i}/search?q=&in=&limit=&cursor=` | `{ groups: [{ key, label, hits: [...], more }], failed: [{ key, label }] }` |
| `GET /api/v1/institutions/{i}/search/places` | The sources this reader may search, with their labels. Drives the hint under an empty bar |

- Rate limited per user. The client waits 200 ms after the last keystroke and cancels the earlier request.
- The scope selector (division, campus) of the page applies; the response says which scope was searched.

## 6. MVP sources

| Source | Module | Finds | Matched fields | Open to |
|---|---|---|---|---|
| `screens` | Kernel | Screens and actions | Screen names | Everyone, for their own screens |
| `learners` | Enrolment | Learners | Names, student number, Acadly ID | Whoever may list learners, within their classes, campus or division |
| `classes` | Academics | Classes, streams, subjects | Names, codes | Staff, within scope |
| `guardians` | Guardians | Guardians | Names; phone for the office only | `learner.manage` holders |
| `staff` | Staff | Staff | Names, staff number | Staff directory readers |
| `applications` | Admissions | Applications | Applicant name, application number | Admissions officers |
| `assessments` | Assessment | Assessments | Title, class, subject | The teacher's own; the academic office's all |
| `library` | Library | Resources | Title, author, subject, level | Whoever may discover the resource |
| `notices` | Communication | Announcements | Title | Their audience and their authors |
| `roles` | Kernel (Access) | Roles | Role names | `role.assign` holders |

The audit trail is not a source. It has its own filtered screen and its own permission.

## 7. Tests

- **A generated harness runs every registered source** against the rules: module off → not asked; no permission → absent; another tenant's rows → nothing; another campus's rows → nothing; each hidden field typed exactly → nothing; a hit has only `SearchHit` fields.
- **The search walk:** one test file lists, per kind of reader (class teacher, subject teacher, admissions officer, academic officer, librarian, head teacher, campus administrator, guardian, learner), exactly which groups they may search. It goes red when a place opens to somebody, so opening one is always a deliberate change.
- **Speed budget:** the 95th percentile of a search with all sources is under 400 ms on the pilot data volume.

## 8. The screen

- **The go bar** sits at the left of the top bar on every console screen (see `DESIGN.md`, Top bar). Help, notices, the theme switch and the identity block sit at the right. `Ctrl K` or `/` puts the cursor in it. `Esc` closes. Arrow keys move through hits; `Enter` opens.
- **Empty and focused:** it lists the reader's recent items (kept on the device only) and one line: what this reader can search.
- **Typing:** a ruled panel opens under the bar with one band per group, the group label and its hits. Each hit: the strong line, the reference in monospace, a subtitle, a word of state.
- **A closed group is not shown.** Nothing says "you cannot search guardians".
- **One plain line at the foot** says what search never looks inside: welfare notes, marks, dates of birth.
- **No results:** "Nothing found for 'x' in the places you can search." It does not hint that something exists elsewhere.
