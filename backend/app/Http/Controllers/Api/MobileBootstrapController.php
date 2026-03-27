<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Support\MapSettings;
use Illuminate\Http\JsonResponse;

class MobileBootstrapController extends Controller
{
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => MapSettings::merged(),
                'geofences' => $this->activeGeofences(),
            ],
        ]);
    }

    public function geofences(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->activeGeofences(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => MapSettings::merged(),
        ]);
    }

    private function activeGeofences()
    {
        return Geofence::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
