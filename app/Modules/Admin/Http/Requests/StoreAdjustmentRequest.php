<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Admin\Infrastructure\Models\LedgerAdjustmentRequest as AdjustmentModel;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The §1.10 form. Every field the doc marks required is required here, and the
 * 50-character floor on the reason is the doc's own `minLength(50)`.
 *
 * `amount` is an integer in the asset's smallest unit — milligrams of fine gold
 * or rial. `integer` rather than `numeric`, deliberately: a decimal here would
 * be silently truncated and the ledger would end up off by the fraction.
 */
final class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'min:1'],
            'asset_type' => ['required', 'string', 'in:'.implode(',', AdjustmentAsset::values())],
            'amount' => ['required', 'integer', 'not_in:0'],
            'offset_account' => ['required', 'string', 'in:'.implode(',', OffsetAccount::values())],
            'reason' => ['required', 'string', 'min:'.AdjustmentModel::MIN_REASON_LENGTH, 'max:5000'],
            'supporting_document' => ['required', 'file', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.min' => 'دلیل اصلاح باید دست‌کم :min نویسه باشد.',
            'supporting_document.required' => 'بارگذاری مستند پشتیبان اجباری است.',
            'amount.not_in' => 'مبلغ اصلاح نمی‌تواند صفر باشد.',
        ];
    }
}
