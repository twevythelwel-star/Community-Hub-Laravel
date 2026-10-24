<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Pennant was removed: no feature flag was ever defined, and its
 * `features` table only stored resolved flag values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('features');
    }

    public function down(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }
};
