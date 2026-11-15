<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\AuditCatalogue;
use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Engine\Jobs\ApplyAfterCommitEffect;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Blueprint\Engine\Projection;
use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Journey\JourneyLinter;
use Acadly\Kernel\Blueprint\Journey\Outcome;
use Acadly\Kernel\Blueprint\Journey\Stage;
use Acadly\Kernel\Blueprint\Journey\Step;
use Acadly\Kernel\Blueprint\Journey\Transition;
use Acadly\Kernel\Blueprint\Lock;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Toy\Contracts\ToyPermission;
use Tests\Fixtures\Toy\Journeys\Errand\Milestone;
use Tests\Fixtures\Toy\Journeys\Errand\OutcomeId as O;
use Tests\Fixtures\Toy\Journeys\Errand\RecordDecision;
use Tests\Fixtures\Toy\Journeys\Errand\State as St;
use Tests\Fixtures\Toy\Journeys\Errand\StepId as S;
use Tests\Fixtures\Toy\Journeys\Errand\TellRequester;
use Tests\Fixtures\Toy\ToyErrand;
use Tests\Fixtures\Toy\ToyModule;

uses(DatabaseTransactions::class);

const TOY_REF = 'toy.errand@2026.1';

beforeEach(function (): void {
    app()->instance(ModuleRegistry::class, new ModuleRegistry([new InstitutionCore, new AttendanceModule, new ToyModule]));

    foreach ([PermissionCatalogue::class, AuditCatalogue::class, EventCatalogue::class, NoticeCatalogue::class, Notifier::class, Audit::class, Outbox::class] as $service) {
        app()->forgetInstance($service);
    }

    $compiler = app(BlueprintCompiler::class);
    expect($compiler->problems())->toBe([]);

    app()->instance(Blueprints::class, new Blueprints($compiler->compiled()));
    app()->forgetScopedInstances();

    $this->artisan('acadly:sync')->assertSuccessful();

    RecordDecision::$applied = 0;
    RecordDecision::$fail = false;
    TellRequester::$calls = [];
});

