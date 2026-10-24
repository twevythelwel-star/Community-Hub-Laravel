<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->string('incident_id', 20)->nullable()->unique()->after('id');
        });

        Schema::create('distress_alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 20);
            $table->foreignId('distress_alert_id')->nullable()->constrained('distress_alerts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->timestamp('occurred_at');
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['incident_id', 'occurred_at', 'id'], 'distress_event_timeline_idx');
        });

        DB::table('distress_alerts')->whereNull('incident_id')->orderBy('id')->chunkById(100, function ($alerts) {
            foreach ($alerts as $alert) {
                $year = substr((string) ($alert->triggered_at ?? now()->toDateString()), 0, 4);
                $incidentId = sprintf('DP-%s-%s', $year, str_pad(strtoupper(base_convert((string) $alert->id, 10, 36)), 4, '0', STR_PAD_LEFT));
                DB::table('distress_alerts')->where('id', $alert->id)->update(['incident_id' => $incidentId]);

                DB::table('distress_alert_events')->insert([
                    'incident_id' => $incidentId,
                    'distress_alert_id' => $alert->id,
                    'user_id' => $alert->user_id,
                    'event_type' => 'activated',
                    'occurred_at' => $alert->triggered_at ?? now(),
                    'snapshot' => json_encode([
                        'assistance' => $alert->assistance_type,
                        'gate' => $alert->gate,
                        'direction' => $alert->event_type,
                        'legacyImport' => true,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distress_alert_events');
        Schema::table('distress_alerts', function (Blueprint $table) {
            $table->dropUnique(['incident_id']);
            $table->dropColumn('incident_id');
        });
    }
};
