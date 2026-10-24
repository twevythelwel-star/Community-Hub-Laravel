<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-per-tenant multi-tenancy (stancl/tenancy) is removed. Nothing in
 * the app was tenant-scoped and tenant databases had no schema; estates are
 * told apart by the Community model instead. These were its two tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }

    public function down(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->timestamps();
            $table->json('data')->nullable();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            $table->string('tenant_id');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
        });
    }
};
