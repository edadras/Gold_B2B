<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Modules\Shared\Http\Support\Cursor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /notifications — cursor pagination per docs/05-api/01-conventions.md §1.8.
 *
 * Shape only. The actual decoding is Shared\Http\Support\Cursor's job, and it
 * treats an unreadable cursor as "start from the beginning" rather than as an
 * error — a pagination hint the client did not compose by hand should not be
 * able to produce a 422 the client cannot recover from. These rules exist to
 * catch the honest mistakes (`limit=0`, `limit=5000`) with a field error.
 */
final class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Cursor::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:500'],
        ];
    }
}
