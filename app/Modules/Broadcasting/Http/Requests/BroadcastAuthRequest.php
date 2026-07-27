<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of POST /api/v1/broadcasting/auth (§3.1 step 2).
 *
 * Both fields come straight off an untrusted client. `socket_id` is echoed
 * into the signed string, so it is bounded and restricted to the `123.456`
 * shape the Pusher protocol defines — an unbounded value would let a caller
 * choose most of the HMAC input. `channel_name` is length-capped here and
 * parsed strictly in ChannelPattern.
 */
final class BroadcastAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Identity is `auth:sanctum`'s job; the per-channel decision is
        // ChannelAuthorizer's, and it needs the parsed channel name to make it.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:164'],
        ];
    }

    public function socketId(): string
    {
        return (string) $this->input('socket_id');
    }

    /** The channel exactly as sent — the signature must cover this spelling. */
    public function channelName(): string
    {
        return (string) $this->input('channel_name');
    }
}
