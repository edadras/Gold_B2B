<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Domain\KycStatus;
use App\Modules\Kyc\Infrastructure\Models\KycProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycProfile>
 */
final class KycProfileFactory extends Factory
{
    protected $model = KycProfile::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'status' => KycStatus::DRAFT,
        ];
    }

    public function status(KycStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
