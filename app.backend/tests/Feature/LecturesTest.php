<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Lectures\Application\Passkeys;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Lectures (D-102): rooms and the campus area, the lecture timetable with
 * its checks, cover, and lecture attendance by scan-in with the student's
 * attendance phone (a passkey), with the lecturer's hand marks, the close,
 * the learner record and the student portal.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    // Thursday 19 November 2026, 12:00 in the school's timezone.
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['programmes', 'lectures'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }
    app()->forgetScopedInstances();

    $now = now();
    $this->college = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $this->college, 'name' => 'College', 'profile_ref' => 'TERTIARY@2026.1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $campus = Ids::next();
    DB::table('institution_campuses')->insert(['id' => $campus, 'name' => 'Main', 'place' => 'Lilongwe', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $this->college, 'campus_id' => $campus, 'started_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $this->semester = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->semester, 'year_id' => $year, 'division_id' => $this->college, 'name' => 'Semester 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-18', 'created_at' => $now, 'updated_at' => $now]);
    $class = Ids::next();
    DB::table('academic_classes')->insert(['id' => $class, 'year_id' => $year, 'division_id' => $this->college, 'campus_id' => $campus, 'level_code' => 'Y1',
        'stream' => null, 'name' => 'Year 1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $this->learners = [];
    foreach (['Chikondi Banda', 'Thoko Mwale', 'Kondwani Phiri'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-4000-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $this->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $this->learners[] = $learner;
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $lecturers = Role::query()->create(['key' => 'lecturer_test', 'name' => 'Lecturer']);
    $lecturers->grant([LecturesPermission::Teach]);
    $this->lecturer = User::factory()->create(['name' => 'Agnes Chilembwe']);
    $this->colleague = User::factory()->create(['name' => 'Joseph Banda']);
    foreach ([$this->lecturer, $this->colleague] as $u) {
        RoleAssignment::query()->create(['user_id' => $u->id, 'role_id' => $lecturers->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->college, 'starts_at' => now()->subMinute()]);
    }

    $base = "/api/v1/programmes/{$this->college}";
    $this->actingAs($this->admin)->putJson("{$base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false]]])->assertOk();
    $faculty = (string) DB::table('programme_faculties')->where('division_id', $this->college)->value('id');
    $department = $this->actingAs($this->admin)->postJson("{$base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
        'code' => 'ACF', 'prefixes' => ['ACC', 'LAW'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
    $c = [];
    foreach (['ACC 101', 'LAW 101'] as $code) {
        $c[$code] = $this->actingAs($this->admin)->postJson("{$base}/courses", ['id' => null, 'department_id' => $department, 'code' => $code, 'title' => "Course {$code}",
            'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertOk()->json('id');
    }
    $programme = $this->actingAs($this->admin)->postJson("{$base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'CAC',
        'name' => 'Certificate in Accounting', 'award' => 'certificate', 'years' => 1, 'semesters' => 1, 'credits' => 30, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $this->actingAs($this->admin)->getJson("{$base}/programmes/{$programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$base}/versions/{$draft}", ['groups' => [],
        'courses' => [['course_id' => $c['ACC 101'], 'year' => 1, 'semester' => 1, 'group' => null], ['course_id' => $c['LAW 101'], 'year' => 1, 'semester' => 1, 'group' => null]]])->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/versions/{$draft}/release")->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/offerings/{$this->semester}/prepare")->assertOk();
    $this->actingAs($this->admin)->putJson("{$base}/registration/{$this->semester}/window", ['opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
        'late_until' => '2026-12-05', 'add_drop_until' => '2026-12-10', 'late_fee' => 0, 'fees_share' => 0])->assertOk();
    foreach ($this->learners as $learner) {
        $this->actingAs($this->admin)->postJson("{$base}/students", ['learner_id' => $learner, 'programme_id' => $programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertOk();
    }
    $this->actingAs($this->admin)->postJson("{$base}/registration/{$this->semester}/start")->assertOk();
    $this->acc = (string) DB::table('programme_offerings')->where('period_id', $this->semester)->where('course_id', $c['ACC 101'])->value('id');
    $this->law = (string) DB::table('programme_offerings')->where('period_id', $this->semester)->where('course_id', $c['LAW 101'])->value('id');
    $this->base = "/api/v1/lectures/{$this->college}";
});

/** A student account, set up, for a learner. */
function lectureStudent(object $test, string $learner, string $phone): User
{
    $base = "/api/v1/programmes/{$test->college}";
    $test->actingAs($test->admin)->putJson("{$base}/accounts/{$learner}/phone", ['phone' => $phone])->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/accounts/codes", ['learners' => [$learner]])->assertOk();
    $user = User::query()->findOrFail(DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id'));
    $user->forceFill(['password' => Hash::make('a long pass phrase')])->save();
    DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => now()]);

    return $user;
}

/** Rooms, an area, and a published timetable: ACC 101 on Thursday 11:00 to 13:00 in B12, LAW 101 on Thursday 14:00 to 16:00. */
function lectureTimetable(object $test): array
{
    $b12 = $test->actingAs($test->admin)->postJson("{$test->base}/rooms", ['id' => null, 'code' => 'b12', 'name' => 'Block B, room 12', 'seats' => 60, 'kind' => 'room'])->assertCreated()->json('id');
    $hall = $test->actingAs($test->admin)->postJson("{$test->base}/rooms", ['id' => null, 'code' => 'HALL A', 'name' => 'Main hall', 'seats' => 150, 'kind' => 'hall'])->assertCreated()->json('id');
    $test->actingAs($test->admin)->putJson("{$test->base}/areas", ['areas' => [['name' => 'Main campus', 'latitude' => -15.7861, 'longitude' => 35.0058, 'reach' => 250]],
        'scan_minutes' => 20])->assertOk();
    $acc = $test->actingAs($test->admin)->postJson("{$test->base}/timetable/{$test->semester}/slots", ['offering_id' => $test->acc, 'kind' => 'lecture', 'weekday' => 4,
        'starts' => '11:00', 'ends' => '13:00', 'room_id' => $b12, 'lecturer_id' => $test->lecturer->id])->assertCreated()->json('id');
    $law = $test->actingAs($test->admin)->postJson("{$test->base}/timetable/{$test->semester}/slots", ['offering_id' => $test->law, 'kind' => 'lecture', 'weekday' => 4,
        'starts' => '14:00', 'ends' => '16:00', 'room_id' => $hall, 'lecturer_id' => $test->colleague->id])->assertCreated()->json('id');
    $test->actingAs($test->admin)->postJson("{$test->base}/timetable/{$test->semester}/publish")->assertOk()->assertJsonPath('published', true);

    return ['acc' => $acc, 'law' => $law, 'b12' => $b12, 'hall' => $hall];
}

/** A phone's passkey for tests: an ES256 key the "phone" holds. */
final class TestPhone
{
    public OpenSSLAsymmetricKey $key;

    public string $id;

    public int $count = 0;

    public function __construct()
    {
        // Some PHP builds find no OpenSSL settings file; a minimal one is enough to make a key.
        $config = tempnam(sys_get_temp_dir(), 'ssl');
        file_put_contents((string) $config, "[req]\ndistinguished_name = dn\n[dn]\n");
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => (string) $config]);
        @unlink((string) $config);
        $this->key = $key instanceof OpenSSLAsymmetricKey ? $key : throw new RuntimeException('No key.');
        $this->id = random_bytes(16);
    }

    /** @return array{client_data_json: string, attestation_object: string} */
    public function register(string $challenge): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$x."\x22\x58\x20".$y;
        $auth = hash('sha256', Passkeys::rpId(), true)."\x45".pack('N', 0).str_repeat("\0", 16).pack('n', strlen($this->id)).$this->id.$cose;
        $att = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x59".pack('n', strlen($auth)).$auth;
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => Passkeys::origin()]);

        return ['client_data_json' => Passkeys::encode((string) $client), 'attestation_object' => Passkeys::encode($att)];
    }

    /** @return array{credential_id: string, client_data_json: string, authenticator_data: string, signature: string} */
    public function sign(string $code): array
    {
        $this->count++;
        $auth = hash('sha256', Passkeys::rpId(), true)."\x05".pack('N', $this->count);
        $client = (string) json_encode(['type' => 'webauthn.get', 'challenge' => Passkeys::encode($code), 'origin' => Passkeys::origin()]);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['credential_id' => Passkeys::encode($this->id), 'client_data_json' => Passkeys::encode($client), 'authenticator_data' => Passkeys::encode($auth),
            'signature' => Passkeys::encode($signature)];
    }
}

it('keeps rooms and the campus area, checks the timetable and publishes it', function (): void {
    $b12 = $this->actingAs($this->admin)->postJson("{$this->base}/rooms", ['id' => null, 'code' => 'B12', 'name' => 'Block B, room 12', 'seats' => 60, 'kind' => 'room'])->assertCreated()->json('id');
    $this->actingAs($this->admin)->postJson("{$this->base}/rooms", ['id' => null, 'code' => 'b12', 'name' => 'Again', 'seats' => 10, 'kind' => 'room'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $s3 = $this->actingAs($this->admin)->postJson("{$this->base}/rooms", ['id' => null, 'code' => 'S3', 'name' => 'Seminar room 3', 'seats' => 2, 'kind' => 'seminar'])->assertCreated()->json('id');
    $this->actingAs($this->admin)->putJson("{$this->base}/areas", ['areas' => [], 'scan_minutes' => 45])->assertUnprocessable()->assertJsonValidationErrors(['scan_minutes']);
    $this->actingAs($this->admin)->putJson("{$this->base}/areas", ['areas' => [['name' => 'Main campus', 'latitude' => -15.7861, 'longitude' => 35.0058, 'reach' => 250]], 'scan_minutes' => 15])->assertOk();
    $rooms = $this->actingAs($this->admin)->getJson("{$this->base}/rooms")->assertOk()->json();
    expect($rooms['rooms'])->toHaveCount(2)->and($rooms['scan_minutes'])->toBe(15)->and($rooms['areas'][0]['reach'])->toBe(250);
    // A lecturer may not change rooms.
    $this->actingAs($this->lecturer)->postJson("{$this->base}/rooms", ['id' => null, 'code' => 'X1', 'name' => 'X', 'seats' => 10, 'kind' => 'room'])->assertForbidden();

    $slot = fn (array $s): string => $this->actingAs($this->admin)->postJson("{$this->base}/timetable/{$this->semester}/slots", $s)->assertCreated()->json('id');
    $slot(['offering_id' => $this->acc, 'kind' => 'lecture', 'weekday' => 4, 'starts' => '11:00', 'ends' => '13:00', 'room_id' => $b12, 'lecturer_id' => $this->lecturer->id]);
    $law = $slot(['offering_id' => $this->law, 'kind' => 'lecture', 'weekday' => 4, 'starts' => '12:00', 'ends' => '14:00', 'room_id' => $b12, 'lecturer_id' => $this->colleague->id]);
    $slot(['offering_id' => $this->acc, 'kind' => 'tutorial', 'weekday' => 2, 'starts' => '08:00', 'ends' => '09:00', 'room_id' => $s3, 'lecturer_id' => $this->lecturer->id]);
    $this->actingAs($this->admin)->postJson("{$this->base}/timetable/{$this->semester}/slots", ['offering_id' => $this->acc, 'kind' => 'lecture', 'weekday' => 4, 'starts' => '13:00',
        'ends' => '12:00', 'room_id' => $b12, 'lecturer_id' => $this->lecturer->id])->assertUnprocessable()->assertJsonValidationErrors(['ends']);

    $t = $this->actingAs($this->admin)->getJson("{$this->base}/timetable")->assertOk()->json();
    expect($t['period']['id'])->toBe($this->semester)->and($t['slots'])->toHaveCount(3)->and($t['cohorts'][0]['label'])->toBe('CAC · Year 1')
        ->and(collect($t['checks'])->pluck('kind')->sort()->values()->all())->toBe(['room', 'seats', 'students']);
    // The same students and the same room twice stop the publish; the small room is only a warning.
    $stopped = $this->actingAs($this->admin)->postJson("{$this->base}/timetable/{$this->semester}/publish")->assertOk()->json();
    expect($stopped['published'])->toBeFalse()->and(collect($stopped['checks'])->firstWhere('kind', 'students')['count'])->toBe(3);

    $hall = $this->actingAs($this->admin)->postJson("{$this->base}/rooms", ['id' => null, 'code' => 'HALL A', 'name' => 'Main hall', 'seats' => 150, 'kind' => 'hall'])->json('id');
    $this->actingAs($this->admin)->postJson("{$this->base}/timetable/{$this->semester}/slots", ['id' => $law, 'offering_id' => $this->law, 'kind' => 'lecture', 'weekday' => 4,
        'starts' => '14:00', 'ends' => '16:00', 'room_id' => $hall, 'lecturer_id' => $this->colleague->id])->assertCreated();
    $this->actingAs($this->admin)->postJson("{$this->base}/timetable/{$this->semester}/publish")->assertOk()->assertJsonPath('published', true);
    expect(DB::table('audit_events')->where('action', 'lectures.published')->count())->toBe(1);

    // Once published, a change tells the students of the course.
    $this->actingAs($this->admin)->postJson("{$this->base}/slots/{$law}/remove")->assertOk();
    expect(DB::table('notification_requests')->where('topic_key', 'lectures.changed')->count())->toBe(1);
});

it('shows a student only a published timetable, and arranges cover, cancels and moves lectures', function (): void {
    $student = lectureStudent($this, $this->learners[0], '0991 234 567');
    expect($this->actingAs($student)->getJson('/api/v1/student/lectures/today')->assertOk()->json('lectures'))->toBe([]);
    $s = lectureTimetable($this);
    $today = $this->actingAs($student)->getJson('/api/v1/student/lectures/today')->assertOk()->json();
    expect(collect($today['lectures'])->pluck('code')->all())->toBe(['ACC 101', 'LAW 101'])->and($today['phone']['set'])->toBeFalse()->and($today['location'])->toBeTrue();
    // Staff routes stay closed to a student.
    $this->actingAs($student)->getJson("{$this->base}/timetable")->assertNotFound();

    // The lecturer cannot come next Thursday: a reason is needed; then a free colleague is asked and accepts.
    $day = '2026-11-26';
    $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/away", ['day' => $day, 'reason' => ''])->assertUnprocessable();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/away", ['day' => $day, 'reason' => 'At a professional examination.'])->assertOk();
    $free = $this->actingAs($this->lecturer)->getJson("{$this->base}/slots/{$s['acc']}/free?day={$day}")->assertOk()->json('colleagues');
    expect(collect($free)->firstWhere('id', $this->colleague->id)['free'])->toBeTrue();
    // A lecturer arranges cover only for their own lecture, and may not cancel.
    $this->actingAs($this->colleague)->postJson("{$this->base}/slots/{$s['acc']}/ask", ['day' => $day, 'user_id' => $this->colleague->id])->assertForbidden();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/cancel", ['day' => $day, 'reason' => 'No.'])->assertForbidden();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/ask", ['day' => $day, 'user_id' => $this->colleague->id])->assertOk();
    expect(DB::table('notification_requests')->where('topic_key', 'lectures.cover')->count())->toBe(1);
    $asked = $this->actingAs($this->colleague)->getJson("{$this->base}/my-day")->assertOk()->json('asked');
    expect($asked)->toHaveCount(1)->and($asked[0]['code'])->toBe('ACC 101');
    $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/accept", ['day' => $day])->assertNotFound();
    $this->actingAs($this->colleague)->postJson("{$this->base}/slots/{$s['acc']}/accept", ['day' => $day])->assertOk();
    $cover = $this->actingAs($this->admin)->getJson("{$this->base}/cover?week=2026-11-23")->assertOk()->json();
    expect($cover['counts']['covered'])->toBe(1)->and($cover['rows'][0]['cover'])->toBe('Joseph Banda');

    // The head of department cancels LAW 101 and moves ACC 101 the week after; the students are told and see it.
    $this->actingAs($this->admin)->postJson("{$this->base}/slots/{$s['law']}/cancel", ['day' => $day, 'reason' => 'Lecturer at a conference.'])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/slots/{$s['acc']}/move", ['day' => '2026-12-03', 'reason' => 'Hall needed for a graduation.',
        'to_day' => '2026-12-04', 'to_starts' => '09:00', 'to_ends' => '11:00', 'to_room' => $s['hall']])->assertOk();
    expect(DB::table('notification_requests')->where('topic_key', 'lectures.changed')->count())->toBe(3);
    $week = $this->actingAs($student)->getJson('/api/v1/student/lectures/week?from=2026-12-03')->assertOk()->json('days');
    $thursday = collect($week)->firstWhere('day', '2026-12-03')['lectures'];
    $friday = collect($week)->firstWhere('day', '2026-12-04')['lectures'];
    expect($thursday[0]['state'])->toBe('moved')->and($friday[0]['code'])->toBe('ACC 101')->and($friday[0]['room'])->toBe('HALL A')->and($friday[0]['starts'])->toBe('09:00');
    $nextWeek = $this->actingAs($student)->getJson('/api/v1/student/lectures/week?from=2026-11-23')->json('days');
    expect(collect(collect($nextWeek)->firstWhere('day', $day)['lectures'])->pluck('state')->all())->toBe(['covered', 'cancelled']);
});

it('takes lecture attendance by scan-in with the attendance phone, on campus, and closes with the rest absent', function (): void {
    $s = lectureTimetable($this);
    $first = lectureStudent($this, $this->learners[0], '0991 234 567');
    $second = lectureStudent($this, $this->learners[1], '0888 765 432');
    $phones = [new TestPhone, new TestPhone];
    foreach ([$first, $second] as $i => $student) {
        $begin = $this->actingAs($student)->postJson('/api/v1/student/lectures/phone/begin')->assertOk()->json();
        expect($begin['rp_id'])->toBe(Passkeys::rpId());
        $this->actingAs($student)->postJson('/api/v1/student/lectures/phone', $phones[$i]->register($begin['challenge']))->assertOk();
    }
    // One phone a student.
    $this->actingAs($first)->postJson('/api/v1/student/lectures/phone/begin')->assertUnprocessable();

    // Only the lecturer of record (or one who arranges cover) opens it; the colleague may not.
    $day = '2026-11-19';
    $this->actingAs($this->colleague)->postJson("{$this->base}/slots/{$s['acc']}/open", ['day' => $day])->assertForbidden();
    $session = $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/open", ['day' => $day])->assertOk()->json('session');
    $screen = $this->actingAs($this->lecturer)->getJson("{$this->base}/sessions/{$session}/code")->assertOk()->json();
    expect($screen['open'])->toBeTrue()->and($screen['of'])->toBe(3)->and($screen['scanned'])->toBe(0);
    $code = $screen['code'];
    $here = ['latitude' => -15.7862, 'longitude' => 35.0059, 'accuracy' => 30];

    // On campus, with the attendance phone: present.
    $this->actingAs($first)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[0]->sign($code), ...$here])->assertOk()
        ->assertJson(['result' => 'present', 'course' => 'ACC 101 Lecture']);
    // Another student's phone, a phone off campus, and an old code are each refused, and the lecturer sees why.
    $this->actingAs($second)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[0]->sign($code), ...$here])->assertOk()->assertJson(['result' => 'refused', 'why' => 'phone']);
    $this->actingAs($second)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[1]->sign($code), 'latitude' => -13.9626, 'longitude' => 33.7741, 'accuracy' => 20])
        ->assertOk()->assertJson(['result' => 'refused', 'why' => 'outside']);
    $this->travel(45)->seconds();
    $this->actingAs($second)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[1]->sign($code), ...$here])->assertOk()->assertJson(['result' => 'refused', 'why' => 'code']);
    $lecture = $this->actingAs($this->lecturer)->getJson("{$this->base}/slots/{$s['acc']}?day={$day}")->assertOk()->json();
    $rows = collect($lecture['students'])->keyBy('learner_id');
    expect($rows[$this->learners[0]]['mark'])->toBe('present')->and($rows[$this->learners[1]]['refused']['why'])->toBe('code')->and($lecture['session']['scanning'])->toBeTrue();
    expect($this->actingAs($first)->getJson('/api/v1/student/lectures/today')->json('lectures.0.mark'))->toBe('present');
    // Once scanning time is over, a scan is refused.
    $this->travel(20)->minutes();
    $fresh = $this->actingAs($this->lecturer)->getJson("{$this->base}/sessions/{$session}/code")->json();
    expect($fresh['open'])->toBeFalse()->and($fresh['code'])->toBe('');
    $this->actingAs($second)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[1]->sign($code), ...$here])->assertOk()->assertJson(['result' => 'refused', 'why' => 'closed']);

    // The lecturer marks the second student late by hand, with a reason; closing marks the third absent.
    $this->actingAs($this->lecturer)->postJson("{$this->base}/sessions/{$session}/mark", ['learner_id' => $this->learners[1], 'mark' => 'late', 'reason' => ''])->assertUnprocessable();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/sessions/{$session}/mark", ['learner_id' => $this->learners[1], 'mark' => 'late', 'reason' => 'Phone not working, seen in class.'])->assertOk();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/sessions/{$session}/close")->assertOk();
    expect(DB::table('lecture_marks')->where('session_id', $session)->where('learner_id', $this->learners[2])->value('mark'))->toBe('absent');
    // A scan after a hand mark answers with that mark.
    $this->actingAs($second)->postJson('/api/v1/student/lectures/scan', ['code' => $code, ...$phones[1]->sign($code), ...$here])->assertOk()->assertJson(['result' => 'late']);
    // A correction is a new row; the first mark stays.
    $this->actingAs($this->lecturer)->postJson("{$this->base}/sessions/{$session}/mark", ['learner_id' => $this->learners[2], 'mark' => 'present', 'reason' => 'Signed the paper list.'])->assertOk();
    expect(DB::table('lecture_marks')->where('session_id', $session)->where('learner_id', $this->learners[2])->count())->toBe(2);
    // Marks cannot be removed: inside its own savepoint, so the refusal does not end the test's transaction.
    DB::beginTransaction();
    expect(fn () => DB::table('lecture_marks')->where('session_id', $session)->delete())->toThrow(Exception::class);
    DB::rollBack();

    // The learner record and the student's own page.
    $tab = $this->actingAs($this->admin)->getJson("/api/v1/lectures/learners/{$this->learners[1]}")->assertOk()->json();
    expect($tab['rate'])->toBe(100)->and($tab['courses'][0]['late'])->toBe(1)->and($tab['phone']['set'])->toBeTrue()->and($tab['can_reset'])->toBeTrue();
    $mine = $this->actingAs($first)->getJson('/api/v1/student/lectures/attendance')->assertOk()->json();
    expect($mine['rate'])->toBe(100)->and($mine['held'])->toBe(1)->and($mine['courses'][0]['code'])->toBe('ACC 101');
    $third = $this->actingAs($this->admin)->getJson("/api/v1/lectures/learners/{$this->learners[2]}")->json();
    expect($third['marks'][0]['was'])->toBe('absent')->and($third['marks'][0]['mark'])->toBe('present');

    // The registry resets a lost phone, with a reason; the student then sets up the new one.
    $this->actingAs($this->lecturer)->postJson("/api/v1/lectures/learners/{$this->learners[0]}/phone/reset", ['reason' => 'Phone lost.'])->assertForbidden();
    $this->actingAs($this->admin)->postJson("/api/v1/lectures/learners/{$this->learners[0]}/phone/reset", ['reason' => 'Phone lost.'])->assertOk();
    $this->actingAs($first)->postJson('/api/v1/student/lectures/phone/begin')->assertOk();
    expect(DB::table('audit_events')->where('action', 'lectures.phone_reset')->count())->toBe(1);
});

it('closes lectures that have ended, and adds to the dashboards and Today', function (): void {
    $s = lectureTimetable($this);
    $session = $this->actingAs($this->lecturer)->postJson("{$this->base}/slots/{$s['acc']}/open", ['day' => '2026-11-19'])->assertOk()->json('session');
    $today = $this->actingAs($this->lecturer)->withHeader('X-Acadly-Place', "division:{$this->college}")->getJson('/api/v1/today')->assertOk()->json();
    expect(collect($today['now'] ?? [])->pluck('key')->all())->toContain("lecture:{$s['acc']}");

    $this->travelTo('2026-11-19 11:30:00');
    $this->artisan('lectures:close')->assertSuccessful();
    expect(DB::table('lecture_sessions')->where('id', $session)->value('closed_at'))->not->toBeNull()
        ->and(DB::table('lecture_marks')->where('session_id', $session)->where('mark', 'absent')->count())->toBe(3);
});
