<?php

declare(strict_types=1);

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Http\Middleware\ForceJsonResponse;
use App\Modules\Shared\Http\Middleware\HandleIdempotency;
use App\Modules\Shared\Http\Middleware\RequireTransactionSignature;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'idempotency' => HandleIdempotency::class,
            // "✍️" in docs/05-api/02-endpoints.md. Mounted BEFORE `idempotency`
            // on every route that uses both, so a refused signature does not
            // burn the key and make the corrected retry replay the 403.
            'transaction.sign' => RequireTransactionSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Business-rule failures carry their own code, message and status.
        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                $e->errorCode(),
                $e->userMessage(),
                $e->httpStatus(),
                $e->details(),
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                'داده ورودی نامعتبر است.',
                422,
                fieldErrors: $e->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->expectsJson()
                ? ApiResponse::error('AUTH_TOKEN_INVALID', 'برای این عملیات باید وارد شوید.', 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return $request->expectsJson()
                ? ApiResponse::error('FORBIDDEN_ROLE', 'شما مجاز به انجام این عملیات نیستید.', 403)
                : null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return $request->expectsJson()
                ? ApiResponse::error('RESOURCE_NOT_FOUND', 'منبع مورد نظر یافت نشد.', 404)
                : null;
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return $request->expectsJson()
                ? ApiResponse::error('RATE_LIMIT_EXCEEDED', 'تعداد درخواست بیش از حد مجاز است.', 429)
                : null;
        });

        // Anything else: never leak internals in production.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() || ! app()->isProduction()) {
                return null;
            }

            report($e);

            return ApiResponse::error(
                'INTERNAL_ERROR',
                'خطای داخلی رخ داد. لطفاً بعداً تلاش کنید.',
                500,
            );
        });
    })
    ->create();
