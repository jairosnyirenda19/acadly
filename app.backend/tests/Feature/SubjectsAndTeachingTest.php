<?php

declare(strict_types=1);

use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
});

function teacherAt(ScopeRef $at, string $name): User
{
    $role = Role::query()->firstOrCreate(['key' => 'teaches'], ['name' => 'Teacher']);
    DB::table('access_role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_key' => AcademicsPermission::Teach->value]);
    $user = User::factory()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

describe('subjects', function (): void {
    it('starts a level from the subjects of its profile, once', function (): void {
        $primary = $this->school->id('primary');
        $before = $this->actingAs($this->admin)->getJson('/api/v1/academics/subjects')->assertOk()->json('levels.0');

        expect($before['subjects'])->toBe([])
            ->and(collect($before['starter'])->pluck('short')->all())->toContain('CHI', 'ENG', 'MAT', 'AGR')
            ->and(collect($before['starter'])->firstWhere('short', 'AGR')['levels'])->toBe(['STD5', 'STD6', 'STD7', 'STD8']);

        $after = $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects/start', ['division_id' => $primary])->assertOk()->json('levels.0');

        expect($after['subjects'])->toHaveCount(8)
            ->and($after['starter'])->toBe([])
            ->and(collect($after['subjects'])->firstWhere('short', 'MAT')['classes'])->toBe(2);

        $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects/start', ['division_id' => $primary])->assertUnprocessable();
    });

    it('adds, changes and retires a subject of the school\'s own, with a reason', function (): void {
        $secondary = $this->school->id('secondary');

        $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects', ['division_id' => $secondary, 'name' => 'French', 'short' => 'fr1', 'core' => false, 'levels' => ['F9']])
            ->assertUnprocessable()->assertJsonValidationErrors(['levels']);
        $levels = $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects', ['division_id' => $secondary, 'name' => 'French', 'short' => 'fre', 'core' => false, 'levels' => []])
            ->assertOk()->json('levels');
        $french = collect(collect($levels)->firstWhere('division_id', $secondary)['subjects'])->firstWhere('short', 'FRE');

        expect($french['levels'])->toBe([]);

        $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects', ['division_id' => $secondary, 'name' => 'french', 'short' => 'FRN', 'core' => false, 'levels' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['short']);
        $this->actingAs($this->admin)->putJson("/api/v1/academics/subjects/{$french['id']}", ['division_id' => $secondary, 'name' => 'French', 'short' => 'FRE', 'core' => false, 'levels' => ['F1', 'F2']])
            ->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/academics/subjects/{$french['id']}/retire", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($this->admin)->postJson("/api/v1/academics/subjects/{$french['id']}/retire", ['reason' => 'No teacher.'])->assertOk();

        expect(DB::table('academic_subjects')->where('id', $french['id'])->whereNotNull('retired_at')->exists())->toBeTrue();
    });
});

