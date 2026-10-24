<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resident requests to remove someone from the blocklist.
 *
 * The dialog for this already existed and told the resident their request "has
 * been sent to the administrators for review". It had not been: onSubmit called
 * console.log and showed a toast. Nothing was stored, nobody was notified, and
 * no administrator had anywhere to see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklist_removal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocklist_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default('Pending');   // Pending | Approved | Declined
            $table->text('reviewer_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            // One open request per resident per entry; re-requesting reopens the
            // existing row rather than stacking duplicates for admins to wade through.
            $table->unique(['blocklist_entry_id', 'requested_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_removal_requests');
    }
};
