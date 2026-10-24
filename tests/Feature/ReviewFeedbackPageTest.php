<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page used to show three hardcoded submissions and change their status in
 * React state. These cover the real queue it now reads and the review it saves.
 */
class ReviewFeedbackPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submission(User $author, array $overrides = []): Feedback
    {
        return Feedback::create([
            'user_id' => $author->id,
            'submitted_by' => $author->display_name,
            'user_role' => $author->role->value,
            'type' => 'Issue',
            'subject' => 'Street light out',
            'body' => 'The second light from the corner has been out for a week.',
            'status' => 'New',
            'submitted_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_admins_see_submissions_with_their_body_and_status_counts(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $this->submission($resident);
        $this->submission($resident, ['subject' => 'Dog waste station', 'type' => 'Suggestion', 'status' => 'Resolved']);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/review-feedback')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/ReviewFeedback')
                ->has('submissions.data', 2)
                ->has('submissions.data.0.body')
                ->where('counts.new', 1)
                ->where('counts.resolved', 1)
            );
    }

    public function test_the_status_and_type_filters_narrow_the_queue(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $this->submission($resident);
        $this->submission($resident, ['subject' => 'Dog waste station', 'type' => 'Suggestion']);
        $this->submission($resident, ['subject' => 'Old issue', 'status' => 'Resolved']);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/review-feedback?status=New&type=Issue')
            ->assertInertia(fn ($page) => $page
                ->has('submissions.data', 1)
                ->where('submissions.data.0.subject', 'Street light out')
                ->where('filters.status', 'New')
                ->where('filters.type', 'Issue')
            );
    }

    public function test_an_admin_can_save_a_status_and_response(): void
    {
        $feedback = $this->submission(User::factory()->role(UserRole::Homeowner)->create());

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch("/dashboard/review-feedback/{$feedback->id}", [
                'status' => 'In Progress',
                'admin_response' => 'Reported to the utility company.',
            ])->assertSessionHas('success', 'Feedback updated.');

        $feedback->refresh();
        $this->assertSame('In Progress', $feedback->status);
        $this->assertSame('Reported to the utility company.', $feedback->admin_response);
    }

    public function test_a_quick_status_change_keeps_the_existing_response(): void
    {
        $feedback = $this->submission(User::factory()->role(UserRole::Homeowner)->create(), [
            'status' => 'In Progress',
            'admin_response' => 'Reported to the utility company.',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch("/dashboard/review-feedback/{$feedback->id}", ['status' => 'Resolved']);

        $feedback->refresh();
        $this->assertSame('Resolved', $feedback->status);
        $this->assertSame('Reported to the utility company.', $feedback->admin_response);
    }

    public function test_residents_cannot_review_feedback(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $feedback = $this->submission($resident);

        $this->actingAs($resident)->get('/dashboard/review-feedback')->assertForbidden();
        $this->actingAs($resident)
            ->patch("/dashboard/review-feedback/{$feedback->id}", ['status' => 'Resolved'])
            ->assertForbidden();

        $this->assertSame('New', $feedback->fresh()->status);
    }
}
