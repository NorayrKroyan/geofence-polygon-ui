<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\GpsPoint;
use App\Services\GeofenceEvaluatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GpsPointController extends Controller
{
    public function __construct(
        protected GeofenceEvaluatorService $evaluator
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 200), 1000));

        $query = GpsPoint::query()
            ->with(['testedGeofence:id,name', 'matchedGeofence:id,name'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        if ($request->filled('device_uuid')) {
            $query->where('device_uuid', $request->string('device_uuid')->toString());
        }

        if ($request->has('is_test_point')) {
            $query->where('is_test_point', $request->boolean('is_test_point'));
        }

        if ($request->filled('tested_geofence_id')) {
            $query->where('tested_geofence_id', $request->integer('tested_geofence_id'));
        }

        return response()->json([
            'data' => $query->limit($limit)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $match = $this->evaluator->findMatchingGeofence($lat, $lng);

        $gpsPoint = GpsPoint::create([
            'device_uuid' => $validated['device_uuid'],
            'lat' => $lat,
            'lng' => $lng,
            'recorded_at' => $validated['recorded_at'] ?? Carbon::now(),
            'point_source' => 'mobile',
            'is_test_point' => false,
            'tested_geofence_id' => null,
            'matched_geofence_id' => $match['geofence']?->id,
            'geofence_check_status' => $match['status'],
            'checked_at' => Carbon::now(),
        ]);

        return response()->json([
            'data' => $gpsPoint->load(['testedGeofence:id,name', 'matchedGeofence:id,name']),
        ], 201);
    }

    public function storeTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tested_geofence_id' => ['required', 'integer', 'exists:geofences,id'],
            'device_uuid' => ['nullable', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $geofence = Geofence::query()->findOrFail($validated['tested_geofence_id']);
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $paths = $geofence->polygon_points
            ?? data_get($geofence->geometry_json, 'paths', []);

        $status = $this->evaluator->getPointGeofenceStatus(
            ['lat' => $lat, 'lng' => $lng],
            $paths
        );

        $gpsPoint = GpsPoint::create([
            'device_uuid' => $validated['device_uuid'] ?? 'manual-test-ui',
            'lat' => $lat,
            'lng' => $lng,
            'recorded_at' => $validated['recorded_at'] ?? Carbon::now(),
            'point_source' => 'manual_test',
            'is_test_point' => true,
            'tested_geofence_id' => $geofence->id,
            'matched_geofence_id' => in_array($status, ['inside', 'edge'], true) ? $geofence->id : null,
            'geofence_check_status' => $status,
            'checked_at' => Carbon::now(),
        ]);

        return response()->json([
            'data' => $gpsPoint->load(['testedGeofence:id,name', 'matchedGeofence:id,name']),
        ], 201);
    }
}
