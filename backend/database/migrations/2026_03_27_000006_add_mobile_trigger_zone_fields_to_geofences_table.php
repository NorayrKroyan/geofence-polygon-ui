<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            if (! Schema::hasColumn('geofences', 'event_id')) {
                $table->unsignedBigInteger('event_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('geofences', 'trigger_zone')) {
                $table->json('trigger_zone')->nullable()->after('event_id');
            }

            if (! Schema::hasColumn('geofences', 'bounding_box')) {
                $table->json('bounding_box')->nullable()->after('trigger_zone');
            }

            if (! Schema::hasColumn('geofences', 'bounding_box_center')) {
                $table->json('bounding_box_center')->nullable()->after('bounding_box');
            }
        });
    }

    public function down(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            if (Schema::hasColumn('geofences', 'bounding_box_center')) {
                $table->dropColumn('bounding_box_center');
            }

            if (Schema::hasColumn('geofences', 'bounding_box')) {
                $table->dropColumn('bounding_box');
            }

            if (Schema::hasColumn('geofences', 'trigger_zone')) {
                $table->dropColumn('trigger_zone');
            }

            if (Schema::hasColumn('geofences', 'event_id')) {
                $table->dropColumn('event_id');
            }
        });
    }
};
