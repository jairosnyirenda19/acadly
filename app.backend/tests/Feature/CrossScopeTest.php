<?php

declare(strict_types=1);

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
use Tests\Fixtures\TwoCampusSchool;

/*
 * The cross-scope test (03 §3, "Proof"). It walks every route of the API with
 * an account that holds every permission in the build, but only at campus A,
 * and points each route at campus B. Every route must refuse: 403 or 404.
 * A refusal must come before validation, so nothing about campus B leaks.
 *
 * A new route is covered the moment it is added. A route that may answer
 * such an account must be listed below with the reason.
 */

/** Routes open to anyone: they reveal nothing about a place. */
const OPEN_ROUTES = [
    'sanctum.csrf-cookie', 'auth.sign-in', 'auth.two-step', 'academics.calendar.feed', 'auth.forgot-password', 'auth.reset-password', 'auth.phone.code', 'auth.phone.sign-in',
    'kernel.health', 'kernel.ready', 'kernel.school', 'institution.logo.public', 'notify.reports',
];

/** Routes about the account itself, not about a place. */
const OWN_ROUTES = ['auth.sign-out', 'me', 'me.context', 'me.account', 'me.account.notices', 'me.account.password', 'me.account.two-step', 'me.account.two-step.confirm', 'me.account.two-step.off', 'me.calendar', 'me.calendar.on', 'me.calendar.off', 'me.teaching', 'search', 'search.places', 'me.phone.code', 'me.phone.confirm', 'me.phone.remove', 'me.notifications', 'me.notifications.read', 'view-as.leave', 'approvals.waiting', 'portal.family', 'portal.confirm'];

/** Routes decided per record by the owning domain, and proved in its own tests. */
const RECORD_ROUTES = [
    'documents.store' => 'DocumentsTest: refuses to store for a person without the kind permission',
    'documents.show' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.content' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.versions.store' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.trash' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.restore' => 'DocumentsTest: trashes with a reason, restores a batch together',
    'assessment.publishing.publish' => 'AssessmentTest: publishes a ready class; a class outside the reach of the viewer is not found',
    'people.import.template' => 'PeopleImportTest: the template lists only the roles and places the viewer can give',
    'people.import.check' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
    'people.import' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
    'learners.import.template' => 'LearnerImportTest: the template lists only the classes the viewer may admit into',
    'learners.import.check' => 'LearnerImportTest: a row for a class outside the viewer\'s reach is not imported',
    'learners.import' => 'LearnerImportTest: a row for a class outside the viewer\'s reach is not imported',
    'grids.views.store' => 'GridTest: keeps a person\'s columns and named views (a person\'s own, over rows within reach)',
    'grids.views.update' => 'GridTest: keeps a person\'s columns and named views',
    'grids.views.delete' => 'GridTest: keeps a person\'s columns and named views',
    'grids.columns' => 'GridTest: keeps a person\'s columns and named views',
    'grids.exports.store' => 'GridTest: builds an export in the background (only rows within reach)',
    'grids.exports.show' => 'GridTest: builds an export in the background (another person: not found)',
    'grids.exports.file' => 'GridTest: builds an export in the background (another person: not found)',
    'grids.bulk' => 'GridTest: changes the class of every learner that matches (rows outside reach are dropped)',
    'fees.payments.store' => 'FeesTest: takes payments only where they may receive them (a child outside reach is not found)',
];

/**
 * Lists that answer every viewer with only what is inside the viewer's reach.
 * For these the test checks that nothing of campus B is in the answer.
 */
const LIST_ROUTES = ['people.index', 'academics.teaching.index', 'academics.timetable.index', 'learners.index', 'learners.grid', 'people.grid', 'registers.index',
    'registers.lessons', 'registers.missed', 'registers.levels', 'assessment.mine', 'assessment.options', 'assessment.grid', 'assessment.publishing', 'fees.shop.index', 'conduct.kinds.index', 'support.watch'];

uses(DatabaseTransactions::class);

/** @return list<Route> */
function apiRoutes(): array
{
    return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/')));
}

it('lists only routes that exist, and every open route needs no sign-in', function (): void {
    $names = array_map(fn (Route $route): ?string => $route->getName(), apiRoutes());

    foreach ([...OPEN_ROUTES, ...OWN_ROUTES, ...array_keys(RECORD_ROUTES)] as $listed) {
        expect($names)->toContain($listed);
    }

    foreach (apiRoutes() as $route) {
        $guarded = in_array('auth:sanctum', $route->gatherMiddleware(), true);
        expect($guarded)->toBe(! in_array($route->getName(), OPEN_ROUTES, true), "Route {$route->getName()} is listed wrongly as open or guarded.");
    }
});