describe('teaching', function (): void {
    it('gives a subject and a class to teachers who can teach there, and keeps the history', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/academics/subjects/start', ['division_id' => $this->school->id('primary')])->assertOk();
        $mercy = teacherAt($this->school->scope(ScopeType::Campus, 'campus_a'), 'Mercy Banda');
        $kondwani = teacherAt($this->school->scope(ScopeType::Offering, 'offering_primary_a'), 'Kondwani Chirwa');
        $elsewhere = teacherAt($this->school->scope(ScopeType::Campus, 'campus_b'), 'Teacher at B');
        $class = $this->school->id('class_primary_a');
        $maths = DB::table('academic_subjects')->where('short', 'MAT')->value('id');
        $agriculture = DB::table('academic_subjects')->where('short', 'AGR')->value('id');

        $board = $this->actingAs($this->admin)->getJson('/api/v1/academics/teaching?division_id='.$this->school->id('primary').'&campus_id='.$this->school->id('campus_a'))
            ->assertOk()->json();

        expect(collect($board['teachers'])->pluck('name')->all())->toBe(['Grace Phiri', 'Kondwani Chirwa', 'Mercy Banda'])
            ->and((array) $board['classes'][0]['teaching'])->not->toHaveKey($agriculture)
            ->and($board['totals']['not_given'])->toBe(8);

        $this->actingAs($this->admin)->putJson('/api/v1/academics/teaching', ['class_id' => $class, 'subject_id' => $maths, 'user_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        $this->actingAs($this->admin)->putJson('/api/v1/academics/teaching', ['class_id' => $class, 'subject_id' => $agriculture, 'user_id' => $mercy->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);

        $this->actingAs($this->admin)->putJson('/api/v1/academics/teaching', ['class_id' => $class, 'subject_id' => $maths, 'user_id' => $mercy->id])->assertOk();
        $this->actingAs($this->admin)->putJson('/api/v1/academics/teaching', ['class_id' => $class, 'user_id' => $kondwani->id])->assertOk();
        $board = $this->actingAs($this->admin)->putJson('/api/v1/academics/teaching', ['class_id' => $class, 'subject_id' => $maths, 'user_id' => $kondwani->id])->assertOk()->json();

        expect($board['classes'][0]['class_teacher']['name'])->toBe('Kondwani Chirwa')
            ->and($board['classes'][0]['teaching'][$maths]['name'])->toBe('Kondwani Chirwa')
            ->and($board['totals']['not_given'])->toBe(6)
            ->and(DB::table('academic_teaching')->where('subject_id', $maths)->count())->toBe(2)
            ->and(DB::table('academic_teaching')->where('subject_id', $maths)->whereNull('ended_at')->value('user_id'))->toBe($kondwani->id);
    });

    it('lists who holds a role at a class but cannot be given teaching, with the roles', function (): void {
        $mercy = teacherAt($this->school->scope(ScopeType::Campus, 'campus_a'), 'Mercy Banda');
        $empty = Role::query()->create(['key' => 'class_teacher_empty', 'name' => 'Class teacher']);
        $jairos = User::factory()->create(['name' => 'Jairos Nyirenda']);
        RoleAssignment::query()->create(['user_id' => $jairos->id, 'role_id' => $empty->id, 'scope_type' => ScopeType::ClassGroup,
            'scope_id' => $this->school->id('class_primary_a'), 'starts_at' => now()->subMinute()]);

        $board = $this->actingAs($this->admin)->getJson('/api/v1/academics/teaching?division_id='.$this->school->id('primary').'&campus_id='.$this->school->id('campus_a'))
            ->assertOk()->json();

        expect(collect($board['teachers'])->pluck('name')->all())->toContain('Mercy Banda')->not->toContain('Jairos Nyirenda')
            ->and($board['blocked'])->toHaveCount(1)
            ->and($board['blocked'][0])->toMatchArray(['id' => $jairos->id, 'name' => 'Jairos Nyirenda', 'state' => 'no_teach', 'roles' => ['Class teacher'],
                'classes' => [$this->school->id('class_primary_a')]])
            ->and(collect($board['blocked'])->pluck('id')->all())->not->toContain($mercy->id);
    });

    it('shows a campus office only the levels at its own campus', function (): void {
        $role = Role::query()->create(['key' => 'campus_academic', 'name' => 'Campus academic office']);
        DB::table('access_role_permissions')->insert(['role_id' => $role->id, 'permission_key' => AcademicsPermission::Manage->value]);
        $office = User::factory()->create();
        RoleAssignment::query()->create(['user_id' => $office->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Campus,
            'scope_id' => $this->school->id('campus_b'), 'starts_at' => now()->subMinute()]);

        $tabs = $this->actingAs($office)->getJson('/api/v1/academics/teaching')->assertOk()->json('tabs');

        expect(collect($tabs)->pluck('label')->all())->toBe(['Primary · Blantyre', 'Secondary · Blantyre']);
        $this->actingAs($office)->getJson('/api/v1/academics/teaching?division_id='.$this->school->id('primary').'&campus_id='.$this->school->id('campus_a'))->assertNotFound();
    });
});
