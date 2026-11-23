<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\Fixtures\RouteRules;
use Tests\Fixtures\TwoCampusSchool;

/*
 * The boundary test (D-099). Divisions and campuses are walls on top of the
 * roles. It walks every route of the API with the strongest account there
 * is, one that holds every permission for the whole school, working inside
 * Primary, and points each route at a learner, a class and a term of
 * Secondary. Every route must refuse: 403 or 404. Every list must answer
 * without a word of Secondary. In the school view, no list may show any
 * learner of any division.
 *
 * A new route is covered the moment it is added.
 */

uses(DatabaseTransactions::class);

/** @return list<Route> */
function boundaryRoutes(): array
{
    return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/')));
}

/** The words around a leak, so a failure shows what came back. */
function leakAround(string $content, string $word): string
{
    $at = strpos($content, $word);

    return $at === false ? '' : substr($content, max(0, $at - 160), 320);
}

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['attendance', 'assessment', 'fees', 'conduct', 'support', 'saferelease', 'programmes', 'lectures', 'classroom'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    }
    app()->forgetScopedInstances();
    $this->school = $school = TwoCampusSchool::make();

    // The strongest account: every permission, for the whole school.
    $everything = Role::query()->create(['key' => 'everything_school', 'name' => 'Everything']);
    $everything->grant(array_values(app(PermissionCatalogue::class)->all()));
    $this->owner = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->owner->id, 'role_id' => $everything->id, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);

    // A member of staff in Secondary only.
    $this->clerk = Role::query()->create(['key' => 'clerk_secondary', 'name' => 'Clerk']);
    $this->atSecondary = User::factory()->create(['name' => 'Zangaphee Kachingwe', 'email' => 'zangaphee.kachingwe@example.test']);
    $this->held = RoleAssignment::query()->create(['user_id' => $this->atSecondary->id, 'role_id' => $this->clerk->id, 'scope_type' => ScopeType::ClassGroup,
        'scope_id' => $school->id('class_secondary_b'), 'starts_at' => now()->subMinute()]);

    // Learners: one in Primary (inside), one in Secondary (outside).
    $this->learner = fn (string $first, string $surname, string $code, string $division, string $campus, string $level, string $class, string $phone): string => tap(Ids::next(), function (string $id) use ($first, $surname, $code, $division, $campus, $level, $class, $phone, $school): void {
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $id, 'acadly_id' => $code, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2012-01-01',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $id, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $id, 'year_id' => $school->id('year'), 'division_id' => $school->id($division),
            'campus_id' => $school->id($campus), 'level_code' => $level, 'class_id' => $school->id($class), 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => now()]);
        $this->contacts[$id] = Ids::next();
        DB::table('learner_contacts')->insert(['id' => $this->contacts[$id], 'learner_id' => $id, 'name' => "{$first} {$surname} Parent", 'relationship' => 'mother',
            'phone' => $phone, 'position' => 1, 'added_at' => now()]);
    });
    $this->contacts = [];
    $this->inside = ($this->learner)('Alinafe', 'Insider', 'AL-1111-1111', 'primary', 'campus_a', 'STD1', 'class_primary_a', '0999 111 111');
    $this->outside = ($this->learner)('Zikomo', 'Outsider', 'AL-9999-9999', 'secondary', 'campus_b', 'F1', 'class_secondary_b', '0999 999 999');

    // A lesson, a subject, a term and an assessment with a mark, all in Secondary.
    $this->slot = Ids::next();
    DB::table('academic_bell_plans')->insert(['id' => Ids::next(), 'division_id' => $school->id('secondary'), 'campus_id' => $school->id('campus_b'),
        'name' => 'Every day', 'weekdays' => json_encode([1, 2, 3, 4, 5, 6, 7]), 'periods' => json_encode([['name' => 'Period 1', 'starts' => '00:00', 'ends' => '23:59', 'kind' => 'lesson']]),
        'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->subject = Ids::next();
    DB::table('academic_subjects')->insert(['id' => $this->subject, 'division_id' => $school->id('secondary'), 'short' => 'BIO', 'name' => 'Xylobiology', 'core' => true,
        'levels' => json_encode(['F1']), 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2, 3, 4, 5, 6, 7] as $weekday) {
        DB::table('academic_timetable')->insert(['id' => $weekday === 1 ? $this->slot : Ids::next(), 'class_id' => $school->id('class_secondary_b'), 'weekday' => $weekday, 'period' => 1,
            'subject_id' => $this->subject, 'user_id' => $this->atSecondary->id, 'starts_on' => '2026-01-01', 'set_by' => $this->atSecondary->id, 'set_at' => now()]);
    }
    $this->term = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->term, 'year_id' => $school->id('year'), 'division_id' => $school->id('secondary'), 'name' => 'Term 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $this->assessment = Ids::next();
    DB::table('assessments')->insert(['id' => $this->assessment, 'class_id' => $school->id('class_secondary_b'), 'subject_id' => $this->subject, 'period_id' => $this->term,
        'kind' => 'class_test', 'title' => 'Quokka cells', 'held_on' => '2026-10-01', 'out_of' => 20, 'counts' => true, 'part_key' => 'continuous', 'created_by' => $this->atSecondary->id,
        'state' => 'marking', 'journey_ref' => 'assessment.marks@2026.1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('assessment_marks')->insert(['id' => Ids::next(), 'assessment_id' => $this->assessment, 'learner_id' => $this->outside, 'value' => 15, 'reason' => null,
        'entered_by' => $this->atSecondary->id, 'entered_at' => now()]);

    // Records of every kind about the Secondary learner, made by the owner inside Secondary, as the office would.
    $this->inPlace('division', $school->id('secondary'), $this->owner);
    $kind = (string) DB::table('conduct_kinds')->where('name', 'Fighting')->value('id');
    $this->case = (string) $this->actingAs($this->owner)->postJson('/api/v1/conduct/entries', ['kind_id' => $kind, 'learners' => [$this->outside],
        'happened_on' => '2026-11-17', 'what' => 'Zikomo Outsider pushed a learner.'])->assertCreated()->json('case_id');
    $this->entry = (string) DB::table('conduct_entries')->orderByDesc('created_at')->value('id');
    $this->plan = (string) $this->actingAs($this->owner)->postJson('/api/v1/support/plans', ['learner_id' => $this->outside, 'title' => 'Quokka plan',
        'concern' => 'Zikomo Outsider is often away.', 'goals' => [['text' => 'Attend', 'measure' => 'Registers.', 'by_when' => 'By 11 Dec']]])->assertCreated()->json('id');
    $tuition = (string) DB::table('fee_items')->where('name', 'Tuition')->value('id');
    $this->actingAs($this->owner)->putJson("/api/v1/fees/terms/{$this->term}/{$school->id('secondary')}", ['amounts' => [['item_id' => $tuition, 'level_code' => 'F1', 'amount' => 9_999_900]],
        'plan' => [['label' => 'On opening day', 'due_on' => '2026-09-07', 'share_bp' => 10000]]])->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/fees/terms/{$this->term}/{$school->id('secondary')}/bill")->assertOk();
    $this->charge = (string) DB::table('fee_charges')->where('learner_id', $this->outside)->value('id');
    DB::table('fee_office_staff')->insert(['office_id' => 'school', 'user_id' => $this->owner->id, 'added_at' => now()]);
    $this->payment = (string) $this->actingAs($this->owner)->postJson('/api/v1/fees/payments', ['method' => 'cash', 'reference' => null, 'received_on' => now()->toDateString(),
        'payer_name' => 'Zikomo Outsider Parent', 'amount' => 1_234_500, 'shares' => [['learner_id' => $this->outside, 'amount' => 1_234_500]], 'tell' => false])->assertCreated()->json('id');
    $guardian = Ids::next();
    DB::table('guardians')->insert(['id' => $guardian, 'phone' => '+265999999999', 'name' => 'Zikomo Outsider Parent', 'user_id' => null, 'created_at' => now()]);
    $this->link = Ids::next();
    DB::table('learner_guardian_relationships')->insert(['id' => $this->link, 'guardian_id' => $guardian, 'learner_id' => $this->outside, 'contact_id' => $this->contacts[$this->outside],
        'held_by' => User::factory()->create(['name' => 'A parent'])->id, 'state' => 'active', 'journey_ref' => 'enrolment.guardian_link@2026.1', 'created_at' => now(), 'updated_at' => now()]);
    $this->message = Ids::next();
    DB::table('notification_deliveries')->insert(['id' => $this->message, 'request_id' => Ids::next(), 'topic_key' => 'attendance.absence', 'audience' => 'contact',
        'recipient_key' => 'phone:+265999999999', 'recipient_name' => 'Zikomo Outsider Parent', 'recipient_phone' => '0999 999 999', 'channel' => 'sms',
        'subject' => 'Zikomo Outsider is absent', 'body' => 'Zikomo Outsider is absent today.', 'subject_type' => 'learner', 'subject_id' => $this->outside,
        'scope_type' => 'class', 'scope_id' => $school->id('class_secondary_b'), 'status' => 'sent', 'dedupe_key' => 'boundary-test', 'queued_at' => now()]);
    $this->request = Ids::next();
    DB::table('approval_requests')->insert(['id' => $this->request, 'topic_key' => 'enrolment.details', 'subject_id' => $this->outside, 'requester_id' => $this->atSecondary->id,
        'state' => 'waiting', 'current' => 0, 'chain' => json_encode([]), 'created_at' => now()]);
    DB::table('approval_steps')->insert(['id' => Ids::next(), 'request_id' => $this->request, 'position' => 0, 'label' => 'Head teacher', 'needs' => 1, 'state' => 'waiting',
        'asked' => json_encode([$this->owner->id]), 'asked_at' => now()]);

    // Words that belong to Secondary only. None may come back inside Primary. (That a division exists is
    // not a record: the switcher names the places one may enter.)
    $this->secrets = ['Zikomo', 'Outsider', 'AL-9999-9999', '0999 999 999', $this->outside, 'Zangaphee', 'Xylobiology', 'Quokka', '99,999', '12,345'];
});

