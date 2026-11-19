<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment;

use Acadly\Core\Enrolment\Application\Actions\FamilyActions;
use Acadly\Core\Enrolment\Application\DetailsApproval;
use Acadly\Core\Enrolment\Application\Directory;
use Acadly\Core\Enrolment\Application\Families;
use Acadly\Core\Enrolment\Application\LearnerFigures;
use Acadly\Core\Enrolment\Application\LearnerPlaces;
use Acadly\Core\Enrolment\Application\LearnersGrid;
use Acadly\Core\Enrolment\Application\Progression;
use Acadly\Core\Enrolment\Application\Search\ClassesSearch;
use Acadly\Core\Enrolment\Application\Search\FamiliesSearch;
use Acadly\Core\Enrolment\Application\Search\LearnersSearch;
use Acadly\Core\Enrolment\Application\Search\VisibleClasses;
use Acadly\Core\Enrolment\Console\YearStartCommand;
use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Grid\Grids;
use Acadly\Kernel\Identity\FamilyAccounts;
use Acadly\Kernel\Identity\PhoneHolders;
use Acadly\Kernel\Search\SearchSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Learners: tables, routes, and where each learner is. */
final class EnrolmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerPlaces::class);
        $this->app->bind(EnrolmentDirectory::class, Directory::class);
        $this->app->scoped(Families::class);
        $this->app->when(Families::class)->needs('$lines')->giveTagged(FamilyLine::TAG);
        $this->app->bind(Guardianship::class, Families::class);
        $this->app->bind(PhoneHolders::class, Families::class);
        $this->app->bind(FamilyAccounts::class, FamilyActions::class);
        $this->app->tag([LearnersGrid::class], Grids::TAG);
        $this->app->tag([LearnerFigures::class], DivisionFigures::TAG);
        $this->app->tag([DetailsApproval::class], ApprovalTopic::TAG);
        $this->app->when(Progression::class)->needs('$checks')->giveTagged(YearEndCheck::TAG);
        $this->app->scoped(VisibleClasses::class);
        $this->app->tag([LearnersSearch::class, ClassesSearch::class, FamiliesSearch::class], SearchSource::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([YearStartCommand::class]);
        }

        // Each morning, the year-end outcomes whose school year has started take effect (D-088).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('learners:year-start')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
        });
    }
}
