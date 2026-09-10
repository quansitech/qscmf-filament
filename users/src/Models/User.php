<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Quansitech\Cmf\Users\Database\Factories\UserFactory;
use Spatie\Permission\Traits\HasRoles;

/**
 * CMF 用户模型：集成 Filament 面板访问、RBAC（HasRoles）与操作审计。
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements Auditable, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use AuditableTrait, HasFactory, HasRoles, Notifiable;

    /**
     * Attributes excluded from auditing.
     *
     * @var list<string>
     */
    protected $auditExclude = ['password', 'remember_token'];

    /**
     * 工厂走 config('cmf-users.model')，宿主继承替换模型后工厂产出继承模型。
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
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
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
