<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 20)->default('#2563eb');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('geometry_json');
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
