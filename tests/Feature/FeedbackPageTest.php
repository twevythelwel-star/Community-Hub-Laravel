<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_residents_can_view_own_feedback_and_admin_responses(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $otherResident = User::factory()->role(UserRole::Homeowner)->create();

        Feedback::create([
            'user_id' => $resident->id,
            'submitted_by' => $resident->display_name,
            'user_role' => $resident->role->value,
            'type' => 'Issue',
            'subject' => 'Street light flickering',
            'body' => 'Light on pole 14 flickers repeatedly at night.',
            'status' => 'In Progress',
            'admin_response' => 'Maintenance ticket dispatch #402 opened.',
            'submitted_at' => now(),
        ]);

        Feedback::create([
            'user_id' => $otherResident->id,
            'submitted_by' => $otherResident->display_name,
            'user_role' => $otherResident->role->value,
            'type' => 'Suggestion',
            'subject' => 'Other resident private issue',
            'body' => 'Should never be seen by anyone else.',
            'status' => 'New',
            'submitted_at' => now(),
        ]);

        $this->actingAs($resident)->get('/dashboard/feedback')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Feedback')
                ->has('submissions', 1)
                ->where('submissions.0.subject', 'Street light flickering')
                ->where('submissions.0.status', 'In Progress')
                ->where('submissions.0.adminResponse', 'Maintenance ticket dispatch #402 opened.')
            );
    }

    public function test_residents_can_submit_issue_or_suggestion(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)->post('/dashboard/feedback', [
            'type' => 'Suggestion',
            'subject' => 'Install solar lamps along jogging track',
            'body' => 'Solar-powered lamps will lower monthly energy bills and improve night visibility.',
        ])->assertRedirect()->assertSessionHas('success', 'Thank you — your feedback has been submitted.');

        $feedback = Feedback::sole();
        $this->assertSame('Suggestion', $feedback->type);
        $this->assertSame('Install solar lamps along jogging track', $feedback->subject);
        $this->assertSame('New', $feedback->status);
        $this->assertSame($resident->id, $feedback->user_id);
    }

    public function test_feedback_submission_requires_valid_fields(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)->post('/dashboard/feedback', [
            'type' => 'InvalidType',
            'subject' => '',
            'body' => '',
        ])->assertSessionHasErrors(['type', 'subject', 'body']);

        $this->assertDatabaseCount('feedback', 0);
    }
}
