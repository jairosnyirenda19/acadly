<?php

declare(strict_types=1);

namespace Acadly\Kernel;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\FlatScopeHierarchy;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\AuditCatalogue;
use Acadly\Kernel\Console\AuditSealCommand;
use Acadly\Kernel\Console\AuditVerifyCommand;
use Acadly\Kernel\Console\CreateAdministratorCommand;
use Acadly\Kernel\Console\PublishEventsCommand;
use Acadly\Kernel\Console\SyncCommand;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Support\Clock;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/** Identity and access: the kernel services every module relies on. */
final class KernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class);

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

        $this->app->bindIf(ScopeHierarchy::class, FlatScopeHierarchy::class, true);

        // One instance per request: it remembers the grants it has loaded.
        $this->app->scoped(Access::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/Identity/Database/migrations',
            __DIR__.'/Access/Database/migrations',
            __DIR__.'/Audit/Database/migrations',
            __DIR__.'/Events/Database/migrations',
        ]);

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
            $this->commands([SyncCommand::class, CreateAdministratorCommand::class, AuditVerifyCommand::class, AuditSealCommand::class, PublishEventsCommand::class]);
        }

        // Every night: verify the audit trail, then seal its head.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:seal')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

            // Events are published after each commit; this catches anything a stopped worker left.
            $schedule->command('events:publish')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
