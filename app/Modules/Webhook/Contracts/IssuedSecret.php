<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Contracts;

use App\Modules\Webhook\Infrastructure\Models\Webhook;

/**
 * The one and only moment a secret exists in plaintext outside the encrypted
 * column: the return value of registration and of rotation (§3.7, §3.13).
 *
 * A dedicated object rather than a plain string on the model, because the whole
 * rule is "this value is shown once". Making it a separate, deliberately
 * short-lived carrier means:
 *
 *   · the model that the Resource serialises does not hold it, so there is no
 *     way for a future field addition to leak it;
 *   · the only code that can emit it is code that explicitly asked for this
 *     object, which is two controller actions and nothing else.
 *
 * @internal to the Webhook module's HTTP layer.
 */
final readonly class IssuedSecret
{
    public function __construct(
        public Webhook $webhook,
        /** The plaintext `whsec_…`. Never persist, never log, never re-emit. */
        public string $secret,
    ) {}
}
