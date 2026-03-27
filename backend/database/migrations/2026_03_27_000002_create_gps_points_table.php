<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_points', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid', 255)->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('heading', 8, 2)->nullable();
            $table->timestamp('recorded_at')->index();
            $table->json('raw_payload_json')->nullable();
            $table->timestamps();

            $table->index(['device_uuid', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_points');
    }
};
