<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\RiskLevel;
use App\Modules\Identity\Infrastructure\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** Sequence used to mint unique-but-valid national ids and mobiles. */
    private static int $sequence = 0;

    public function definition(): array
    {
        $n = ++self::$sequence;
        $nationalId = self::validNationalId($n);

        return [
            'type' => OrganizationType::INDIVIDUAL,
            'status' => OrganizationStatus::PENDING,
            'display_name' => 'طلافروشی '.$this->faker->unique()->lastName(),
            'city' => 'تهران',
            'province' => 'تهران',
            'mobile' => '98912'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            'risk_level' => RiskLevel::MEDIUM,
            'national_id_enc' => $nationalId,
            'national_id_hash' => BlindIndex::forNationalId($nationalId),
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => OrganizationStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function status(OrganizationStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function platform(): self
    {
        return $this->state(fn (): array => [
            'is_platform' => true,
            'display_name' => 'اپراتور سامانه',
            'status' => OrganizationStatus::ACTIVE,
            'national_id_enc' => null,
            'national_id_hash' => null,
        ]);
    }

    public function legalEntity(): self
    {
        return $this->state(fn (): array => ['type' => OrganizationType::LEGAL_ENTITY]);
    }

    /**
     * Builds a checksum-correct 10-digit national id from a counter, so
     * factories never collide on the unique blind index.
     */
    public static function validNationalId(int $seed): string
    {
        $base = str_pad((string) ($seed % 1_000_000_000), 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $base[$i]) * (10 - $i);
        }
        $rem = $sum % 11;
        $check = $rem < 2 ? $rem : 11 - $rem;

        $id = $base.$check;

        // Repdigits are rejected by the validator; nudge the seed and retry.
        return preg_match('/^(\d)\1{9}$/', $id) === 1
            ? self::validNationalId($seed + 1)
            : $id;
    }
}
