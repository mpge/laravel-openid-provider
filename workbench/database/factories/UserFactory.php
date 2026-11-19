<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $given = fake()->firstName();
        $family = fake()->lastName();

        return [
            'name' => "{$given} {$family}",
            'given_name' => $given,
            'family_name' => $family,
            'username' => Str::lower($given).'.'.Str::random(6),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
        ];
    }
}
