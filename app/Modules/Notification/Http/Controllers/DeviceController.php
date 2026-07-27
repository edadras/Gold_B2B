<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Modules\Notification\Application\DeviceService;
use App\Modules\Notification\Http\Requests\RegisterDeviceRequest;
use App\Modules\Notification\Http\Resources\PushDeviceResource;
use App\Modules\Shared\Contracts\AuthorizationGateway;
use App\Modules\Shared\Http\ApiController;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/devices` — push registration, §2.14.
 *
 * AUTHORISATION: a push token identifies a handset in one person's pocket, so
 * this is USER-scoped like the rest of the module. The two legs are
 * `auth:sanctum` for identity and the caller's own user id for ownership —
 * strictly narrower than the organisation check used elsewhere, and narrower is
 * the safe direction. The organisation id is recorded on the row for the
 * dispatcher's benefit but is never what a lookup is keyed on, so it cannot be
 * used to reach another member's device.
 *
 * DELETE never distinguishes "unknown token" from "somebody else's token".
 * Both revoke nothing and answer 204. A 404 on one and a 204 on the other would
 * let anyone test whether a token they had seen elsewhere is registered here.
 */
final class DeviceController extends ApiController
{
    public function __construct(
        AuthorizationGateway $authorization,
        private readonly DeviceService $devices,
    ) {
        parent::__construct($authorization);
    }

    public function index(Request $request): JsonResponse
    {
        $devices = $this->devices->activeFor($this->userId($request));

        return ApiResponse::collection(PushDeviceResource::collection($devices));
    }

    /**
     * Idempotent on the token: re-registering the same handset refreshes the
     * existing row, so a client may call this on every cold start. 200 rather
     * than 201 for that reason — the second call creates nothing.
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = $this->devices->register(
            userId: $this->userId($request),
            organizationId: $this->organizationId($request),
            platform: (string) $request->validated('platform'),
            token: (string) $request->validated('token'),
            deviceName: $request->validated('device_name'),
            appVersion: $request->validated('app_version'),
        );

        return ApiResponse::item(new PushDeviceResource($device), $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $this->devices->revoke($this->userId($request), $token);

        return ApiResponse::noContent();
    }
}
