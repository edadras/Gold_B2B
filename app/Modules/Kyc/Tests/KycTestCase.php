<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kyc\Application\DocumentService;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Domain\LicenseStatus;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\BusinessLicense;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Kyc\Infrastructure\Models\Signatory;
use App\Modules\Kyc\KycServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Kyc depends on Identity's tables, so both providers are registered.
 */
abstract class KycTestCase extends TestCase
{
    private static int $ibanSequence = 0;

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A compliance officer belonging to the platform organisation. */
    protected function makeComplianceOfficer(): User
    {
        $platform = Organization::factory()->platform()->create();

        return $this->makeUser($platform, [RoleEnum::COMPLIANCE_OFFICER]);
    }

    /** @param  list<RoleEnum>  $roles */
    protected function makeUser(Organization $organization, array $roles = []): User
    {
        /** @var User $user */
        $user = User::factory()->forOrganization($organization)->create();

        if ($roles !== []) {
            $roleIds = Role::query()
                ->whereIn('name', array_map(static fn (RoleEnum $r): string => $r->value, $roles))
                ->pluck('id')
                ->all();

            $pivot = [];
            foreach ($roleIds as $roleId) {
                $pivot[$roleId] = ['organization_id' => $organization->id, 'assigned_at' => now()];
            }

            $user->roles()->sync($pivot);
            $user->unsetRelation('roles');
            $user->forgetPermissionCache();
        }

        return $user;
    }

    /**
     * Gives an organisation everything KycSubmissionService::missingItems asks
     * for, so tests can focus on the flow rather than on fixtures.
     */
    protected function completeDossier(Organization $organization): void
    {
        foreach (DocumentType::requiredFor($organization->type) as $type) {
            $this->attachDocument($organization, $type);
        }

        BusinessLicense::query()->create([
            'organization_id' => $organization->id,
            'license_no' => 'LIC-'.$organization->id,
            'issuing_union' => 'اتحادیه طلا و جواهر تهران',
            'activity_type' => 'خرید و فروش طلا',
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'status' => LicenseStatus::VALID->value,
        ]);

        $account = new BankAccount;
        $account->organization_id = $organization->id;
        $account->bank_name = 'بانک ملی';
        $account->account_holder_name = (string) $organization->display_name;
        $account->setIban(self::nextValidIban());
        $account->save();

        if ($organization->type === OrganizationType::LEGAL_ENTITY) {
            $organization->registration_no ??= '12345';
            $organization->setLegalId('10101234561');
            $organization->save();

            $organization->load('users');

            Signatory::query()->create([
                'organization_id' => $organization->id,
                'full_name' => 'مدیرعامل',
                'signature_authority' => 'SOLE',
                'is_active' => true,
            ]);
        }
    }

    protected function attachDocument(Organization $organization, DocumentType $type): Document
    {
        /** @var Document $document */
        $document = Document::query()->create([
            'organization_id' => $organization->id,
            'type' => $type->value,
            'disk' => 'local',
            'storage_path' => 'kyc/'.$organization->id.'/'.strtolower($type->value).'/'.uniqid().'.jpg',
            'file_hash' => hash('sha256', $type->value.$organization->id.uniqid()),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);

        return $document;
    }

    /** Checksum-correct IR IBANs, unique per call (iban_hash is UNIQUE). */
    protected static function nextValidIban(): string
    {
        $body = str_pad((string) (++self::$ibanSequence), 22, '0', STR_PAD_LEFT);

        return self::buildIban($body);
    }

    /** Computes the two ISO 13616 check digits for a 22-digit BBAN. */
    protected static function buildIban(string $bban22): string
    {
        $remainder = 0;
        $digits = $bban22.'182700';

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = ((int) ((string) $remainder.$chunk)) % 97;
        }

        $check = str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);

        return 'IR'.$check.$bban22;
    }

    protected function documentService(): DocumentService
    {
        return $this->app->make(DocumentService::class);
    }
}
