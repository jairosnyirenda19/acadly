<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One document of the school: its kind, its one owner record, its title and
 * its current version. Written only by the document Actions.
 *
 * @property string $id
 * @property string $kind
 * @property string $owner_type
 * @property string $owner_id
 * @property string $title
 * @property string $classification
 * @property string|null $current_file_id
 * @property string|null $created_by
 * @property CarbonImmutable|null $trashed_at
 * @property string|null $trash_batch
 * @property CarbonImmutable|null $held_at
 * @property CarbonImmutable|null $purged_at
 */
final class Document extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'documents';

    protected $guarded = [];

    protected $casts = [
        'trashed_at' => 'immutable_datetime',
        'held_at' => 'immutable_datetime',
        'purged_at' => 'immutable_datetime',
    ];

    /**
     * Documents that are neither in the trash nor purged.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('trashed_at')->whereNull('purged_at');
    }

    public function isLive(): bool
    {
        return $this->trashed_at === null && $this->purged_at === null;
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    public function auditType(): string
    {
        return 'document';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditFields(): array
    {
        return ['title' => FieldClass::Plain, 'kind' => FieldClass::Plain];
    }
}
