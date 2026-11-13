<?php

namespace App\Models;

use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $tenant_id
 * @property bool $is_platform_admin
 * @property UserKind $kind
 * @property string $name
 * @property string|null $description
 * @property array<int, string>|null $allowed_ips
 * @property string|null $email
 * @property string|null $username
 * @property string|null $phone
 * @property string|null $locale
 * @property UserStatus $status
 * @property string $password
 * @property Carbon|null $password_changed_at
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property-read Tenant|null $tenant
 */
#[Fillable(['tenant_id', 'kind', 'name', 'description', 'allowed_ips', 'email', 'username', 'phone', 'locale', 'avatar_path', 'status', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuditsChanges, BelongsToTenant, HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_platform_admin' => false,
        'kind' => 'person',
        'allowed_ips' => null,
        'status' => 'active',
        'failed_login_attempts' => 0,
    ];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'kind' => UserKind::class,
            'allowed_ips' => 'array',
            'status' => UserStatus::class,
        ];
    }

    /**
     * People only, without the organization's integration accounts.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function people(Builder $query): void
    {
        $query->where($query->qualifyColumn('kind'), UserKind::Person);
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function integrations(Builder $query): void
    {
        $query->where($query->qualifyColumn('kind'), UserKind::Integration);
    }

    public function isIntegration(): bool
    {
        return $this->kind === UserKind::Integration;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnly(['password_changed_at', 'two_factor_confirmed_at'])
            ->logExcept(['tenant_id', 'avatar_path', 'password'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}
