<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Domain;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An academic year of the school. The terms of each level are rows of
 * `academic_periods`.
 *
 * @property string $id
 * @property string $name
 * @property string $starts_on
 * @property string $ends_on
 */
final class AcademicYear extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'academic_years';

    protected $guarded = [];

    public function auditType(): string
    {
        return 'academic_year';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => FieldClass::Plain, 'starts_on' => FieldClass::Plain, 'ends_on' => FieldClass::Plain];
    }
}
