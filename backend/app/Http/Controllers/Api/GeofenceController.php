<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Services\GeofenceEvaluatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GeofenceController extends Controller
{
    public function __construct(
        protected GeofenceEvaluatorService $evaluator
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $includeDeleted = $request->boolean('include_deleted', false);

        $query = Geofence::query()->orderByDesc('id');

        if (! $includeDeleted) {
            $query->where('is_delete', false);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $geofence = new Geofence();
        $this->fillGeofence($geofence, $validated)->save();

        return response()->json([
            'data' => $geofence->fresh(),
        ], 201);
    }

    public function update(Request $request, Geofence $geofence): JsonResponse
    {
        $validated = $this->validatePayload($request, $geofence);

        $this->fillGeofence($geofence, $validated)->save();

        return response()->json([
            'data' => $geofence->fresh(),
        ]);
    }

    public function destroy(Geofence $geofence): JsonResponse
    {
        $geofence->is_delete = true;
        $geofence->is_active = false;
        $geofence->save();

        return response()->json([
            'message' => 'Geofence deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, ?Geofence $geofence = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'speed_limit_kph' => ['nullable', 'integer', 'min:0'],
            'entry_action' => ['nullable', 'string', 'max:40'],
            'exit_action' => ['nullable', 'string', 'max:40'],
            'expire_date' => ['nullable', 'date'],
            'geometry_json' => ['required', 'array'],
            'geometry_json.paths' => ['required', 'array', 'min:3'],
            'geometry_json.paths.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'geometry_json.paths.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
    }

    protected function fillGeofence(Geofence $geofence, array $validated): Geofence
    {
        $paths = data_get($validated, 'geometry_json.paths', []);

        $geofence->name = $validated['name'];
        $geofence->color = $validated['color'] ?? '#2563eb';
        $geofence->notes = $validated['notes'] ?? null;
        $geofence->is_active = $validated['is_active'] ?? true;
        $geofence->speed_limit_kph = $validated['speed_limit_kph'] ?? null;
        $geofence->entry_action = $validated['entry_action'] ?? null;
        $geofence->exit_action = $validated['exit_action'] ?? null;
        $geofence->expire_date = $validated['expire_date'] ?? null;
        $geofence->is_delete = false;

        $this->evaluator->syncDerivedGeofenceFields($geofence, $paths);

        return $geofence;
    }
}
