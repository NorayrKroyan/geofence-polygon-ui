<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Geofence extends Model
{
    protected $fillable = [
        'name',
        'center_point_lat',
        'center_point_lng',
        'speed_limit_kph',
        'entry_action',
        'exit_action',
        'polygon_points',
        'geometry_json',
        'color',
        'notes',
        'is_active',
        'is_delete',
        'expire_date',
    ];

    protected $casts = [
        'center_point_lat' => 'float',
        'center_point_lng' => 'float',
        'speed_limit_kph' => 'integer',
        'polygon_points' => 'array',
        'geometry_json' => 'array',
        'is_active' => 'boolean',
        'is_delete' => 'boolean',
        'expire_date' => 'datetime',
    ];

    public function testedGpsPoints(): HasMany
    {
        return $this->hasMany(GpsPoint::class, 'tested_geofence_id');
    }

    public function matchedGpsPoints(): HasMany
    {
        return $this->hasMany(GpsPoint::class, 'matched_geofence_id');
    }
}
