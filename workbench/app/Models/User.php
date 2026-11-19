<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Mpge\OpenIdProvider\Concerns\HasOidcSubject;
use Mpge\OpenIdProvider\Contracts\OidcSubject;
use Workbench\Database\Factories\UserFactory;

/**
 * Demo user for the workbench app, the test suite and the conformance run.
 */
class User extends Authenticatable implements OAuthenticatable, OidcSubject
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasOidcSubject;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
