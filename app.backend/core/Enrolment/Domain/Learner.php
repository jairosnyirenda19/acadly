<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Domain;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One learner, one record for the whole stay and after (09 §1). The Acadly
 * ID is issued once and never changes. The audit trail names the record by
 * that ID, never by the learner's name, which is personal.
 *
 * @property string $id
 * @property string $acadly_id
 * @property string $first_name
 * @property string|null $other_names
 * @property string $surname
 * @property string $sex
 * @property CarbonImmutable $date_of_birth
 * @property string|null $photo_document_id
 */
final class Learner extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'learners';

    protected $fillable = ['acadly_id', 'first_name', 'other_names', 'surname', 'sex', 'date_of_birth'];

    public function name(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->other_names, $this->surname])));
    }

    public function auditType(): string
    {
        return 'learner';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Learner '.$this->acadly_id;
    }

    public function auditFields(): array
    {
        return [
            'first_name' => FieldClass::Personal,
            'other_names' => FieldClass::Personal,
            'surname' => FieldClass::Personal,
            'sex' => FieldClass::Personal,
            'date_of_birth' => FieldClass::Personal,
        ];
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'immutable_date'];
    }
}
