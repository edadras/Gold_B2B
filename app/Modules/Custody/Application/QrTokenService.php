<?php

declare(strict_types=1);

namespace App\Modules\Custody\Application;

use App\Modules\Custody\Contracts\DTO\PublicLotView;
use App\Modules\Custody\Domain\Enums\AssayStatus;
use App\Modules\Custody\Domain\Enums\CustodianType;
use App\Modules\Custody\Infrastructure\Models\AssayModel;
use App\Modules\Custody\Infrastructure\Models\GoldLotModel;
use App\Modules\Custody\Infrastructure\Models\LaboratoryModel;
use Illuminate\Container\Container;

/**
 * QR tokens and the public verification page — docs/03-domain/02-gold-lot-assay.md §2.8.
 *
 * Security rules implemented here:
 *  - 256-bit tokens from a CSPRNG, one per lot and one per certificate
 *  - the token is engraved on the physical piece, so it never changes when
 *    ownership changes
 *  - the public payload never contains owner, price or counterparty
 *
 * Rate limiting and scan auditing belong to the HTTP layer and the Audit
 * module; this service only builds the payload.
 */
final class QrTokenService
{
    public const TOKEN_BYTES = 32;   // 256 bit

    /** Cryptographically random 64-char hex token. */
    public function generate(): string
    {
        return bin2hex(random_bytes($this->tokenBytes()));
    }

    /** Assign a token to a lot that does not have one yet. */
    public function assignToLot(GoldLotModel $lot): string
    {
        if (is_string($lot->qr_token) && $lot->qr_token !== '') {
            return $lot->qr_token;
        }

        $token = $this->generate();
        $lot->qr_token = $token;
        $lot->save();

        return $token;
    }

    public function publicUrl(string $token): string
    {
        return sprintf($this->urlTemplate(), $token);
    }

    /**
     * Resolve a scanned token. Accepts either a lot token or a certificate
     * token; both land on the same public view of the lot.
     */
    public function lookup(string $token): ?PublicLotView
    {
        if (! $this->looksLikeToken($token)) {
            return null;
        }

        $lot = GoldLotModel::query()->where('qr_token', $token)->first();

        if ($lot === null) {
            $assay = AssayModel::query()->where('qr_token', $token)->first();

            if ($assay === null) {
                return null;
            }

            $lot = GoldLotModel::query()->find($assay->gold_lot_id);
        }

        if (! $lot instanceof GoldLotModel) {
            return null;
        }

        return $this->publicView($lot);
    }

    public function publicView(GoldLotModel $lot): PublicLotView
    {
        $assay = null;
        $laboratoryName = null;

        if ($lot->current_assay_id !== null) {
            $assay = AssayModel::query()->find($lot->current_assay_id);

            if ($assay !== null) {
                $laboratoryName = LaboratoryModel::query()->find($assay->laboratory_id)?->name;
            }
        }

        return new PublicLotView(
            lotCode: (string) $lot->lot_code,
            metalType: $lot->metal_type->value,
            grossWeightMg: (int) $lot->gross_weight_mg,
            purityX10: (int) $lot->purity_x10,
            fineWeightMg: (int) $lot->fine_weight_mg,
            puritySource: $lot->purity_source->value,
            shape: $lot->shape->value,
            serialNumber: $lot->serial_number,
            hallmarkCode: $lot->hallmark_code,
            status: $lot->status->value,
            custodyState: $this->custodyState($lot->custodian_type),
            assayCode: $assay?->assay_code,
            laboratoryName: $laboratoryName,
            assayMethod: $assay?->method->value,
            assayedAt: $assay?->assayed_at?->toIso8601String(),
            certificateNo: $assay?->certificate_no,
            certified: $assay !== null && $assay->status === AssayStatus::VALID,
        );
    }

    /**
     * Coarse custody description. Deliberately does not name the custodian:
     * "in a vault" is public information, "in vault V01 box B14" is not.
     */
    private function custodyState(CustodianType $type): string
    {
        return match ($type) {
            CustodianType::VAULT => 'IN_VAULT',
            CustodianType::ORGANIZATION => 'WITH_MEMBER',
            CustodianType::LAB => 'AT_LABORATORY',
            CustodianType::IN_TRANSIT => 'IN_TRANSIT',
            CustodianType::THIRD_PARTY => 'THIRD_PARTY',
        };
    }

    private function looksLikeToken(string $token): bool
    {
        return $token !== '' && ctype_xdigit($token) && strlen($token) === $this->tokenBytes() * 2;
    }

    private function tokenBytes(): int
    {
        return (int) $this->setting('token_bytes', self::TOKEN_BYTES);
    }

    private function urlTemplate(): string
    {
        return (string) $this->setting('public_url_template', 'https://goldb2b.ir/v/%s');
    }

    private function setting(string $key, string|int $default): string|int
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        /** @var string|int $value */
        $value = $container->make('config')->get("goldb2b.custody.qr.{$key}", $default);

        return $value;
    }
}