it('refuses every route pointed at Secondary, and keeps every list to Primary, for an account that holds everything', function (): void {
    $school = $this->school;
    $own = [
        'people.' => ['id' => $this->atSecondary->id, 'assignment' => $this->held->id],
        'roles.' => ['id' => $this->clerk->id],
        'learners.' => ['id' => $this->outside, 'contact' => $this->contacts[$this->outside]],
        'registers.' => ['id' => $school->id('class_secondary_b'), 'register' => $school->id('class_secondary_b'), 'learner' => $this->outside, 'slot' => $this->slot],
        'assessment.' => ['id' => $this->assessment],
        'assessment.learner' => ['id' => $this->outside],
        'portal.' => ['id' => $this->outside, 'day' => '2026-10-01'],
        'year-end.' => ['division' => $school->id('secondary'), 'id' => $this->outside],
        'conduct.' => ['id' => $this->outside, 'step' => 'take-up'],
        'support.' => ['id' => $this->outside, 'what' => 'agree'],
        'pickup.' => ['id' => $this->outside],
        'programmes.' => ['division' => $school->id('secondary'), 'id' => $this->outside, 'learner' => $this->outside, 'period' => $this->term, 'action' => 'submit'],
        'news.' => ['division' => $school->id('secondary'), 'id' => $this->outside],
        'lectures.' => ['division' => $school->id('secondary'), 'id' => $this->outside, 'learner' => $this->outside, 'period' => $this->term, 'action' => 'away'],
        'classroom.' => ['division' => $school->id('secondary'), 'id' => $this->outside, 'learner' => $this->outside],
        'student.' => ['id' => $this->outside],
        'fees.' => ['id' => $this->outside, 'period' => $this->term, 'division' => $school->id('secondary'), 'day' => '2026-10-01'],
        // Each record route at a real record of Secondary.
        'conduct.incidents.' => ['id' => $this->case, 'step' => 'take-up'],
        'conduct.entries.' => ['id' => $this->entry],
        'support.plans.' => ['id' => $this->plan, 'what' => 'agree'],
        'fees.payments.' => ['id' => $this->payment],
        'fees.charges.' => ['id' => $this->charge],
        'families.' => ['id' => $this->link],
        'messages.message' => ['id' => $this->message],
        'messages.again' => ['id' => $this->message],
        'approvals.show' => ['id' => $this->request],
        'approvals.answer' => ['id' => $this->request],
        'approvals.withdraw' => ['id' => $this->request],
        'academics.subjects.' => ['id' => $this->subject],
    ];
    $b = ['id' => $school->id('class_secondary_b'), 'key' => 'attendance', 'division_id' => $school->id('secondary'), 'campus_id' => $school->id('campus_b')];
    $body = [...$b, 'class_id' => $school->id('class_secondary_b'), 'learner_id' => $this->outside, 'period_id' => $this->term, 'subject_id' => $this->subject, 'slot_id' => $this->slot,
        'level_code' => 'F1', 'reason' => 'Boundary test.', 'day' => '2026-10-01', 'marks' => [['learner_id' => $this->outside, 'value' => 10, 'reason' => null]],
        'learner_ids' => [$this->outside], 'class_ids' => [$school->id('class_secondary_b')], 'ids' => [$this->outside],
        // As the cross-scope test: a full body, so a route that would let it through gets past validation and is seen.
        'name' => 'Secondary office', 'place' => 'Blantyre', 'taught' => true, 'streams' => ['F1' => ['East']],
        'terms' => [['name' => 'Term 1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11']], 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23',
        'email' => 'someone@example.test', 'role' => 'class_teacher', 'place_type' => 'class', 'place_id' => $school->id('class_secondary_b'),
        'enabled' => true, 'role_id' => $this->clerk->id, 'scope_type' => 'class', 'scope_id' => $school->id('class_secondary_b'), 'permissions' => [], 'send' => false,
        'first_name' => 'Wezi', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2012-02-02', 'admitted_on' => '2026-09-08', 'from' => '2026-10-01',
        'phone' => '0999 000 222', 'relationship' => 'father', 'user_id' => $this->atSecondary->id, 'away' => true, 'weekday' => 1, 'period' => 1,
        'kind' => 'class_test', 'title' => 'Cells', 'held_on' => '2026-10-01', 'out_of' => 20, 'counts' => true, 'value' => 10, 'note' => 'Boundary test.',
        'mode' => 'continuous', 'what' => 'Boundary test.', 'happened_on' => '2026-11-17', 'learners' => [$this->outside], 'concern' => 'Boundary test.',
        'method' => 'cash', 'received_on' => now()->toDateString(), 'payer_name' => 'Someone', 'amount' => 100, 'shares' => [['learner_id' => $this->outside, 'amount' => 100]],
        'contacts' => [['name' => 'Wezi Parent', 'relationship' => 'mother', 'phone' => '0999 000 333', 'email' => '', 'main' => true],
            ['name' => 'Wezi Parent Two', 'relationship' => 'father', 'phone' => '0999 000 444', 'email' => '', 'main' => false]]];

    $this->inPlace('division', $school->id('primary'), $this->owner);
    $checked = 0;
    $lists = 0;
    $leaks = [];

    foreach (boundaryRoutes() as $route) {
        $name = (string) $route->getName();

        // Open routes reveal nothing; record routes are decided by Access (and so by the place) in their own tests.
        if (in_array($name, RouteRules::OPEN_ROUTES, true) || array_key_exists($name, RouteRules::RECORD_ROUTES)) {
            continue;
        }

        $values = $b;
        foreach ($own as $prefix => $overrides) {
            if (str_starts_with($name, $prefix)) {
                $values = [...$b, ...$overrides];
            }
        }
        $own_ = in_array($name, RouteRules::OWN_ROUTES, true);
        // A page with nothing in its address that points at a place answers for the place worked in: it is checked like a list.
        $list = in_array($name, RouteRules::LIST_ROUTES, true) || (in_array('GET', $route->methods(), true) && ($own_ || ! str_contains($route->uri(), '{')));

        // An own route that changes the account: not about a place.
        if ($own_ && ! $list) {
            continue;
        }

        $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($values, $name): string {
            return $values[$m[1]] ?? throw new LogicException("Give the boundary test a Secondary value for {{$m[1]}} in route {$name}.");
        }, $route->uri());
        if ($name === 'search') {
            $uri .= '?q=Outsider';
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            foreach ($method === 'GET' ? [[]] : [$body, []] as $sent) {
                app()->forgetScopedInstances();
                // Each request in its own savepoint: what one changes or breaks never reaches the next.
                DB::beginTransaction();
                $response = $this->actingAs($this->owner)->json($method, $uri, $sent);
                DB::rollBack();
                $status = $response->status();
                // Search repeats the words asked: they are the asker's, not the school's.
                $content = (string) preg_replace('/"q":"[^"]*"/', '', (string) $response->getContent());

                if ($list) {
                    if (! in_array($status, [200, 403, 404, 422], true)) {
                        $leaks[] = "{$uri} ({$name}) answered {$status}.";
                    }
                    // The account's own context lists the places it may enter (the switcher): only learners must not be in it.
                    foreach ($name === 'me.context' ? array_slice($this->secrets, 0, 5) : $this->secrets as $secret) {
                        if (str_contains($content, $secret)) {
                            $leaks[] = "{$uri} ({$name}) showed Secondary ({$secret}) inside Primary: ".leakAround($content, $secret);
                            break;
                        }
                    }
                    $lists++;
                } else {
                    // Done (2xx) or broken (5xx) is a leak; refused, invalid (nothing done, nothing named) or slowed is not. School-wide settings may be done.
                    // Without a person named, marking away marks the caller's own day.
                    $ownDay = $sent === [] && $name === 'registers.away';
                    if ($status >= 500 || ($status < 300 && ! $ownDay && ! in_array($name, [...RouteRules::SCHOOL_WIDE, ...RouteRules::PLACE_OF], true))) {
                        $leaks[] = "{$method} {$uri} ({$name}) answered {$status} inside Primary, pointed at Secondary.";
                    }
                    foreach ($this->secrets as $secret) {
                        if (str_contains($content, $secret)) {
                            $leaks[] = "{$method} {$uri} ({$name}) named Secondary ({$secret}) in its refusal.";
                            break;
                        }
                    }
                }
                $checked++;
            }
        }
    }

    expect($leaks)->toBe([])
        ->and($checked)->toBeGreaterThan(100)->and($lists)->toBeGreaterThan(20);

    // Inside Primary the Primary learner is there: the wall is not an empty page.
    $this->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->inside)->assertOk();
    $this->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->outside)->assertNotFound();
    // The console may offer to switch to Secondary, where the owner works too: only the place is named.
    $this->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->outside.'/place')->assertOk()->assertExactJson(['key' => 'division:'.$this->school->id('secondary'), 'label' => 'Secondary']);
    $this->actingAs($this->atSecondary)->getJson('/api/v1/learners/'.$this->inside.'/place')->assertNotFound();
});

