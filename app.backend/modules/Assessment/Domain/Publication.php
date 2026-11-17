<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Domain;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of a class's published term results (D-080). Never changed
 * once written: a correction makes the next version, and every version is kept.
 *
 * @property string $id
 * @property string $class_id
 * @property string $period_id
 * @property int $version
 * @property string|null $reason
 * @property string $fingerprint
 * @property string $published_by
 */
final class Publication extends Model implements Auditable
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'result_publications';

    protected $fillable = ['class_id', 'period_id', 'version', 'reason', 'fingerprint', 'settings', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'settings' => 'array'];
    }

    public function auditType(): string
    {
        return 'result_publication';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Term results, version '.$this->version;
    }

    public function auditFields(): array
    {
        return ['version' => FieldClass::Plain, 'reason' => FieldClass::Plain];
    }
}
