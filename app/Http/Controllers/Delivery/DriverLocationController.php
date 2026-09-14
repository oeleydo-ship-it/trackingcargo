<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreDriverLocationRequest;
use App\Services\Delivery\DriverLocationService;
use Illuminate\Http\JsonResponse;

final class DriverLocationController extends Controller
{
    public function store(StoreDriverLocationRequest $request, DriverLocationService $locations): JsonResponse
    {
        $data = $request->validated();

        $locations->ping($request->user()->driver, (float) $data['latitude'], (float) $data['longitude']);

        return response()->json(['status' => 'ok']);
    }
}