it('shows no learner of any division in the school view', function (): void {
    $this->inPlace('institution', null, $this->owner);
    $leaks = [];
    $words = ['Alinafe', 'Insider', 'AL-1111-1111', 'Zikomo', 'Outsider', 'AL-9999-9999', '0999 111 111', '0999 999 999', $this->inside, $this->outside];

    foreach (boundaryRoutes() as $route) {
        $name = (string) $route->getName();
        if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || in_array($name, [...RouteRules::OPEN_ROUTES, ...RouteRules::OVERVIEW_LISTS], true)) {
            continue;
        }
        $uri = '/'.$route->uri().($name === 'search' ? '?q=Insider' : '');
        app()->forgetScopedInstances();
        $content = (string) preg_replace('/"q":"[^"]*"/', '', (string) $this->actingAs($this->owner)->getJson($uri)->getContent());
        foreach ($words as $word) {
            if (str_contains($content, $word)) {
                $leaks[] = "{$uri} ({$name}) showed a learner ({$word}) in the school view: ".leakAround($content, $word);
                break;
            }
        }
    }

    // No learner's record opens in the school view, whatever the module.
    foreach (['learners', 'fees/learners', 'conduct/learners', 'support/learners', 'assessment/learner', 'registers/learner', 'pickup/learners'] as $path) {
        foreach ([$this->inside, $this->outside] as $learner) {
            $status = $this->actingAs($this->owner)->getJson("/api/v1/{$path}/{$learner}")->status();
            if (! in_array($status, [403, 404], true)) {
                $leaks[] = "/api/v1/{$path}/{$learner} answered {$status} in the school view.";
            }
        }
    }
    expect($leaks)->toBe([]);
    $this->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->inside)->assertNotFound();
});

