<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            if (! Schema::hasColumn('geofences', 'center_point_lat')) {
                $table->decimal('center_point_lat', 10, 7)->nullable()->after('name');
            }

            if (! Schema::hasColumn('geofences', 'center_point_lng')) {
                $table->decimal('center_point_lng', 10, 7)->nullable()->after('center_point_lat');
            }

            if (! Schema::hasColumn('geofences', 'speed_limit_kph')) {
                $table->unsignedInteger('speed_limit_kph')->nullable()->after('center_point_lng');
            }

            if (! Schema::hasColumn('geofences', 'entry_action')) {
                $table->string('entry_action', 40)->nullable()->after('speed_limit_kph');
            }

            if (! Schema::hasColumn('geofences', 'exit_action')) {
                $table->string('exit_action', 40)->nullable()->after('entry_action');
            }

            if (! Schema::hasColumn('geofences', 'polygon_points')) {
                $table->json('polygon_points')->nullable()->after('geometry_json');
            }

            if (! Schema::hasColumn('geofences', 'is_delete')) {
                $table->boolean('is_delete')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('geofences', 'expire_date')) {
                $table->timestamp('expire_date')->nullable()->after('is_delete');
            }
        });
    }

    public function down(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            foreach ([
                'center_point_lat',
                'center_point_lng',
                'speed_limit_kph',
                'entry_action',
                'exit_action',
                'polygon_points',
                'is_delete',
                'expire_date',
            ] as $column) {
                if (Schema::hasColumn('geofences', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
