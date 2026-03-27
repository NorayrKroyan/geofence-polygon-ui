<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Geofence extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'center_point_lat',
        'center_point_lng',
        'speed_limit_kph',
        'entry_action',
        'exit_action',
        'polygon_points',
        'trigger_zone',
        'bounding_box',
        'bounding_box_center',
        'geometry_json',
        'color',
        'notes',
        'is_active',
        'is_delete',
        'expire_date',
    ];

    protected $casts = [
        'event_id' => 'integer',
        'center_point_lat' => 'float',
        'center_point_lng' => 'float',
        'speed_limit_kph' => 'integer',
        'polygon_points' => 'array',
        'trigger_zone' => 'array',
        'bounding_box' => 'array',
        'bounding_box_center' => 'array',
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
