<?php

declare(strict_types=1);

namespace Acadly\Kernel;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Access\ScopeTree;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\AuditCatalogue;
use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Console\BlueprintCompileCommand;
use Acadly\Kernel\Blueprint\Console\BlueprintDiagramCommand;
use Acadly\Kernel\Blueprint\Console\BlueprintLintCommand;
use Acadly\Kernel\Blueprint\Console\BlueprintReleaseCommand;
use Acadly\Kernel\Blueprint\Declarations;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Blueprint\Engine\Projection;
use Acadly\Kernel\Blueprint\Journey\JourneyLinter;
use Acadly\Kernel\Blueprint\Lock;
use Acadly\Kernel\Console\AuditSealCommand;
use Acadly\Kernel\Console\AuditVerifyCommand;
use Acadly\Kernel\Console\CreateAdministratorCommand;
use Acadly\Kernel\Console\InstallLicenceCommand;
use Acadly\Kernel\Console\PublishEventsCommand;
use Acadly\Kernel\Console\SyncCommand;
use Acadly\Kernel\Documents\Console\DocumentsPurgeCommand;
use Acadly\Kernel\Documents\Console\DocumentsStorageCommand;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Documents\DocumentCatalogue;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Documents\Scanning\ClamavScanner;
use Acadly\Kernel\Documents\Scanning\MissingScanner;
use Acadly\Kernel\Documents\Scanning\NoScanner;
use Acadly\Kernel\Documents\Scanning\VirusScanner;
use Acadly\Kernel\Documents\Storage\StorageMeter;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\Grids;
use Acadly\Kernel\Grid\PurgeExportsCommand;
use Acadly\Kernel\Identity\PeopleGrid;
use Acadly\Kernel\Module\Http\RequireModule;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModuleOffering;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\OfferEverything;
use Acadly\Kernel\Notify\Channels\ChannelAdapters;
use Acadly\Kernel\Notify\Channels\EmailChannel;
use Acadly\Kernel\Notify\Channels\InAppChannel;
use Acadly\Kernel\Notify\Channels\SmsChannel;
use Acadly\Kernel\Notify\Channels\SmsProvider;
use Acadly\Kernel\Notify\Console\NotifySweepCommand;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\MessagesGrid;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\NoticeDispatcher;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\School\ConfiguredSchool;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/** Identity, access, audit, events, modules, blueprints, notices and documents: the kernel services every module relies on. */
final class KernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class);

        // The school as configuration names it, until the Institution core domain binds the real one.
        $this->app->bindIf(SchoolProfile::class, ConfiguredSchool::class);

        $this->app->singleton(
            PermissionCatalogue::class,
            fn (Application $app): PermissionCatalogue => new PermissionCatalogue($app->make(ModuleRegistry::class)),
        );

        $this->app->singleton(
            AuditCatalogue::class,
            fn (Application $app): AuditCatalogue => new AuditCatalogue($app->make(ModuleRegistry::class)),
        );

        $this->app->singleton(Audit::class);

        $this->app->singleton(
            EventCatalogue::class,
            fn (Application $app): EventCatalogue => new EventCatalogue($app->make(ModuleRegistry::class)),
        );

        $this->app->singleton(Outbox::class);

        // Until Profile Blueprints exist, every installed module is offered.
        $this->app->bindIf(ModuleOffering::class, OfferEverything::class, true);

        // One instance per request: each remembers what it has read.
        $this->app->scoped(Licences::class);
        $this->app->scoped(ModuleActivation::class);

        // The scope tree, from the places each domain registers. One per request: it remembers what it has read.
        $this->app->scoped(ScopeHierarchy::class, fn (Application $app): ScopeHierarchy => new ScopeTree($app->tagged(ScopeTree::PARENTS)));

        // One instance per request: it remembers the grants it has loaded.
        $this->app->scoped(Access::class);

        // Blueprints: the compiled form is read once per process and checked against the lock.
        $this->app->singleton(Lock::class, fn (): Lock => new Lock(config()->string('acadly.blueprints.lock')));
        $this->app->singleton(
            Blueprints::class,
            fn (Application $app): Blueprints => Blueprints::load(config()->string('acadly.blueprints.compiled'), $app->make(Lock::class)),
        );
        $this->app->bind(
            BlueprintCompiler::class,
            fn (Application $app): BlueprintCompiler => new BlueprintCompiler(
                new Declarations($app->make(ModuleRegistry::class), config()->string('acadly.blueprints.profiles')),
                $app->make(JourneyLinter::class),
                $app->make(Lock::class),
            ),
        );
        $this->app->scoped(JourneyEngine::class);
        $this->app->scoped(Projection::class);

        // Notify: the catalogue is the union of the manifests. A deployment binds its own SMS provider.
        $this->app->singleton(
            NoticeCatalogue::class,
            fn (Application $app): NoticeCatalogue => new NoticeCatalogue($app->make(ModuleRegistry::class)),
        );
        $this->app->singleton(Notifier::class);
        $this->app->bind(SmsProvider::class, fn (Application $app): SmsProvider => $app->make(MessageSettings::class)->smsProvider());
        $this->app->bind(
            ChannelAdapters::class,
            fn (Application $app): ChannelAdapters => new ChannelAdapters([
                $app->make(InAppChannel::class),
                $app->make(EmailChannel::class),
                $app->make(SmsChannel::class),
            ]),
        );
        $this->app->scoped(NoticeDispatcher::class);

        // Documents: one table, one owner each. Scanning is clamd when set up, off only on purpose.
        $this->app->singleton(
            DocumentCatalogue::class,
            fn (Application $app): DocumentCatalogue => new DocumentCatalogue($app->make(ModuleRegistry::class)),
        );
        $this->app->bindIf(VirusScanner::class, function (Application $app): VirusScanner {
            $scan = config('acadly.documents.scan');

            return match (true) {
                $scan === 'clamav' => new ClamavScanner(config()->string('acadly.documents.clamav.host'),
                    config()->integer('acadly.documents.clamav.port'), config()->integer('acadly.documents.clamav.timeout')),
                $scan === 'off' && $app->environment(['local', 'testing']) => new NoScanner,
                default => new MissingScanner,
            };
        });
        $this->app->scoped(DocumentAccess::class);
        $this->app->scoped(DocumentReader::class);
        $this->app->scoped(StorageMeter::class);
        $this->app->tag([PeopleGrid::class, MessagesGrid::class], Grids::TAG);
        $this->app->scoped(Grids::class, fn (Application $app): Grids => new Grids(array_values(array_filter(iterator_to_array($app->tagged(Grids::TAG), false),
            fn (mixed $g): bool => $g instanceof GridDefinition))));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/Identity/Database/migrations',
            __DIR__.'/Access/Database/migrations',
            __DIR__.'/Audit/Database/migrations',
            __DIR__.'/Events/Database/migrations',
            __DIR__.'/Module/Database/migrations',
            __DIR__.'/Blueprint/Database/migrations',
            __DIR__.'/Notify/Database/migrations',
            __DIR__.'/Documents/Database/migrations',
            __DIR__.'/Grid/Database/migrations',
        ]);

        $this->app->make('router')->aliasMiddleware('module', RequireModule::class);

        Password::defaults(fn (): Password => Password::min(12)->max(255));

        ResetPassword::toMailUsing(function (mixed $notifiable, string $token): MailMessage {
            $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';
            $url = rtrim(config()->string('acadly.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token)
                .'&email='.urlencode($email);

            return (new MailMessage)
                ->subject('Password reset')
                ->line('A password reset was requested for this account.')
                ->action('Set a new password', $url)
                ->line('The link expires in 60 minutes. If the request was not made by the account holder, no action is required.');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class, CreateAdministratorCommand::class, AuditVerifyCommand::class, AuditSealCommand::class, PublishEventsCommand::class, InstallLicenceCommand::class,
                BlueprintCompileCommand::class, BlueprintLintCommand::class, BlueprintReleaseCommand::class, BlueprintDiagramCommand::class,
                NotifySweepCommand::class, DocumentsPurgeCommand::class, DocumentsStorageCommand::class, PurgeExportsCommand::class]);
        }

        // Every night: verify the audit trail, then seal its head.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:seal')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

            // Events are published after each commit; this catches anything a stopped worker left.
            $schedule->command('events:publish')->everyMinute()->withoutOverlapping()->onOneServer();

            // Notices a stopped worker left unresolved, and deliveries waiting too long.
            $schedule->command('notify:sweep')->everyMinute()->withoutOverlapping()->onOneServer();

            // Empty the document trash past its period; measure storage against the licence.
            $schedule->command('documents:purge')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
            $schedule->command('documents:storage')->hourly()->withoutOverlapping()->onOneServer();
            $schedule->command('grids:purge-exports')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
        });
    }
}
