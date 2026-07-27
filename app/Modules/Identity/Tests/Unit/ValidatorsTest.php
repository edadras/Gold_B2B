<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Unit;

use App\Modules\Identity\Domain\Validators\IbanValidator;
use App\Modules\Identity\Domain\Validators\LegalIdValidator;
use App\Modules\Identity\Domain\Validators\MobileNormalizer;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven vectors for the four identity validators of
 * docs/03-domain/01-identity-kyc.md §1.4.
 *
 * Pure unit tests — no database, no framework bootstrap.
 */
final class ValidatorsTest extends TestCase
{
    // ------------------------------------------------------------------
    // National id (کد ملی)
    // ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function validNationalIds(): array
    {
        return [
            'leading zeros' => ['0012345679'],
            'tehran style' => ['0065432193'],
            'eleven-complement branch' => ['1123456781'],
            'remainder below two' => ['4567891236'],
            'check digit zero' => ['9876543210'],
            'all zeros but one' => ['0000000019'],
            'sequential' => ['1234567891'],
        ];
    }

    #[DataProvider('validNationalIds')]
    public function test_accepts_valid_national_ids(string $id): void
    {
        $this->assertTrue(NationalIdValidator::isValid($id), "expected {$id} to be valid");
    }

    /** @return array<string, array{string}> */
    public static function invalidNationalIds(): array
    {
        return [
            'wrong check digit' => ['0012345678'],
            'repdigit zeros' => ['0000000000'],
            'repdigit ones' => ['1111111111'],
            'repdigit fives' => ['5555555555'],
            'too long' => ['00123456790'],
            'letters' => ['00123abc79'],
            'empty' => [''],
            'check digit off by one' => ['9876543211'],
        ];
    }

    #[DataProvider('invalidNationalIds')]
    public function test_rejects_invalid_national_ids(string $id): void
    {
        $this->assertFalse(NationalIdValidator::isValid($id), "expected {$id} to be invalid");
    }

    public function test_national_id_accepts_persian_digits_and_pads(): void
    {
        // ۰۰۱۲۳۴۵۶۷۹ in Persian digits.
        $this->assertTrue(NationalIdValidator::isValid('۰۰۱۲۳۴۵۶۷۹'));

        // Spreadsheets strip leading zeros; we put them back.
        $this->assertSame('0012345679', NationalIdValidator::normalize('12345679'));
        $this->assertTrue(NationalIdValidator::isValid('12345679'));
    }

    public function test_national_id_masking_hides_the_middle(): void
    {
        $this->assertSame('00•••••••9', NationalIdValidator::mask('0012345679'));
    }

    // ------------------------------------------------------------------
    // Legal entity id (شناسه ملی)
    // ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function validLegalIds(): array
    {
        return [
            'tehran registrar' => ['10101234565'],
            'company a' => ['10302737018'],
            'company b' => ['10630273700'],
            'recent registration' => ['14001234562'],
            'minimal prefix' => ['10000000010'],
            'repdigit fours' => ['44444444441'],
            'alternating' => ['10101010103'],
        ];
    }

    #[DataProvider('validLegalIds')]
    public function test_accepts_valid_legal_ids(string $id): void
    {
        $this->assertTrue(LegalIdValidator::isValid($id), "expected {$id} to be valid");
    }

    /** @return array<string, array{string}> */
    public static function invalidLegalIds(): array
    {
        return [
            'wrong check digit' => ['10101234566'],
            'tenth digit is five' => ['10101234550'],
            'zero prefix' => ['00000000000'],
            'ten digits only' => ['1010123456'],
            'twelve digits' => ['101012345650'],
            'letters' => ['1010123456X'],
            'empty' => [''],
            'check digit off by one' => ['10302737019'],
        ];
    }

    #[DataProvider('invalidLegalIds')]
    public function test_rejects_invalid_legal_ids(string $id): void
    {
        $this->assertFalse(LegalIdValidator::isValid($id), "expected {$id} to be invalid");
    }

    public function test_legal_id_accepts_persian_digits(): void
    {
        $this->assertTrue(LegalIdValidator::isValid('۱۰۱۰۱۲۳۴۵۶۵'));
    }

