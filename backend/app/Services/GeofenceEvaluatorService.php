<?php

namespace App\Services;

use App\Models\Geofence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class GeofenceEvaluatorService
{
    public function normalizePolygon(array $paths): array
    {
        return collect($paths)
            ->map(function ($point) {
                return [
                    'lat' => round((float) ($point['lat'] ?? 0), 6),
                    'lng' => round((float) ($point['lng'] ?? 0), 6),
                ];
            })
            ->filter(function ($point) {
                return is_numeric($point['lat']) && is_numeric($point['lng']);
            })
            ->values()
            ->all();
    }

    public function computeCenter(array $paths): array
    {
        $polygon = $this->normalizePolygon($paths);

        if (count($polygon) === 0) {
            return ['lat' => null, 'lng' => null];
        }

        $lat = array_sum(array_column($polygon, 'lat')) / count($polygon);
        $lng = array_sum(array_column($polygon, 'lng')) / count($polygon);

        return [
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
        ];
    }

    public function computeBoundingBox(array $paths): array
    {
        $polygon = $this->normalizePolygon($paths);

        if (count($polygon) === 0) {
            return [
                'north' => null,
                'south' => null,
                'east' => null,
                'west' => null,
            ];
        }

        $lats = array_column($polygon, 'lat');
        $lngs = array_column($polygon, 'lng');

        return [
            'north' => round(max($lats), 7),
            'south' => round(min($lats), 7),
            'east' => round(max($lngs), 7),
            'west' => round(min($lngs), 7),
        ];
    }

    public function computeBoundingBoxCenter(array $boundingBox): array
    {
        if (
            $boundingBox['north'] === null ||
            $boundingBox['south'] === null ||
            $boundingBox['east'] === null ||
            $boundingBox['west'] === null
        ) {
            return ['lat' => null, 'lng' => null];
        }

        return [
            'lat' => round((((float) $boundingBox['north']) + ((float) $boundingBox['south'])) / 2, 7),
            'lng' => round((((float) $boundingBox['east']) + ((float) $boundingBox['west'])) / 2, 7),
        ];
    }

    public function pointOnSegment(array $point, array $a, array $b, float $epsilon = 1.0E-9): bool
    {
        $px = (float) $point['lng'];
        $py = (float) $point['lat'];
        $ax = (float) $a['lng'];
        $ay = (float) $a['lat'];
        $bx = (float) $b['lng'];
        $by = (float) $b['lat'];

        $cross = (($px - $ax) * ($by - $ay)) - (($py - $ay) * ($bx - $ax));
        if (abs($cross) > $epsilon) {
            return false;
        }

        $dot = (($px - $ax) * ($bx - $ax)) + (($py - $ay) * ($by - $ay));
        if ($dot < -$epsilon) {
            return false;
        }

        $squaredLength = (($bx - $ax) ** 2) + (($by - $ay) ** 2);

        return ($dot - $squaredLength) <= $epsilon;
    }

    public function getPointGeofenceStatus(array $point, array $polygon): string
    {
        $polygon = $this->normalizePolygon($polygon);

        if (count($polygon) < 3) {
            return 'outside';
        }

        $px = (float) $point['lng'];
        $py = (float) $point['lat'];
        $windingNumber = 0;
        $count = count($polygon);

        for ($i = 0; $i < $count; $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % $count];

            if ($this->pointOnSegment($point, $a, $b)) {
                return 'edge';
            }

            $ax = (float) $a['lng'];
            $ay = (float) $a['lat'];
            $bx = (float) $b['lng'];
            $by = (float) $b['lat'];

            $isUpwardCrossing = $ay <= $py && $by > $py;
            $isDownwardCrossing = $ay > $py && $by <= $py;
            $isLeftValue = (($bx - $ax) * ($py - $ay)) - (($px - $ax) * ($by - $ay));

            if ($isUpwardCrossing && $isLeftValue > 0) {
                $windingNumber++;
            } elseif ($isDownwardCrossing && $isLeftValue < 0) {
                $windingNumber--;
            }
        }

        return $windingNumber !== 0 ? 'inside' : 'outside';
    }

    public function syncDerivedGeofenceFields(Geofence $geofence, array $paths): Geofence
    {
        $polygon = $this->normalizePolygon($paths);
        $center = $this->computeCenter($polygon);
        $boundingBox = $this->computeBoundingBox($polygon);
        $boundingBoxCenter = $this->computeBoundingBoxCenter($boundingBox);

        $geofence->geometry_json = ['paths' => $polygon];
        $geofence->polygon_points = $polygon;

        // Mobile compatibility fields
        $geofence->trigger_zone = $polygon;
        $geofence->bounding_box = $boundingBox;
        $geofence->bounding_box_center = $boundingBoxCenter;

        $geofence->center_point_lat = $center['lat'];
        $geofence->center_point_lng = $center['lng'];

        return $geofence;
    }

    public function activeGeofences(): Collection
    {
        return Geofence::query()
            ->where('is_active', true)
            ->where('is_delete', false)
            ->where(function ($query) {
                $query->whereNull('expire_date')
                    ->orWhere('expire_date', '>', Carbon::now());
            })
            ->get();
    }

    public function findMatchingGeofence(float $lat, float $lng): array
    {
        $point = ['lat' => $lat, 'lng' => $lng];

        foreach ($this->activeGeofences() as $geofence) {
            $paths = $geofence->polygon_points
                ?? data_get($geofence->geometry_json, 'paths', []);

            $status = $this->getPointGeofenceStatus($point, $paths);

            if (in_array($status, ['inside', 'edge'], true)) {
                return [
                    'geofence' => $geofence,
                    'status' => $status,
                ];
            }
        }

        return [
            'geofence' => null,
            'status' => 'outside',
        ];
    }
}