it('refuses a place the account does not work in, and starts an account with roles in several divisions in the school view', function (): void {
    // A teacher of a Secondary class may not claim Primary.
    $this->inPlace('division', $this->school->id('primary'), $this->atSecondary);
    $this->actingAs($this->atSecondary)->getJson('/api/v1/learners')->assertForbidden()->assertJsonPath('code', 'place_not_held');

    // A request that cannot carry the header (a receipt in a new tab, a photo) names the place in a cookie, checked the same way.
    $this->noPlace($this->owner);
    $this->withCredentials()->withUnencryptedCookie(Boundary::COOKIE, 'division:'.$this->school->id('primary'))->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->inside)->assertOk();
    $this->withCredentials()->withUnencryptedCookie(Boundary::COOKIE, 'division:'.$this->school->id('primary'))->actingAs($this->owner)->getJson('/api/v1/learners/'.$this->outside)->assertNotFound();
    $this->withCredentials()->withUnencryptedCookie(Boundary::COOKIE, 'division:'.$this->school->id('primary'))->actingAs($this->atSecondary)->getJson('/api/v1/learners')->assertForbidden();
    $this->unencryptedCookies = [];

    // With no place named, an account with roles for the whole school sees no record.
    $this->actingAs($this->owner)->withHeaders([Boundary::HEADER => ''])->getJson('/api/v1/learners/'.$this->inside)->assertNotFound();
});

