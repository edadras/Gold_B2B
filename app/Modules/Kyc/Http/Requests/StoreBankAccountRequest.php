<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Requests;

use App\Modules\Identity\Domain\Validators\IbanValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /organization/bank-accounts` — 👑 OWNER, ✍️ signed.
 *
 * The IBAN is checked here with the same mod-97 validator the model uses, so a
 * typo comes back as a field error rather than as a business exception. The
 * service still validates: it is the last line before ciphertext is written,
 * and it is reachable from places that are not this request.
 *
 * `status` and `verified_at` are not accepted — verification is the platform's
 * decision (docs/03-domain/01-identity-kyc.md §1.4).
 */
final class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'iban' => ['required', 'string', 'max:34'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_holder_name' => ['required', 'string', 'max:191'],
            'account_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
            'document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            /** @var array{iban?: string} $data */
            $data = $validator->getData();

            if (isset($data['iban']) && is_string($data['iban']) && ! IbanValidator::isValid($data['iban'])) {
                $validator->errors()->add('iban', 'شماره شبا معتبر نیست.');
            }
        });
    }
}
