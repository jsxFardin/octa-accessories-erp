<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Services\DeviceSessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceSessionController extends Controller
{
    public function __construct(private readonly DeviceSessionRegistry $sessions) {}

    /** Badge scan plus PIN, in exchange for a shift-length token (06-rbac §6). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_no' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'max:10'],
            'machine_code' => ['nullable', 'string', 'max:30'],
        ]);

        $session = $this->sessions->issue($data['card_no'], $data['pin'], $data['machine_code'] ?? null);

        if ($session === null) {
            return response()->json(['message' => 'Badge or PIN not recognised.'], 401);
        }

        return response()->json($session->toArray(), 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('device_session')->toArray());
    }
}
