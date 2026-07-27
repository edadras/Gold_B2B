<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Infrastructure\Models;

use App\Modules\Settlement\Domain\PaymentMethod;
use App\Modules\Shared\ValueObjects\Rial;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment assertion and its confirmation — §5.3 pattern 2.
 *
 * The platform never holds the money (ADR-008), so a DECLARED row is only the
 * payer's word. CONFIRMED is the payee's counter-signature and the only status
 * that has moved rial in the ledger.
 *
 * @property int $id
 * @property int $settlement_id
 * @property int $amount_rial
 * @property string $status
 * @property PaymentMethod $payment_method
 */
final class PaymentModel extends Model
{
    public const DECLARED = 'DECLARED';

    public const CONFIRMED = 'CONFIRMED';

    public const REJECTED = 'REJECTED';

    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = [
        'payment_method' => PaymentMethod::class,
        'settlement_id' => 'int',
        'payer_organization_id' => 'int',
        'payee_organization_id' => 'int',
        'amount_rial' => 'int',
        'declared_by_user_id' => 'int',
        'confirmed_by_user_id' => 'int',
        'rejected_by_user_id' => 'int',
        'paid_at' => 'datetime',
        'declared_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'auto_matched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function amount(): Rial
    {
        return Rial::fromRial($this->amount_rial);
    }

    public function isPending(): bool
    {
        return $this->status === self::DECLARED;
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null && $this->receipt_path !== '';
    }
}
