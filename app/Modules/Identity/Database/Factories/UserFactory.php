<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public const DEFAULT_PASSWORD = 'correct-horse-battery-staple';

    private static int $sequence = 0;

    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'organization_id' => Organization::factory(),
            'full_name' => $this->faker->name(),
            'mobile' => '98913'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
            'password_changed_at' => now(),
            'status' => UserStatus::ACTIVE,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (): array => ['status' => UserStatus::PENDING]);
    }

    public function withTwoFactor(string $secret): self
    {
        return $this->state(fn (): array => [
            'two_factor_secret_enc' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }
}
