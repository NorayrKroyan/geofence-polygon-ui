<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_points', function (Blueprint $table) {
            if (! Schema::hasColumn('gps_points', 'point_source')) {
                $table->string('point_source', 20)->default('mobile')->after('device_uuid');
            }

            if (! Schema::hasColumn('gps_points', 'is_test_point')) {
                $table->boolean('is_test_point')->default(false)->after('point_source');
            }

            if (! Schema::hasColumn('gps_points', 'tested_geofence_id')) {
                $table->foreignId('tested_geofence_id')
                    ->nullable()
                    ->after('is_test_point')
                    ->constrained('geofences')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('gps_points', 'matched_geofence_id')) {
                $table->foreignId('matched_geofence_id')
                    ->nullable()
                    ->after('tested_geofence_id')
                    ->constrained('geofences')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('gps_points', 'geofence_check_status')) {
                $table->string('geofence_check_status', 20)->nullable()->after('matched_geofence_id');
            }

            if (! Schema::hasColumn('gps_points', 'checked_at')) {
                $table->timestamp('checked_at')->nullable()->after('geofence_check_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gps_points', function (Blueprint $table) {
            foreach ([
                'tested_geofence_id',
                'matched_geofence_id',
            ] as $foreignColumn) {
                if (Schema::hasColumn('gps_points', $foreignColumn)) {
                    $table->dropForeign([$foreignColumn]);
                }
            }

            foreach ([
                'point_source',
                'is_test_point',
                'tested_geofence_id',
                'matched_geofence_id',
                'geofence_check_status',
                'checked_at',
            ] as $column) {
                if (Schema::hasColumn('gps_points', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
