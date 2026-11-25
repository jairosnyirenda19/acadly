<?php

declare(strict_types=1);

namespace Acadly\Kernel\Operations;

use Acadly\Kernel\Notify\NoticeCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks a school's server before it opens, and after each release (D-112).
 * Every line says what is wrong and what to set. It prints no secret value.
 * It fails when anything a school cannot run safely without is missing.
 */
final class PreflightCommand extends Command
{
    protected $signature = 'acadly:preflight {--running : Also require the scheduler and the queue worker to be running}';

    protected $description = 'Check that this server is set up safely for a school.';

    /** @var list<array{bool, bool, string, string}> required, passed, check, advice */
    private array $lines = [];

    public function handle(NoticeCatalogue $catalogue, Heartbeats $heartbeats): int
    {
        $url = fn (mixed $v): bool => is_string($v) && str_starts_with($v, 'https://');
        $mail = config('mail.default');
        $sms = config('acadly.sms.driver');
        $scan = config('acadly.documents.scan');

        $this->check(true, config('app.env') === 'production', 'The server runs as production', 'Set APP_ENV=production.');
        $this->check(true, config('app.debug') === false, 'Error details are hidden', 'Set APP_DEBUG=false.');
        $this->check(true, is_string(config('app.key')) && config('app.key') !== '', 'The application key is set', 'Run php artisan key:generate once, and keep the key with the backups.');
        $this->check(true, $url(config('app.url')) && $url(config('acadly.frontend_url')) && $url(config('acadly.portal_url')) && $url(config('acadly.student_url')),
            'Every address uses HTTPS', 'Set APP_URL, ACADLY_FRONTEND_URL, ACADLY_PORTAL_URL and ACADLY_STUDENT_URL to https:// addresses.');
        $this->check(true, config('session.secure') === true, 'Sign-in cookies are sent over HTTPS only', 'Set SESSION_SECURE_COOKIE=true.');
        $this->check(true, config('acadly.trusted_proxies') !== [], 'The web server in front is trusted', 'Set ACADLY_TRUSTED_PROXIES to the address of the web server in front (for example 127.0.0.1).');
        $this->check(true, is_string(config('acadly.documents.key')) && config('acadly.documents.key') !== '', 'Documents have their own key', 'Set ACADLY_DOCUMENTS_KEY (base64 of 32 random bytes), and keep it with the backups.');
        $this->check(true, $scan === 'clamav' && $this->reachable(), 'Uploads are scanned for viruses', 'Set ACADLY_VIRUS_SCAN=clamav and start the ClamAV daemon at CLAMAV_HOST:CLAMAV_PORT.');
        $this->check(true, $sms !== 'log', 'Text messages are sent', 'Set ACADLY_SMS_DRIVER=school and choose the provider on the Messages page.');
        $this->check(true, ! in_array($mail, ['log', 'array'], true), 'Email is sent', 'Set MAIL_MAILER and the SMTP settings.');
        $this->check(true, config('queue.default') !== 'sync', 'Slow work goes to the queue', 'Set QUEUE_CONNECTION=database.');
        $this->check(true, $this->safe(fn (): bool => DatabaseRole::safe()), 'The application cannot change the database structure', 'Connect DB_USERNAME as acadly_app, never as the owner. See infra/README-database.md.');
        $this->check(true, $this->safe(fn (): bool => DB::connection('pgsql_owner')->selectOne('SELECT 1 AS one') !== null), 'The owner connection works for releases', 'Set DB_OWNER_USERNAME and DB_OWNER_PASSWORD.');
        $synced = fn (): bool => is_string($hash = DB::table('system_sync_marks')->where('name', 'notify.catalogue')->value('hash')) && hash_equals($hash, $catalogue->hash());
        $this->check(true, $this->safe($synced),
            'The database matches this release', 'Run php artisan migrate --database=pgsql_owner --force, then php artisan acadly:sync.');
        $this->check(true, $this->python(), 'The document engine runs', 'Install Python 3 and run pip install -r workers/requirements.txt; set ACADLY_PYTHON_BIN.');
        $this->check(true, $this->writable(), 'Document storage is writable', 'Give the web server user write access to storage/app/documents.');
        $this->check(false, config('logging.channels.stack.channels') !== ['single'], 'Logs are kept by day', 'Set LOG_STACK=daily (or rotate storage/logs with logrotate).');
        $this->check(false, is_string(config('acadly.documents.soffice')) && config('acadly.documents.soffice') !== '', 'Office files get page previews', 'Install LibreOffice and set ACADLY_SOFFICE_BIN.');
        $this->check(false, config('acadly.version') !== '0.0.0-stage1', 'The release has a version', 'Set ACADLY_VERSION from the release tag.');

        $alive = $this->safe(fn (): bool => ! in_array(false, $heartbeats->alive(), true));
        $this->check((bool) $this->option('running'), $alive, 'The scheduler and the queue worker are running', 'Start the acadly-scheduler timer and the acadly-queue service. See infra/README-deploy.md.');

        $failed = 0;

        foreach ($this->lines as [$required, $passed, $check, $advice]) {
            if ($passed) {
                $this->line("  ✓ {$check}");
            } else {
                $failed += $required ? 1 : 0;
                $this->line(($required ? '  ✕ ' : '  ! ').$check.'. '.$advice);
            }
        }

        if ($failed > 0) {
            $this->error($failed === 1 ? '1 check failed. The server is not ready for a school.' : "{$failed} checks failed. The server is not ready for a school.");

            return self::FAILURE;
        }

        $this->info('The server is ready for a school.');

        return self::SUCCESS;
    }

    private function check(bool $required, bool $passed, string $check, string $advice): void
    {
        $this->lines[] = [$required, $passed, $check, $advice];
    }

    /** @param callable(): bool $test */
    private function safe(callable $test): bool
    {
        try {
            return $test();
        } catch (Throwable) {
            return false;
        }
    }

    private function reachable(): bool
    {
        $host = config('acadly.documents.clamav.host');
        $port = config('acadly.documents.clamav.port');
        $socket = @fsockopen(is_string($host) ? $host : '127.0.0.1', is_numeric($port) ? (int) $port : 3310, $code, $message, 2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function python(): bool
    {
        $python = config('acadly.pdf.python');
        $process = new Process([is_string($python) && $python !== '' ? $python : 'python3', '-I', '-c', 'import reportlab, PIL']);
        $process->setTimeout(20);

        return $this->safe(fn (): bool => $process->run() === 0);
    }

    private function writable(): bool
    {
        return $this->safe(function (): bool {
            $name = config('acadly.documents.disk');
            $disk = Storage::disk(is_string($name) ? $name : 'documents');
            $probe = '.preflight-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return true;
        });
    }
}
