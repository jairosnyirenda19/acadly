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
    'sanctum.csrf-cookie', 'auth.sign-in', 'auth.forgot-password', 'auth.reset-password',
    'kernel.health', 'kernel.ready', 'kernel.school', 'institution.logo.public',
];

/** Routes about the account itself, not about a place. */
const OWN_ROUTES = ['auth.sign-out', 'me', 'me.context', 'me.notifications', 'me.notifications.read'];

/** Routes decided per record by the owning domain, and proved in its own tests. */
const RECORD_ROUTES = [
    'documents.store' => 'DocumentsTest: refuses to store for a person without the kind permission',
    'documents.show' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.content' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.versions.store' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.trash' => 'DocumentsTest: answers not found to a person who may not open the document',
    'documents.restore' => 'DocumentsTest: trashes with a reason, restores a batch together',
    'people.import.template' => 'PeopleImportTest: the template lists only the roles and places the viewer can give',
    'people.import.check' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
    'people.import' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
];

/**
 * Lists that answer every viewer with only what is inside the viewer's reach.
 * For these the test checks that nothing of campus B is in the answer.
 */
const LIST_ROUTES = ['people.index', 'academics.teaching.index', 'learners.index'];

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

    // Values that point at campus B, for route parameters and request bodies alike;
    // a route's own values come first.
    $own = [
        'people.' => ['id' => $atB->id, 'assignment' => $heldAtB->id],
        'roles.' => ['id' => $clerk->id],
        'learners.' => ['id' => $learner, 'contact' => $contact],
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
        'from' => '2026-10-01', 'phone' => '0999 000 222', 'relationship' => 'father'];
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
