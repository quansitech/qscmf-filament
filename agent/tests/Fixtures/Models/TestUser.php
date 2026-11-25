<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class TestUser extends Authenticatable implements FilamentUser
{
    use HasApiTokens;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'abilities'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['abilities' => 'array'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
