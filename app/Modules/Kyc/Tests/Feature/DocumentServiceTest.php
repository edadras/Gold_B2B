<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Kyc\Domain\DocumentStatus;
use App\Modules\Kyc\Domain\DocumentType;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use App\Modules\Kyc\Infrastructure\Models\Document;
use App\Modules\Kyc\Tests\KycTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DocumentServiceTest extends KycTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->organization = Organization::factory()->create();
    }

    public function test_upload_writes_to_an_unguessable_path_and_records_the_hash(): void
    {
        $file = UploadedFile::fake()->image('national-card.jpg', 600, 400);
        $expectedHash = hash('sha256', (string) file_get_contents($file->getRealPath()));

        $document = $this->documentService()->store(
            (int) $this->organization->id,
            DocumentType::NATIONAL_CARD_FRONT,
            $file,
        );

        $this->assertSame($expectedHash, $document->file_hash);
        $this->assertSame(DocumentStatus::PENDING, $document->status);
        $this->assertSame('image/jpeg', $document->mime_type);

        // The filename is a UUID, never the user's original name.
        $this->assertMatchesRegularExpression(
            '#^kyc/'.$this->organization->id.'/national_card_front/[0-9a-f-]{36}\.jpg$#',
            $document->storage_path,
        );
        $this->assertStringNotContainsString('national-card', $document->storage_path);

        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertTrue($this->documentService()->verifyIntegrity($document));
    }

    public function test_integrity_check_detects_tampering(): void
    {
        $document = $this->documentService()->store(
            (int) $this->organization->id,
            DocumentType::BUSINESS_LICENSE,
            UploadedFile::fake()->image('license.png'),
        );

        Storage::disk('local')->put($document->storage_path, 'different bytes entirely');

        $this->assertFalse($this->documentService()->verifyIntegrity($document));
    }

    public function test_re_uploading_supersedes_the_previous_document(): void
    {
        $service = $this->documentService();

        $first = $service->store(
            (int) $this->organization->id,
            DocumentType::SELFIE_WITH_NATIONAL_CARD,
            UploadedFile::fake()->image('one.jpg'),
        );

        $second = $service->store(
            (int) $this->organization->id,
            DocumentType::SELFIE_WITH_NATIONAL_CARD,
            UploadedFile::fake()->image('two.jpg'),
        );

        // The old row is kept for the audit trail, not deleted.
        $this->assertSame(DocumentStatus::SUPERSEDED, $first->fresh()->status);
        $this->assertSame(DocumentStatus::PENDING, $second->fresh()->status);
        $this->assertSame(2, Document::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Document::query()->where('organization_id', $this->organization->id)->provided()->count());
    }

    public function test_rejects_unsupported_types_and_oversized_files(): void
    {
        $service = $this->documentService();

        $this->assertThrows(
            fn () => $service->store(
                (int) $this->organization->id,
                DocumentType::OTHER,
                UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
            ),
            InvalidArgumentException::class,
        );

        $this->assertThrows(
            fn () => $service->store(
                (int) $this->organization->id,
                DocumentType::OTHER,
                UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf'),
            ),
            InvalidArgumentException::class,
        );
    }

    public function test_verification_and_rejection_transitions(): void
    {
        $service = $this->documentService();
        $officer = $this->makeComplianceOfficer();

        $document = $service->store(
            (int) $this->organization->id,
            DocumentType::NATIONAL_CARD_BACK,
            UploadedFile::fake()->image('back.jpg'),
        );

        $verified = $service->markVerified($document, (int) $officer->id);
        $this->assertSame(DocumentStatus::VERIFIED, $verified->status);
        $this->assertSame((int) $officer->id, (int) $verified->verified_by_user_id);

        // A rejection without a stated reason is refused.
        $this->assertThrows(
            fn () => $service->markRejected($verified, (int) $officer->id, '  '),
            OperationNotPermittedException::class,
        );

        $rejected = $service->markRejected($verified, (int) $officer->id, 'ناخوانا');
        $this->assertSame(DocumentStatus::REJECTED, $rejected->status);
        $this->assertSame('ناخوانا', $rejected->rejection_reason);
    }

    public function test_bank_account_iban_is_encrypted_with_a_searchable_blind_index(): void
    {
        $iban = self::nextValidIban();

        $account = new BankAccount;
        $account->organization_id = $this->organization->id;
        $account->bank_name = 'بانک ملی';
        $account->account_holder_name = 'علی کریمی';
        $account->setIban($iban);
        $account->save();

        // Stored ciphertext, not plaintext.
        $raw = (string) DB::table('bank_accounts')
            ->where('id', $account->id)
            ->value('iban_enc');
        $this->assertStringNotContainsString($iban, $raw);

        // ...but still findable through the blind index.
        $this->assertSame(
            (int) $account->id,
            (int) BankAccount::query()->withIban($iban)->firstOrFail()->id,
        );

        $this->assertSame(substr($iban, 4, 3), $account->bank_code);
        $this->assertStringStartsWith(substr($iban, 0, 6), $account->maskedIban());
    }

    public function test_an_invalid_iban_is_refused(): void
    {
        $account = new BankAccount;

        $this->expectException(InvalidArgumentException::class);

        $account->setIban('IR990170000000108888888801');
    }
}
