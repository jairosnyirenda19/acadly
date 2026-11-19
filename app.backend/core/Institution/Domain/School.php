<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Domain;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The school: the one row of `institution`. Written only by the Institution Actions.
 *
 * @property string $id
 * @property string $name
 * @property string|null $short_name
 * @property string|null $code
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $district
 * @property string|null $country
 * @property string|null $physical_address
 * @property string|null $postal_address
 * @property string|null $logo_document_id
 * @property string|null $app_name
 * @property string|null $brand_main
 * @property string|null $brand_accent
 * @property bool $accent_last_word
 * @property CarbonImmutable|null $opened_at
 * @property string|null $opened_by
 */
final class School extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'institution';

    protected $guarded = [];

    protected $casts = ['opened_at' => 'immutable_datetime', 'accent_last_word' => 'boolean'];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null;
    }

    public function auditType(): string
    {
        return 'institution';
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
        return [
            'name' => FieldClass::Plain, 'short_name' => FieldClass::Plain, 'code' => FieldClass::Plain,
            'email' => FieldClass::Plain, 'phone' => FieldClass::Plain, 'website' => FieldClass::Plain,
            'district' => FieldClass::Plain, 'country' => FieldClass::Plain,
            'physical_address' => FieldClass::Plain, 'postal_address' => FieldClass::Plain,
        ];
    }
}
