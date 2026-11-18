<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A login account (docs/architecture/03-deployment-campus-access.md §4).
 *
 * An account is not a person and holds no role by itself. What it may do comes
 * from role assignments held at a scope (Acadly\Kernel\Access).
 *
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $password
 * @property AccountStatus $status
 * @property CarbonImmutable|null $last_signed_in_at
 * @property string|null $two_step_secret
 * @property CarbonImmutable|null $two_step_on_at
 * @property int|null $two_step_last_step
 * @property CarbonImmutable|null $password_changed_at
 */
final class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'status'];

    protected $hidden = ['password', 'remember_token', 'two_step_secret'];

    protected $attributes = ['status' => 'active'];

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function auditType(): string
    {
        return 'account';
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
            'name' => FieldClass::Plain,
            'email' => FieldClass::Personal,
            'phone' => FieldClass::Personal,
            'password' => FieldClass::Secret,
            'status' => FieldClass::Plain,
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return Attribute<string|null, string|null> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value !== null ? mb_strtolower(trim($value)) : null);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'two_step_secret' => 'encrypted',
            'two_step_on_at' => 'immutable_datetime',
            'two_step_last_step' => 'integer',
            'password_changed_at' => 'immutable_datetime',
        ];
    }
}
