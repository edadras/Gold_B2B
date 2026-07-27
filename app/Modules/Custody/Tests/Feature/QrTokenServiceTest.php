<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Feature;

use App\Modules\Custody\Application\AssayService;
use App\Modules\Custody\Application\Commands\RecordAssayCommand;
use App\Modules\Custody\Application\QrTokenService;
use App\Modules\Custody\Contracts\DTO\PublicLotView;
use App\Modules\Custody\Domain\Enums\AssayMethod;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Infrastructure\Models\AssayModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Tests\Support\CustodyTestCase;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Public QR verification — docs/03-domain/02-gold-lot-assay.md §2.8.
 *
 * The security rule under test: the unauthenticated payload never carries the
 * owner, a price or a counterparty.
 */
#[Group('custody')]
#[Group('qr')]
final class QrTokenServiceTest extends CustodyTestCase
{
    /**
     * Matched against the snake_case segments of a field name, so
     * "serial_number" is fine but "amount_rial" or "owner_id" is not.
     */
    private const FORBIDDEN = ['owner', 'organization', 'org', 'price', 'rial',
        'counterparty', 'buyer', 'seller', 'trade', 'settlement', 'value', 'cost'];

    /**
     * Split camelCase or snake_case into lower-case words.
     *
     * @return list<string>
     */
    private static function segmentsOf(string $name): array
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        return array_values(array_filter(explode('_', $snake)));
    }

    private function service(): QrTokenService
    {
        return app(QrTokenService::class);
    }

    #[Test]
    public function the_public_payload_has_no_owner_field(): void
    {
        $lot = $this->makeLot(grossMg: 127_420, purityX10: 7_500, ownerOrgId: 184);

        $view = $this->service()->lookup((string) $lot->qr_token);

        $this->assertInstanceOf(PublicLotView::class, $view);

        $payload = $view->toArray();

        $this->assertArrayNotHasKey('owner_organization_id', $payload);
        $this->assertArrayNotHasKey('owner', $payload);

        foreach (array_keys($payload) as $key) {
            foreach (self::segmentsOf((string) $key) as $segment) {
                $this->assertNotContains(
                    $segment,
                    self::FORBIDDEN,
                    "public QR payload exposes a forbidden field: {$key}",
                );
            }
        }

        // Belt and braces: the owner id must not leak through any value either.
        $this->assertStringNotContainsString('184', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function the_public_view_object_itself_declares_no_sensitive_property(): void
    {
        $properties = (new ReflectionClass(PublicLotView::class))->getProperties();

        foreach ($properties as $property) {
            foreach (self::segmentsOf($property->getName()) as $segment) {
                $this->assertNotContains(
                    $segment,
                    self::FORBIDDEN,
                    "PublicLotView declares a forbidden property: {$property->getName()}",
                );
            }
        }
    }

    #[Test]
    public function it_shows_the_documented_public_fields(): void
    {
        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 127_420, purityX10: 7_500);

        app(AssayService::class)->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'IR-LAB03-2025-8821',
            method: AssayMethod::FIRE_ASSAY,
            grossWeight: Weight::fromMilligrams(127_420),
            purity: Purity::fromScaled(7_500),
            assayedAt: '2025-11-14 10:30:00',
            recordedByUserId: 1,
        ));

        $view = $this->service()->lookup((string) $lot->fresh()->qr_token);

        $this->assertNotNull($view);
        $this->assertSame($lot->lot_code, $view->lotCode);
        $this->assertSame(127_420, $view->grossWeightMg);
        $this->assertSame(7_500, $view->purityX10);
        $this->assertSame(95_565, $view->fineWeightMg);
        $this->assertSame('ASSAYED', $view->puritySource);
        $this->assertTrue($view->certified);
        $this->assertSame($lab->name, $view->laboratoryName);
        $this->assertSame('IR-LAB03-2025-8821', $view->certificateNo);
        $this->assertSame('IN_VAULT', $view->custodyState);
    }

    #[Test]
    public function scanning_a_certificate_token_lands_on_the_same_lot(): void
    {
        $lab = $this->makeLaboratory();
        $lot = $this->makeLot(grossMg: 50_000, purityX10: 9_950);

        $result = app(AssayService::class)->record(new RecordAssayCommand(
            goldLotId: (int) $lot->id,
            laboratoryId: (int) $lab->id,
            certificateNo: 'C-1',
            method: AssayMethod::XRF,
            grossWeight: Weight::fromMilligrams(50_000),
            purity: Purity::fromScaled(9_950),
            assayedAt: '2026-01-02 10:30:00',
            recordedByUserId: 1,
        ));

        $assay = AssayModel::query()->findOrFail($result->assayId);

        $view = $this->service()->lookup((string) $assay->qr_token);

        $this->assertNotNull($view);
        $this->assertSame($lot->lot_code, $view->lotCode);
        $this->assertSame($result->assayCode, $view->assayCode);
    }

    #[Test]
    public function tokens_are_256_bit_and_unique(): void
    {
        $service = $this->service();

        $tokens = [];
        for ($i = 0; $i < 200; $i++) {
            $token = $service->generate();

            $this->assertSame(64, strlen($token), 'a 256-bit token is 64 hex characters');
            $this->assertTrue(ctype_xdigit($token));

            $tokens[$token] = true;
        }

        $this->assertCount(200, $tokens, 'tokens must never repeat');
    }

    #[Test]
    public function an_unknown_or_malformed_token_resolves_to_nothing(): void
    {
        $this->assertNull($this->service()->lookup(''));
        $this->assertNull($this->service()->lookup('not-a-token'));
        $this->assertNull($this->service()->lookup(str_repeat('a', 64)));
    }

    #[Test]
    public function the_token_survives_a_change_of_owner(): void
    {
        // The token is engraved on the physical piece, so it must not rotate.
        $lot = $this->makeLot(grossMg: 10_000, purityX10: 9_950, ownerOrgId: 184);
        $token = (string) $lot->qr_token;

        GoldLotModel::query()->whereKey($lot->id)->update(['owner_organization_id' => 291]);

        $this->assertSame($token, (string) $lot->fresh()->qr_token);
        $this->assertNotNull($this->service()->lookup($token));
    }

    #[Test]
    public function the_custody_state_never_names_the_custodian(): void
    {
        $lot = $this->makeLot(
            grossMg: 10_000,
            purityX10: 9_950,
            custodianType: CustodianType::LAB,
            custodianId: 77,
        );

        $view = $this->service()->lookup((string) $lot->qr_token);

        $this->assertNotNull($view);
        $this->assertSame('AT_LABORATORY', $view->custodyState);
        $this->assertStringNotContainsString('77', json_encode($view->toArray(), JSON_THROW_ON_ERROR));
    }
}
