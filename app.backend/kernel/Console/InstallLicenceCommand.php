<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Installs a licence issued by IQ-X. The signature must verify. */
final class InstallLicenceCommand extends Command
{
    protected $signature = 'acadly:licence {path : The licence file issued by IQ-X}';

    protected $description = 'Install a signed licence';

    public function handle(Licences $licences, Audit $audit, Clock $clock): int
    {
        $path = $this->argument('path');
        $document = is_file($path) ? file_get_contents($path) : false;

        if ($document === false) {
            $this->components->error('The licence file cannot be read.');

            return self::FAILURE;
        }

        try {
            $licence = $licences->read($document);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($document, $licence, $audit, $clock): void {
            DB::table('system_licences')->insert([
                'id' => Ids::next(),
                'serial' => $licence->serial,
                'document' => $document,
                'installed_at' => $clock->now(),
            ]);

            $audit->record(KernelAudit::LicenceInstalled, actor: Actor::system('Deployment console'), meta: [
                'serial' => $licence->serial,
                'status' => $licence->status->value,
                'modules' => implode(',', $licence->modules),
            ]);
        });

        $licences->forget();
        $expires = $licence->expiresAt?->toDateString() ?? 'no expiry date';
        $this->components->info("Licence {$licence->serial} installed ({$licence->status->value}, {$expires}).");

        return self::SUCCESS;
    }
}
