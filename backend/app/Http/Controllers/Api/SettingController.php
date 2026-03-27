<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MapSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => MapSettings::merged(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_center_lat' => ['required', 'numeric', 'between:-90,90'],
            'default_center_lng' => ['required', 'numeric', 'between:-180,180'],
            'default_zoom' => ['required', 'integer', 'min:1', 'max:20'],
            'gps_refresh_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
        ]);

        MapSettings::upsertValidated($data);

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data' => MapSettings::merged(),
        ]);
    }
}
