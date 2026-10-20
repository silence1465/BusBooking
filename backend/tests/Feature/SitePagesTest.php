<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** The Contact us and Privacy pages say what an admin wrote under Settings. */
class SitePagesTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    public function test_the_contact_details_come_from_settings(): void
    {
        $this->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.contact.phone', null)->assertJsonPath('data.privacy_text', null);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', [
            'support_phone' => '0302000111',
            'contact_whatsapp' => '+233 24 123 4567',
            'contact_email' => 'help@abaa.example.org',
            'contact_address' => "Circle Bus Terminal\nAccra",
            'contact_hours' => 'Every day, 5am to 10pm',
            'contact_map_url' => 'https://maps.example.org/abaa',
            'contact_intro' => 'We are happy to help.',
            'page_privacy' => "## Our promise\n\nWe keep your details safe.",
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/site')->assertOk()
            ->assertJsonPath('data.contact.phone', '0302000111')
            ->assertJsonPath('data.contact.whatsapp', '+233 24 123 4567')
            ->assertJsonPath('data.contact.email', 'help@abaa.example.org')
            ->assertJsonPath('data.contact.hours', 'Every day, 5am to 10pm')
            ->assertJsonPath('data.privacy_text', "## Our promise\n\nWe keep your details safe.");
    }

    public function test_bad_contact_details_are_refused(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $a = $this->actingAs($admin, 'sanctum');

        $a->putJson('/api/v1/admin/settings', ['contact_email' => 'not-an-email'])->assertStatus(422);
        $a->putJson('/api/v1/admin/settings', ['contact_map_url' => 'javascript:alert(1)'])->assertStatus(422); // only web links
        $a->putJson('/api/v1/admin/settings', ['contact_whatsapp' => 'call me'])->assertStatus(422);
    }

    public function test_the_map_can_be_pasted_as_google_iframe_code_and_only_google_is_accepted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $a = $this->actingAs($admin, 'sanctum');
        $src = 'https://www.google.com/maps/embed?pb=!1m17!1m12!1m3!1d3272.96!2d-0.2185!3d5.5686';

        $a->putJson('/api/v1/admin/settings', ['contact_map_embed' => '<iframe src="'.$src.'" width="600" height="450" style="border:0;" loading="lazy"></iframe>'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/site')->assertJsonPath('data.contact.map_embed', $src);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', ['contact_map_embed' => '<iframe src="https://evil.example.org/phish"></iframe>'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings', ['contact_map_embed' => 'javascript:alert(1)'])->assertStatus(422);
    }
}