/** @param  list<ToyPermission>  $permissions */
function toyWorker(array $permissions = []): User
{
    $user = User::factory()->create();
    $role = Role::query()->create(['key' => 'toy_'.substr(Ids::next(), -12), 'name' => 'Errands']);
    $role->grant($permissions === [] ? ToyPermission::cases() : $permissions);

    RoleAssignment::query()->create([
        'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'institution',
        'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

function toyErrand(string $title = 'Collect the register books'): ToyErrand
{
    return ToyErrand::query()->create(['title' => $title, 'state' => St::Draft->value, 'journey_ref' => TOY_REF]);
}

function engine(): JourneyEngine
{
    return app(JourneyEngine::class);
}

function toyCompiled(): CompiledJourney
{
    return app(Blueprints::class)->journey(BlueprintRef::parse(TOY_REF));
}

/** Submitted by one person, ready for another to decide. */
function submittedErrand(): ToyErrand
{
    $errand = toyErrand();
    engine()->perform($errand, toyWorker(), S::Submit, O::Submitted);

    return $errand;
}

describe('the compiled form', function (): void {
    it('compiles the toy journey and reads it back to the same hash', function (): void {
        $journey = toyCompiled();

        expect($journey->initial)->toBe('draft')
            ->and(array_keys($journey->steps))->toBe(['submit', 'reopen', 'decide', 'complete'])
            ->and(CompiledJourney::fromJson($journey->json())->hash())->toBe($journey->hash())
            ->and($journey->json())->not->toContain(PHP_EOL.' ');
    });

    it('finds unreachable states, dead ends, unknown permissions and missing parts', function (): void {
        $broken = JourneyBlueprint::make('toy.broken@2026.1')
            ->title('Broken')
            ->subject(ToyErrand::class)
            ->states(St::class, initial: St::Draft, terminal: [St::Done])
            ->stage('only', 'Only', fn (Stage $stage): Stage => $stage
                ->step(Step::make(S::Submit)->title('Submit')->from(St::Draft)
                    ->outcome(Outcome::make(O::Submitted)->title('Submit')->transition(Transition::to(St::Submitted)))))
            ->compile();

        $problems = implode("\n", app(JourneyLinter::class)->problems($broken));

        expect($problems)
            ->toContain('step [submit]: the step needs a capability')
            ->toContain('the state [approved] cannot be reached')
            ->toContain('the state [submitted] is not terminal and has no step out of it');
    });

    it('refuses a released version whose declaration has changed', function (): void {
        $folder = sys_get_temp_dir().'/acadly-lock-'.Ids::next();
        mkdir($folder);
        file_put_contents($folder.'/blueprints.lock', json_encode([TOY_REF => ['hash' => str_repeat('0', 64), 'released_at' => '2026-11-16T00:00:00+00:00']]));
        app()->instance(Lock::class, new Lock($folder.'/blueprints.lock'));

        expect(implode("\n", app(BlueprintCompiler::class)->problems()))
            ->toContain('the version is released and its declaration has changed');
    });

    it('compiles, lints and releases through the commands', function (): void {
        $folder = sys_get_temp_dir().'/acadly-bp-'.Ids::next();
        config(['acadly.blueprints.compiled' => $folder.'/compiled', 'acadly.blueprints.lock' => $folder.'/blueprints.lock']);
        app()->instance(Lock::class, new Lock($folder.'/blueprints.lock'));

        $this->artisan('blueprint:lint')->assertFailed();
        $this->artisan('blueprint:compile')->assertSuccessful();
        $this->artisan('blueprint:lint')->assertSuccessful();
        $this->artisan('blueprint:release', ['ref' => TOY_REF])->assertSuccessful();
        $this->artisan('blueprint:release', ['ref' => TOY_REF])->assertFailed();

        $lock = new Lock($folder.'/blueprints.lock');
        $loaded = Blueprints::load($folder.'/compiled', $lock);

        expect($lock->hashOf(BlueprintRef::parse(TOY_REF)))->toBe(toyCompiled()->hash())
            ->and($loaded->newest('toy.errand')->version)->toBe('2026.1');

        // The deployment records the released version once.
        app()->instance(Lock::class, $lock);
        app()->instance(Blueprints::class, $loaded);
        $this->artisan('acadly:sync')->assertSuccessful();
        $this->artisan('acadly:sync')->assertSuccessful();

        expect(DB::table('blueprint_versions')->where('ref', TOY_REF)->count())->toBe(1);

        // A compiled file edited by hand no longer matches the lock.
        file_put_contents($folder.'/compiled/'.TOY_REF.'.json', str_replace('"Errand"', '"Changed"', (string) file_get_contents($folder.'/compiled/'.TOY_REF.'.json')));

        expect(fn () => Blueprints::load($folder.'/compiled', $lock))->toThrow(LogicException::class, 'does not match its locked hash');
    });

    it('draws the journey as a state diagram', function (): void {
        $diagram = app(Projection::class)->diagram(toyCompiled());

        expect($diagram)->toContain('[*] --> draft')
            ->toContain('submitted --> approved : decide · approved')
            ->toContain('done --> [*]');
    });
});

describe('perform', function (): void {
    it('moves the record, and records the step, the audit line and nothing else', function (): void {
        $errand = toyErrand();
        $who = toyWorker();

        $receipt = engine()->perform($errand, $who, S::Submit, O::Submitted);

        $completion = DB::table('journey_step_completions')->where('subject_id', $errand->id)->first();
        $line = DB::table('audit_events')->where('action', 'journeys.step_performed')->orderByDesc('seq')->first();

        expect($receipt->toState)->toBe('submitted')
            ->and($errand->state)->toBe('submitted')
            ->and($errand->fresh()?->state)->toBe('submitted')
            ->and($completion?->step)->toBe('submit')
            ->and($completion?->actor_user_id)->toBe($who->id)
            ->and($completion?->seq)->toBe(1)
            ->and($line?->journey_ref)->toBe(TOY_REF)
            ->and($line?->journey_outcome)->toBe('submitted')
            ->and($line?->subject_id)->toBe($errand->id);
    });

    it('blocks a closed gate with its reason and remedy, and changes nothing', function (): void {
        $errand = toyErrand(str_repeat('A long title ', 5));

        try {
            engine()->perform($errand, toyWorker(), S::Submit, O::Submitted);
            $this->fail('The gate did not block.');
        } catch (Problem $problem) {
            expect($problem->problemCode)->toBe('step_not_admissible')
                ->and($problem->extra)->toBe(['reason' => 'The title is longer than 40 characters.', 'remedy' => 'Shorten the title.']);
        }

        expect($errand->fresh()?->state)->toBe('draft')
            ->and(DB::table('journey_step_completions')->where('subject_id', $errand->id)->count())->toBe(0);
    });

    it('refuses a person without the capability, and audits the refusal', function (): void {
        $errand = toyErrand();
        $outsider = toyWorker([ToyPermission::Complete]);

        expect(fn () => engine()->perform($errand, $outsider, S::Submit, O::Submitted))->toThrow(AuthorizationException::class);
        expect($errand->fresh()?->state)->toBe('draft')
            ->and(DB::table('audit_events')->where('refusal', 'permission:toy.submit')->exists())->toBeTrue();
    });

    it('refuses a step that does not start from the current state', function (): void {
        $errand = toyErrand();

        expect(fn () => engine()->perform($errand, toyWorker(), S::Complete, O::Done))
            ->toThrow(fn (Problem $p) => expect($p->problemCode)->toBe('step_not_available'));
        expect(fn () => engine()->perform($errand, toyWorker(), 'no_such_step', 'none'))
            ->toThrow(fn (Problem $p) => expect($p->problemCode)->toBe('step_not_available'));
    });

    it('requires four eyes: the person who submitted cannot decide', function (): void {
        $errand = toyErrand();
        $submitter = toyWorker();
        engine()->perform($errand, $submitter, S::Submit, O::Submitted);

        expect(fn () => engine()->perform($errand, $submitter, S::Decide, O::Approved))
            ->toThrow(fn (Problem $p) => expect($p->problemCode)->toBe('another_person_required'));
        expect(DB::table('audit_events')->where('refusal', 'four_eyes:submit')->exists())->toBeTrue();

        Queue::fake();
        engine()->perform($errand, toyWorker(), S::Decide, O::Approved);

        expect($errand->state)->toBe('approved');
    });

    it('requires a reason where the outcome says so, and keeps it', function (): void {
        $errand = submittedErrand();
        $decider = toyWorker();

        expect(fn () => engine()->perform($errand, $decider, S::Decide, O::Declined))->toThrow(ValidationException::class);

        engine()->perform($errand, $decider, S::Decide, O::Declined, ['reason' => '  Not needed this term.  ']);

        expect(DB::table('journey_step_completions')->where('subject_id', $errand->id)->where('step', 'decide')->value('reason'))
            ->toBe('Not needed this term.')
            ->and(DB::table('toy_decisions')->where('errand_id', $errand->id)->value('reason'))->toBe('Not needed this term.');
    });

    it('refuses a stale record: the state changed after it was read', function (): void {
        $errand = toyErrand();
        $stale = ToyErrand::query()->findOrFail($errand->id);

        engine()->perform($errand, toyWorker(), S::Submit, O::Submitted);

        expect(fn () => engine()->perform($stale, toyWorker(), S::Submit, O::Submitted))
            ->toThrow(fn (Problem $p) => expect($p->problemCode)->toBe('state_changed'));
    });

    it('emits the declared event into the outbox', function (): void {
        Queue::fake();
        $errand = submittedErrand();
        engine()->perform($errand, toyWorker(), S::Decide, O::Approved);
        engine()->perform($errand, toyWorker(), S::Complete, O::Done);

        $event = DB::table('outbox_events')->where('name', 'toy.errand_completed')->orderByDesc('id')->first();

        expect(json_decode((string) $event?->payload, true))->toEqual(['errand_id' => $errand->id, 'state' => 'done']);
    });
});

describe('effects', function (): void {
    it('applies internal effects in the transaction, and after-commit effects only after commit', function (): void {
        Queue::fake();
        $errand = submittedErrand();

        $receipt = engine()->perform($errand, toyWorker(), S::Decide, O::Approved);

        expect(RecordDecision::$applied)->toBe(1)
            ->and($receipt->produced[RecordDecision::class] ?? null)->not->toBeNull()
            ->and(TellRequester::$calls)->toBe([]);

        Queue::assertPushed(ApplyAfterCommitEffect::class, 1);
    });

    it('applies an after-commit effect once, however often the job runs', function (): void {
        Queue::fake();
        $errand = submittedErrand();
        $receipt = engine()->perform($errand, toyWorker(), S::Decide, O::Approved);

        /** @var ApplyAfterCommitEffect $job */
        $job = Queue::pushed(ApplyAfterCommitEffect::class)->first();

        $job->handle(engine());
        $job->handle(engine());

        expect(TellRequester::$calls)->toHaveCount(1)
            ->and(TellRequester::$calls[0]['decision'])->toBe($receipt->produced[RecordDecision::class])
            ->and(DB::table('effect_applications')->where('subject_id', $errand->id)->count())->toBe(3);
    });

    it('keeps nothing and sends nothing when an internal effect fails', function (): void {
        Queue::fake();
        $errand = submittedErrand();
        RecordDecision::$fail = true;

        expect(fn () => engine()->perform($errand, toyWorker(), S::Decide, O::Approved))->toThrow(RuntimeException::class);

        expect($errand->fresh()?->state)->toBe('submitted')
            ->and(DB::table('journey_step_completions')->where('subject_id', $errand->id)->count())->toBe(1)
            ->and(DB::table('effect_applications')->where('subject_id', $errand->id)->count())->toBe(0);

        Queue::assertNotPushed(ApplyAfterCommitEffect::class);
    });

    it('applies the effects again on the next round of a journey that loops', function (): void {
        $errand = submittedErrand();
        engine()->perform($errand, toyWorker(), S::Decide, O::Declined, ['reason' => 'First round.']);
        engine()->perform($errand, toyWorker(), S::Reopen, O::Reopened);
        engine()->perform($errand, toyWorker(), S::Submit, O::Submitted);
        engine()->perform($errand, toyWorker(), S::Decide, O::Declined, ['reason' => 'Second round.']);

        expect(RecordDecision::$applied)->toBe(2)
            ->and(DB::table('toy_decisions')->where('errand_id', $errand->id)->count())->toBe(2);
    });
});

describe('the state column', function (): void {
    it('cannot be changed except by the engine', function (): void {
        $errand = toyErrand();

        expect(fn () => DB::transaction(fn () => DB::table('toy_errands')->where('id', $errand->id)->update(['state' => 'done'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('toy_errands')->where('id', $errand->id)->update(['journey_ref' => 'toy.errand@2026.2'])))
            ->toThrow(QueryException::class);

        DB::table('toy_errands')->where('id', $errand->id)->update(['title' => 'A new title']);

        expect($errand->fresh()?->title)->toBe('A new title');
    });

    it('stays guarded after the engine has written it in the same transaction', function (): void {
        $errand = toyErrand();

        expect(fn () => DB::transaction(function () use ($errand): void {
            engine()->perform($errand, toyWorker(), S::Submit, O::Submitted);
            DB::table('toy_errands')->where('id', $errand->id)->update(['state' => 'done']);
        }))->toThrow(QueryException::class);
    });
});

describe('projections', function (): void {
    it('lists the available actions with permission and the reason a gate is closed', function (): void {
        $long = toyErrand(str_repeat('A long title ', 5));
        $actions = engine()->available($long, toyWorker());

        expect($actions)->toHaveCount(1)
            ->and($actions[0]->step)->toBe('submit')
            ->and($actions[0]->permitted)->toBeTrue()
            ->and($actions[0]->blockedReason)->toBe('The title is longer than 40 characters.');

        $errand = toyErrand();
        $outsider = engine()->available($errand, toyWorker([ToyPermission::Complete]));

        expect($outsider[0]->permitted)->toBeFalse()
            ->and($outsider[0]->blockedReason)->toBeNull();
    });

    it('presents the state label, the ladder and the actions for a resource', function (): void {
        $errand = submittedErrand();
        $view = app(Projection::class)->present($errand, toyWorker());

        expect($view['state'])->toBe('submitted')
            ->and($view['state_label'])->toBe('Submitted')
            ->and(array_column($view['ladder'], 'current', 'stage'))->toBe(['request' => false, 'decision' => true, 'completion' => false])
            ->and(array_column($view['available_actions'][0]['outcomes'], 'outcome'))->toBe(['approved', 'declined'])
            ->and($view['available_actions'][0]['outcomes'][1]['requires_reason'])->toBeTrue();
    });

    it('tells whether a record has reached a milestone', function (): void {
        Queue::fake();
        $errand = submittedErrand();

        expect(engine()->hasReached($errand, Milestone::Decided))->toBeFalse();

        engine()->perform($errand, toyWorker(), S::Decide, O::Approved);
        engine()->perform($errand, toyWorker(), S::Complete, O::Done);

        expect(engine()->hasReached($errand, Milestone::Decided))->toBeTrue();
    });
});
