<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<SystemAdmin>
 */
class SystemAdminFactory extends Factory
{
    protected $model = SystemAdmin::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'super_admin',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }
}

