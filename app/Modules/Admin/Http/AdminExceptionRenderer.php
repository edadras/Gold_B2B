<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http;

use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a refused business rule into a message on the form.
 *
 * `bootstrap/app.php` renders DomainException for JSON callers only — the right
 * default for an API-first application, and not this module's to change. Left
 * alone, "you cannot approve your own adjustment" reaches the operator as a
 * 500, which reads as "the system is broken" rather than "the control worked";
 * that difference decides whether a control is respected or routed around.
 *
 * Registered through the exception handler rather than as middleware, because
 * Illuminate's pipeline converts a throwable to a response at the innermost
 * pipe — a middleware wrapped around the controller never sees it.
 *
 * Scoped to `/admin` and to non-JSON requests, so the API's error contract is
 * untouched. The exception has already been recorded by whichever service threw
 * it — every refusal in this module writes a `denied` audit row first — so this
 * only presents; it swallows nothing unlogged.
 */
final class AdminExceptionRenderer
{
    public function __invoke(DomainException $e, Request $request): ?Response
    {
        if ($request->expectsJson() || ! $request->is('admin', 'admin/*')) {
            return null;
        }

        return back()->withInput()->withErrors(['domain' => $this->message($e)]);
    }

    /**
     * Prefer the exception's own explanation when it carries one.
     *
     * OperationNotPermittedException::userMessage() is a generic "you are not
     * permitted"; its `reason` says *why*, and on this panel the why is the
     * entire message.
     */
    private function message(DomainException $e): string
    {
        if (property_exists($e, 'reason') && is_string($e->reason) && $e->reason !== '') {
            return $e->reason;
        }

        return $e->userMessage();
    }
}
