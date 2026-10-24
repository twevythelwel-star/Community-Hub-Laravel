<?php

namespace Tests\Feature;

use App\Jobs\SendVisitorPassNotification;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendVisitorPassNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_generates_qr_code(): void
    {
        Storage::fake('public');

        $resident = User::factory()->create();
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'contact' => 'test@example.com',
            'notify_email' => true,
        ]);

        $job = new SendVisitorPassNotification($visitor, ['email']);
        app()->call([$job, 'handle']);

        $this->assertNotNull($visitor->fresh()->qr_code_path);
        Storage::disk('public')->assertExists($visitor->fresh()->qr_code_path);
    }

    public function test_job_only_processes_enabled_channels(): void
    {
        Storage::fake('public');

        $resident = User::factory()->create();
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'contact' => 'test@example.com',
            'notify_email' => true,
            'notify_sms' => false,
            'notify_whatsapp' => false,
        ]);

        $job = new SendVisitorPassNotification($visitor, ['email', 'sms', 'whatsapp']);
        app()->call([$job, 'handle']);

        $this->assertNotNull($visitor->fresh()->qr_code_path);
    }

    public function test_job_handles_visitor_without_contact(): void
    {
        Storage::fake('public');

        $resident = User::factory()->create();
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'contact' => null,
            'notify_email' => true,
        ]);

        $job = new SendVisitorPassNotification($visitor, ['email']);
        app()->call([$job, 'handle']);

        // QR code should still be generated even without contact
        $this->assertNotNull($visitor->fresh()->qr_code_path);
    }
}
