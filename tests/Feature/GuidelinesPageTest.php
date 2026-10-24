<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Guideline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page offered Add, Edit and Delete against a hardcoded array, and the
 * server had no write endpoints at all. These cover the endpoints added for it.
 */
class GuidelinesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guidelines_arrive_grouped_by_category_with_manage_rights_for_the_viewer(): void
    {
        Guideline::create(['category' => 'Security Policies', 'title' => 'Gate Access', 'description' => 'One vehicle per credential.', 'sort_order' => 1]);
        Guideline::create(['category' => 'Amenities Usage', 'title' => 'Pool Hours', 'description' => 'Open 9 AM to 9 PM.', 'sort_order' => 1]);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard/guidelines')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Guidelines')
                ->has('guidelines.Security Policies', 1)
                ->where('guidelines.Amenities Usage.0.title', 'Pool Hours')
                ->where('canManage', false)
            );

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/guidelines')
            ->assertInertia(fn ($page) => $page->where('canManage', true));
    }

    public function test_an_admin_can_add_a_guideline_at_the_end_of_its_category(): void
    {
        Guideline::create(['category' => 'General Conduct', 'title' => 'Noise Levels', 'description' => 'Quiet hours from 10 PM.', 'sort_order' => 4]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/guidelines', [
                'category' => 'General Conduct',
                'title' => 'Trash Disposal',
                'description' => 'Bins curbside on Tuesday evenings.',
            ])->assertSessionHas('success', 'Guideline added.');

        $this->assertSame(5, Guideline::where('title', 'Trash Disposal')->sole()->sort_order);
    }

    public function test_an_admin_can_edit_and_delete_a_guideline(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $guideline = Guideline::create(['category' => 'Amenities Usage', 'title' => 'Pool Hours', 'description' => 'Open 9 AM to 9 PM.', 'sort_order' => 1]);

        $this->actingAs($admin)->patch("/dashboard/guidelines/{$guideline->id}", [
            'category' => 'Amenities Usage',
            'title' => 'Pool Hours',
            'description' => 'Open 8 AM to 8 PM from May to September.',
        ])->assertSessionHas('success', 'Guideline updated.');

        $this->assertSame('Open 8 AM to 8 PM from May to September.', $guideline->fresh()->description);

        $this->actingAs($admin)->delete("/dashboard/guidelines/{$guideline->id}")
            ->assertSessionHas('success', 'Guideline deleted.');

        $this->assertDatabaseCount('guidelines', 0);
    }

    public function test_the_server_refuses_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/guidelines', [
                'category' => 'GC',
                'title' => 'No',
                'description' => 'Too short',
            ])->assertSessionHasErrors(['category', 'title', 'description']);

        $this->assertDatabaseCount('guidelines', 0);
    }

    public function test_a_resident_cannot_change_guidelines(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();
        $guideline = Guideline::create(['category' => 'General Conduct', 'title' => 'Noise Levels', 'description' => 'Quiet hours from 10 PM.', 'sort_order' => 1]);

        $this->actingAs($homeowner)->post('/dashboard/guidelines', [
            'category' => 'General Conduct',
            'title' => 'My own rule',
            'description' => 'Residents should not be able to add this.',
        ])->assertForbidden();

        $this->actingAs($homeowner)->delete("/dashboard/guidelines/{$guideline->id}")->assertForbidden();

        $this->assertDatabaseCount('guidelines', 1);
    }
}
