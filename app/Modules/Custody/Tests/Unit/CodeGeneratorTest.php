<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Unit;

use App\Modules\Custody\Domain\ValueObjects\AssayCode;
use App\Modules\Custody\Domain\ValueObjects\LotCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Codes shown in the docs: GL-00001287, AS-00004421. */
#[Group('custody')]
final class CodeGeneratorTest extends TestCase
{
    #[Test]
    public function it_formats_lot_codes_the_way_the_documents_do(): void
    {
        $this->assertSame('GL-00001287', LotCode::forSequence(1287)->value);
        $this->assertSame('GL-00001512', LotCode::forSequence(1512)->value);
        $this->assertSame('GL-00000001', LotCode::forSequence(1)->value);
    }

    #[Test]
    public function it_formats_assay_codes_the_way_the_documents_do(): void
    {
        $this->assertSame('AS-00004421', AssayCode::forSequence(4421)->value);
        $this->assertSame('AS-00004599', AssayCode::forSequence(4599)->value);
    }

    #[Test]
    public function a_code_round_trips_back_to_its_sequence(): void
    {
        $this->assertSame(1287, LotCode::fromString('GL-00001287')->sequence);
        $this->assertSame(4421, AssayCode::fromString('AS-00004421')->sequence);
    }

    #[Test]
    public function it_rejects_a_foreign_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LotCode::fromString('AS-00001287');
    }

    #[Test]
    public function it_rejects_a_non_numeric_sequence(): void
    {
        $this->assertFalse(LotCode::isValid('GL-ABCDEFGH'));
        $this->assertFalse(AssayCode::isValid('AS-'));
        $this->assertTrue(LotCode::isValid('GL-00000042'));
    }

    #[Test]
    public function placeholders_are_unique_and_never_valid_codes(): void
    {
        $first = LotCode::placeholder();
        $second = LotCode::placeholder();

        $this->assertNotSame($first, $second);
        $this->assertFalse(LotCode::isValid($first));
    }
}
