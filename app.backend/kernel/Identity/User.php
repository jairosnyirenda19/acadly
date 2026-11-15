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
 * @property string $email
 * @property string $password
 * @property AccountStatus $status
 * @property CarbonImmutable|null $last_signed_in_at
 */
final class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

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
            'password' => FieldClass::Secret,
            'status' => FieldClass::Plain,
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
        ];
    }
}
