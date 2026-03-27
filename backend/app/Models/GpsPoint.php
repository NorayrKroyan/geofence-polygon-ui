<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpsPoint extends Model
{
    protected $fillable = [
        'device_uuid',
        'lat',
        'lng',
        'recorded_at',
        'point_source',
        'is_test_point',
        'tested_geofence_id',
        'matched_geofence_id',
        'geofence_check_status',
        'checked_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'recorded_at' => 'datetime',
        'is_test_point' => 'boolean',
        'tested_geofence_id' => 'integer',
        'matched_geofence_id' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function testedGeofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'tested_geofence_id');
    }

    public function matchedGeofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'matched_geofence_id');
    }
}