    // ------------------------------------------------------------------
    // IBAN (شبا)
    // ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function validIbans(): array
    {
        return [
            'bank melli' => ['IR980170000000108888888801'],
            'bank saderat' => ['IR490620000000201234567891'],
            'bank tejarat' => ['IR860550000000999999999901'],
            'all zero body' => ['IR690130000000000000000001'],
            'sequential body' => ['IR310180000000123456789012'],
            'with spaces' => ['IR98 0170 0000 0010 8888 8888 01'],
            'lowercase' => ['ir980170000000108888888801'],
        ];
    }

    #[DataProvider('validIbans')]
    public function test_accepts_valid_ibans(string $iban): void
    {
        $this->assertTrue(IbanValidator::isValid($iban), "expected {$iban} to be valid");
    }

    /** @return array<string, array{string}> */
    public static function invalidIbans(): array
    {
        return [
            'wrong check digits' => ['IR990170000000108888888801'],
            'wrong country' => ['DE980170000000108888888801'],
            'too short' => ['IR98017000000010888888880'],
            'too long' => ['IR9801700000001088888888012'],
            'no country prefix' => ['980170000000108888888801'],
            'letters in body' => ['IR98017000000010888888880A'],
            'empty' => [''],
            'transposed digits' => ['IR980170000000108888888810'],
        ];
    }

    #[DataProvider('invalidIbans')]
    public function test_rejects_invalid_ibans(string $iban): void
    {
        $this->assertFalse(IbanValidator::isValid($iban), "expected {$iban} to be invalid");
    }

    public function test_iban_accepts_persian_digits(): void
    {
        $this->assertTrue(IbanValidator::isValid('IR۹۸۰۱۷۰۰۰۰۰۰۰۱۰۸۸۸۸۸۸۸۸۰۱'));
    }

    public function test_iban_helpers(): void
    {
        $this->assertSame('017', IbanValidator::bankCode('IR980170000000108888888801'));
        $this->assertSame(
            'IR98 0170 0000 0010 8888 8888 01',
            IbanValidator::format('IR980170000000108888888801'),
        );
    }

    // ------------------------------------------------------------------
    // Mobile
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function normalisableMobiles(): array
    {
        return [
            'local with trunk zero' => ['09121234567', '989121234567'],
            'international plus' => ['+989121234567', '989121234567'],
            'international double zero' => ['00989121234567', '989121234567'],
            'bare national' => ['9121234567', '989121234567'],
            'already canonical' => ['989121234567', '989121234567'],
            'with spaces and dashes' => ['0912 123 4567', '989121234567'],
            'with parentheses' => ['(0912) 123-4567', '989121234567'],
            'persian digits' => ['۰۹۱۲۱۲۳۴۵۶۷', '989121234567'],
            'persian international' => ['+۹۸۹۱۲۱۲۳۴۵۶۷', '989121234567'],
            'arabic digits' => ['٠٩١٢١٢٣٤٥٦٧', '989121234567'],
            'irancell prefix' => ['09351234567', '989351234567'],
            'rightel prefix' => ['09211234567', '989211234567'],
        ];
    }

    #[DataProvider('normalisableMobiles')]
    public function test_normalises_mobiles(string $input, string $expected): void
    {
        $this->assertSame($expected, MobileNormalizer::normalize($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidMobiles(): array
    {
        return [
            'landline' => ['02112345678'],
            'too short' => ['0912123456'],
            'too long' => ['091212345678'],
            'does not start with nine' => ['08121234567'],
            'letters' => ['0912abcdefg'],
            'empty' => [''],
            'only prefix' => ['0098'],
            'foreign number' => ['+447911123456'],
        ];
    }

    #[DataProvider('invalidMobiles')]
    public function test_rejects_invalid_mobiles(string $input): void
    {
        $this->assertNull(MobileNormalizer::normalize($input), "expected {$input} to be unusable");
        $this->assertFalse(MobileNormalizer::isValid($input));
    }

    public function test_mobile_round_trips_to_local_form(): void
    {
        $this->assertSame('09121234567', MobileNormalizer::toLocal('+98 912 123 4567'));
        $this->assertSame('0912•••4567', MobileNormalizer::mask('989121234567'));
    }
}