it('refuses every place-bound route to an account that holds everything, but only at another campus', function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'assessment', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'fees', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'conduct', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'support', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    $school = TwoCampusSchool::make();

    $everything = Role::query()->create(['key' => 'everything_at_a', 'name' => 'Everything at campus A']);
    $everything->grant(array_values(app(PermissionCatalogue::class)->all()));
    $user = User::factory()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $everything->id, 'scope_type' => ScopeType::Campus,
        'scope_id' => $school->id('campus_a'), 'starts_at' => now()->subMinute()]);

    // A member of staff at campus B, with a role there.
    $clerk = Role::query()->create(['key' => 'clerk_b', 'name' => 'Clerk']);
    $atB = User::factory()->create(['name' => 'Chimwemwe Blantyre', 'email' => 'chimwemwe.b@example.test']);
    $heldAtB = RoleAssignment::query()->create(['user_id' => $atB->id, 'role_id' => $clerk->id, 'scope_type' => ScopeType::Campus,
        'scope_id' => $school->id('campus_b'), 'starts_at' => now()->subMinute()]);

    // A learner in a class at campus B, with a contact.
    $learner = Ids::next();
    $enrolment = Ids::next();
    $contact = Ids::next();
    DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-2222-2222', 'first_name' => 'Chimwemwe', 'surname' => 'Blantyre',
        'sex' => 'female', 'date_of_birth' => '2012-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $school->id('year'),
        'division_id' => $school->id('secondary'), 'campus_id' => $school->id('campus_b'), 'level_code' => 'F1', 'class_id' => $school->id('class_secondary_b'),
        'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => now()]);
    DB::table('learner_contacts')->insert(['id' => $contact, 'learner_id' => $learner, 'name' => 'Chimwemwe Blantyre', 'relationship' => 'mother',
        'phone' => '0999 000 111', 'position' => 1, 'added_at' => now()]);

    // A lesson at campus B, on every school day.
    $slot = Ids::next();
    DB::table('academic_bell_plans')->insert(['id' => Ids::next(), 'division_id' => $school->id('secondary'), 'campus_id' => $school->id('campus_b'),
        'name' => 'Every day', 'weekdays' => json_encode([1, 2, 3, 4, 5, 6, 7]), 'periods' => json_encode([['name' => 'Period 1', 'starts' => '00:00', 'ends' => '23:59', 'kind' => 'lesson']]),
        'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $subject = Ids::next();
    DB::table('academic_subjects')->insert(['id' => $subject, 'division_id' => $school->id('secondary'), 'short' => 'BIO', 'name' => 'Biology', 'core' => true,
        'levels' => json_encode(['F1']), 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2, 3, 4, 5, 6, 7] as $weekday) {
        DB::table('academic_timetable')->insert(['id' => $weekday === 1 ? $slot : Ids::next(), 'class_id' => $school->id('class_secondary_b'), 'weekday' => $weekday, 'period' => 1,
            'subject_id' => $subject, 'user_id' => $atB->id, 'starts_on' => '2026-01-01', 'set_by' => $atB->id, 'set_at' => now()]);
    }

    // A term of Secondary, and an assessment with a mark in the class at campus B.
    $term = Ids::next();
    DB::table('academic_periods')->insert(['id' => $term, 'year_id' => $school->id('year'), 'division_id' => $school->id('secondary'), 'name' => 'Term 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $assessment = Ids::next();
    DB::table('assessments')->insert(['id' => $assessment, 'class_id' => $school->id('class_secondary_b'), 'subject_id' => $subject, 'period_id' => $term,
        'kind' => 'class_test', 'title' => 'Cells', 'held_on' => '2026-10-01', 'out_of' => 20, 'counts' => true, 'part_key' => 'continuous', 'created_by' => $atB->id,
        'state' => 'marking', 'journey_ref' => 'assessment.marks@2026.1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('assessment_marks')->insert(['id' => Ids::next(), 'assessment_id' => $assessment, 'learner_id' => $learner, 'value' => 15, 'reason' => null,
        'entered_by' => $atB->id, 'entered_at' => now()]);

    // Values that point at campus B, for route parameters and request bodies alike;
    // a route's own values come first.
    $own = [
        'people.' => ['id' => $atB->id, 'assignment' => $heldAtB->id],
        'roles.' => ['id' => $clerk->id],
        'learners.' => ['id' => $learner, 'contact' => $contact],
        'registers.' => ['id' => $school->id('class_secondary_b'), 'register' => $school->id('class_secondary_b'), 'learner' => $learner, 'slot' => $slot],
        'assessment.' => ['id' => $assessment],
        'assessment.learner' => ['id' => $learner],
        'portal.' => ['id' => $learner, 'day' => '2026-10-01'],
        'year-end.' => ['division' => $school->id('secondary'), 'id' => $learner],
        'conduct.' => ['id' => $learner, 'step' => 'take-up'],
        'support.' => ['id' => $learner, 'what' => 'agree'],
        'fees.' => ['id' => $learner, 'period' => $term, 'division' => $school->id('secondary'), 'day' => '2026-10-01'],
    ];
    $b = [
        'id' => $school->id('campus_b'),
        'key' => 'attendance',
        'division_id' => $school->id('secondary'),
        'campus_id' => $school->id('campus_b'),
    ];
    $body = [...$b, 'name' => 'Campus B', 'place' => 'Blantyre', 'taught' => true, 'streams' => ['F1' => ['East']],
        'terms' => [['name' => 'Term 1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11']],
        'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'levels' => [['profile' => 'SECONDARY']],
        'email' => 'someone@example.test', 'role' => 'class_teacher', 'place_type' => 'campus', 'place_id' => $school->id('campus_b'),
        'enabled' => true, 'reason' => 'Cross-scope test.', 'role_id' => $clerk->id, 'scope_type' => 'campus',
        'scope_id' => $school->id('campus_b'), 'permissions' => [], 'send' => false, 'class_id' => $school->id('class_secondary_b'),
        'first_name' => 'Wezi', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2012-02-02', 'admitted_on' => '2026-09-08',
        'from' => '2026-10-01', 'phone' => '0999 000 222', 'relationship' => 'father', 'slot_id' => $slot, 'user_id' => $atB->id, 'away' => true,
        'weekday' => 1, 'period' => 1, 'subject_id' => $subject, 'level_code' => 'F1', 'period_id' => $term, 'learner_id' => $learner,
        'kind' => 'class_test', 'title' => 'Cells', 'held_on' => '2026-10-01', 'out_of' => 20, 'counts' => true, 'value' => 10, 'note' => 'Cross-scope test.',
        'scale_id' => (string) DB::table('assessment_scales')->value('id'), 'mode' => 'continuous', 'marks' => [['learner_id' => $learner, 'value' => 10, 'reason' => null]],
        'bands' => [['grade' => 'P', 'word' => 'Pass', 'from' => 50, 'to' => 100], ['grade' => 'F', 'word' => 'Fail', 'from' => 0, 'to' => 49]]];
    $secrets = [$school->id('campus_b'), $atB->email, $atB->name, 'Blantyre'];

    $checked = 0;

    foreach (apiRoutes() as $route) {
        $name = (string) $route->getName();

        if (in_array($name, [...OPEN_ROUTES, ...OWN_ROUTES], true) || array_key_exists($name, RECORD_ROUTES)) {
            continue;
        }

        $values = $b;

        foreach ($own as $prefix => $overrides) {
            if (str_starts_with($name, $prefix)) {
                $values = [...$b, ...$overrides];
            }
        }

        $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($values, $name): string {
            return $values[$m[1]] ?? throw new LogicException("Give the cross-scope test a campus B value for {{$m[1]}} in route {$name}.");
        }, $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            // A full body pointing at campus B, and an empty one: neither may get past the refusal.
            foreach ($method === 'GET' ? [[]] : [$body, []] as $sent) {
                app()->forgetScopedInstances();
                $response = $this->actingAs($user)->json($method, $uri, $sent);
                $status = $response->status();

                if (in_array($name, LIST_ROUTES, true)) {
                    expect($status)->toBe(200);

                    foreach ($secrets as $secret) {
                        expect(str_contains((string) $response->getContent(), $secret))->toBeFalse("{$uri} ({$name}) showed campus B data ({$secret}) to an account scoped to campus A.");
                    }
                } else {
                    expect($status)->toBeIn([403, 404], "{$method} {$uri} ({$name}) answered {$status} to an account scoped to another campus.");
                }
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(10);
});
