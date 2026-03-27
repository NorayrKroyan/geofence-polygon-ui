<?php

namespace App\Support;

use App\Models\Setting;

class MapSettings
{
    public const DEFAULTS = [
        'default_center_lat' => '40.17712',
        'default_center_lng' => '44.50391',
        'default_zoom' => '13',
        'gps_refresh_seconds' => '15',
    ];

    public static function merged(): array
    {
        $stored = Setting::query()
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        return array_merge(self::DEFAULTS, $stored);
    }

    public static function upsertValidated(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $type = in_array($key, ['default_center_lat', 'default_center_lng'], true) ? 'float' : 'integer';

            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'type' => $type,
                ]
            );
        }
    }
}
