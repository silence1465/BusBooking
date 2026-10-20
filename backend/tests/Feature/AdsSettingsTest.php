<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class AdsSettingsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    public function test_ads_are_off_until_an_admin_sets_them_up(): void
    {
        $this->getJson('/api/v1/ads')->assertOk()->assertJsonPath('data.enabled', false);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $a = $this->actingAs($admin, 'sanctum');

        // switching on without a publisher id still shows nothing
        $a->putJson('/api/v1/admin/settings', ['ads_enabled' => '1'])->assertOk();
        $this->getJson('/api/v1/ads')->assertJsonPath('data.enabled', false);

        $a->putJson('/api/v1/admin/settings', ['ads_publisher_id' => 'ca-pub-1234567890123456', 'ads_slot_home' => '1234567890', 'ads_slot_results' => '', 'ads_slot_confirmation' => '9876543210'])->assertOk();
        $this->getJson('/api/v1/ads')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.publisher_id', 'ca-pub-1234567890123456')
            ->assertJsonPath('data.slots.home', '1234567890')
            ->assertJsonPath('data.slots.results', null) // an empty unit means no ad in that spot
            ->assertJsonPath('data.slots.confirmation', '9876543210');
    }

    public function test_a_badly_typed_publisher_id_is_refused(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', ['ads_publisher_id' => '<script>'])->assertStatus(422);
    }
}