it('keeps a finance office to the levels it serves, closes nothing else, and closes its own cash day', function (): void {
    $school = $this->school;

    // In the school view: Primary gets its own office; Secondary stays with the school's own office.
    $this->inPlace('institution', null, $this->owner);
    $this->actingAs($this->owner)->putJson('/api/v1/fees/offices', ['offices' => [
        ['id' => null, 'name' => 'Primary accounts', 'offerings' => [$school->id('offering_primary_a'), $school->id('offering_primary_b')], 'staff' => [$this->owner->id]],
    ], 'own_staff' => [$this->owner->id]])->assertOk();
    $offices = collect($this->actingAs($this->owner)->getJson('/api/v1/fees/offices')->assertOk()->json('offices'));
    $primary = (string) $offices->firstWhere('name', 'Primary accounts')['id'];
    expect($offices->pluck('name')->all())->toBe(['Finance office', 'Primary accounts']);

    // Each office is a place the bursar may choose.
    $keys = array_column($this->actingAs($this->owner)->getJson('/api/v1/me/context')->assertOk()->json('places'), 'key');
    expect($keys)->toContain('office:school', 'office:'.$primary);

    // In the Primary office: nothing of Secondary, and nothing but fees.
    $this->inPlace('office', $primary, $this->owner);
    expect(str_contains((string) $this->actingAs($this->owner)->getJson('/api/v1/fees/desk')->assertOk()->getContent(), 'Zikomo'))->toBeFalse();
    $this->actingAs($this->owner)->getJson('/api/v1/fees/payments/'.$this->payment)->assertNotFound();
    $this->actingAs($this->owner)->getJson('/api/v1/conduct')->assertForbidden();
    $this->actingAs($this->owner)->postJson('/api/v1/fees/days/'.now()->toDateString().'/close', ['counted' => 0])->assertOk();

    // In the school's own office: Secondary's payment is there, and its cash day is its own.
    $this->inPlace('office', 'school', $this->owner);
    $this->actingAs($this->owner)->getJson('/api/v1/fees/payments/'.$this->payment)->assertOk();
    $this->actingAs($this->owner)->postJson('/api/v1/fees/days/'.now()->toDateString().'/close', ['counted' => 1_234_500])->assertOk()->assertJsonPath('difference', 0);
    expect(DB::table('fee_days')->where('day', now()->toDateString())->pluck('office_id')->sort()->values()->all())->toBe([$primary, 'school']);

    // D-100: a fees role alone does not put a person in an office; they must be named to it.
    $cashier = User::factory()->create(['name' => 'Yamikani Zulu']);
    RoleAssignment::query()->create(['user_id' => $cashier->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->noPlace($cashier);
    $this->inPlace('institution', null, $cashier);
    expect(array_column($this->actingAs($cashier)->getJson('/api/v1/me/context')->assertOk()->json('places'), 'key'))->not->toContain('office:school', 'office:'.$primary);
    $this->inPlace('office', $primary, $cashier);
    $this->actingAs($cashier)->getJson('/api/v1/fees/desk')->assertForbidden()->assertJsonPath('code', 'place_not_held');
    $this->inPlace('division', $school->id('secondary'), $cashier);
    $this->actingAs($cashier)->postJson('/api/v1/fees/payments', ['method' => 'cash', 'reference' => null, 'received_on' => now()->subDay()->toDateString(),
        'payer_name' => 'A parent', 'amount' => 100, 'shares' => [['learner_id' => $this->outside, 'amount' => 100]], 'tell' => false])
        ->assertUnprocessable()->assertJsonPath('errors.shares.0', 'You are not named to work in the finance office for this learner.');

    // A person with no fees role cannot be named.
    $stranger = User::factory()->create();
    $this->inPlace('institution', null, $this->owner);
    $this->actingAs($this->owner)->putJson('/api/v1/fees/offices', ['offices' => [['id' => $primary, 'name' => 'Primary accounts',
        'offerings' => [$school->id('offering_primary_a'), $school->id('offering_primary_b')], 'staff' => [$this->owner->id, $stranger->id]]], 'own_staff' => [$this->owner->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['offices.0.staff']);
    expect(DB::table('audit_events')->where('action', 'fees.office_staff_changed')->count())->toBeGreaterThan(0);

    // An office no one serves a level of is not a place one may claim.
    $this->inPlace('office', '00000000-0000-0000-0000-000000000000', $this->owner);
    $this->actingAs($this->owner)->getJson('/api/v1/fees/desk')->assertForbidden()->assertJsonPath('code', 'place_not_held');
});
