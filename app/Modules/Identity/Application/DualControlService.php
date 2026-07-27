<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\DualControlStatus;
use App\Modules\Identity\Domain\Exceptions\DualControlViolationException;
use App\Modules\Identity\Infrastructure\Models\DualControlRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;

/**
 * Generic maker/checker gate (docs/01-product/01-personas-roles.md §1.6).
 *
 * Callers stay agnostic of what is being approved: they record an action name
 * and a payload, and later read the approved request back to execute it. The
 * only rule this class enforces absolutely is `maker != checker`, which is also
 * a CHECK constraint on the table — application and database both refuse.
 */
final class DualControlService
{
    /** Requests older than this can no longer be approved. */
    public const DEFAULT_TTL_HOURS = 24;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function request(
        string $action,
        array $payload,
        int $makerUserId,
        ?int $organizationId = null,
        ?string $makerNote = null,
        ?int $ttlHours = null,
    ): DualControlRequest {
        $maker = User::query()->findOrFail($makerUserId);

        $organizationId ??= (int) $maker->organization_id;

        if ((int) $maker->organization_id !== (int) $organizationId && ! $maker->isPlatformStaff()) {
            throw new OperationNotPermittedException('tenancy_mismatch');
        }

        $ttlHours ??= self::DEFAULT_TTL_HOURS;

        return DualControlRequest::query()->create([
            'organization_id' => $organizationId,
            'action' => $action,
            'payload' => $payload,
            'payload_hash' => $this->payloadHash($action, $payload),
            'maker_user_id' => $makerUserId,
            'maker_note' => $makerNote,
            'requested_at' => now(),
            'status' => DualControlStatus::PENDING,
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }

    /**
     * Approve a pending request.
     *
     * The row is locked for the duration so two checkers racing each other
     * cannot both see PENDING and both approve.
     */
    public function approve(int $requestId, int $checkerUserId, ?string $checkerNote = null): DualControlRequest
    {
        return DB::transaction(function () use ($requestId, $checkerUserId, $checkerNote): DualControlRequest {
            $request = $this->lockPending($requestId);

            if ((int) $request->maker_user_id === $checkerUserId) {
                throw DualControlViolationException::selfApproval();
            }

            $this->assertCheckerEligible($request, $checkerUserId);

            $request->checker_user_id = $checkerUserId;
            $request->checker_note = $checkerNote;
            $request->decided_at = now();
            $request->status = DualControlStatus::APPROVED;
            $request->save();

            return $request;
        });
    }

    public function reject(int $requestId, int $checkerUserId, string $checkerNote): DualControlRequest
    {
        if (trim($checkerNote) === '') {
            throw new OperationNotPermittedException('rejection_requires_a_note');
        }

        return DB::transaction(function () use ($requestId, $checkerUserId, $checkerNote): DualControlRequest {
            $request = $this->lockPending($requestId);

            if ((int) $request->maker_user_id === $checkerUserId) {
                throw DualControlViolationException::selfApproval();
            }

            $this->assertCheckerEligible($request, $checkerUserId);

            $request->checker_user_id = $checkerUserId;
            $request->checker_note = $checkerNote;
            $request->decided_at = now();
            $request->status = DualControlStatus::REJECTED;
            $request->save();

            return $request;
        });
    }

    /** The maker may withdraw their own request; nobody else may. */
    public function cancel(int $requestId, int $makerUserId): DualControlRequest
    {
        return DB::transaction(function () use ($requestId, $makerUserId): DualControlRequest {
            $request = $this->lockPending($requestId);

            if ((int) $request->maker_user_id !== $makerUserId) {
                throw new OperationNotPermittedException('only_the_maker_may_cancel');
            }

            $request->status = DualControlStatus::CANCELLED;
            $request->decided_at = now();
            $request->save();

            return $request;
        });
    }

    /** Sweep expired pending requests. Called from a scheduled command. */
    public function expireStale(): int
    {
        return DualControlRequest::query()
            ->where('status', DualControlStatus::PENDING->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => DualControlStatus::EXPIRED->value]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(string $action, array $payload): string
    {
        ksort($payload);

        return hash('sha256', $action.'|'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function lockPending(int $requestId): DualControlRequest
    {
        /** @var DualControlRequest $request */
        $request = DualControlRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

        if ($request->status !== DualControlStatus::PENDING) {
            throw DualControlViolationException::alreadyDecided($request->status->value);
        }

        if ($request->isExpired()) {
            $request->status = DualControlStatus::EXPIRED;
            $request->save();

            throw DualControlViolationException::expired();
        }

        return $request;
    }

    /**
     * The checker must belong to the same organisation as the request, unless
     * they are platform staff acting on a member.
     */
    private function assertCheckerEligible(DualControlRequest $request, int $checkerUserId): void
    {
        $checker = User::query()->findOrFail($checkerUserId);

        if ($request->organization_id === null || $checker->isPlatformStaff()) {
            return;
        }

        if ((int) $checker->organization_id !== (int) $request->organization_id) {
            throw new OperationNotPermittedException('checker_tenancy_mismatch');
        }
    }
}
