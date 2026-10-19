<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_page_opens_before_anything_is_set(): void
    {
        $a = User::factory()->create();
        $a->forceFill(['is_admin' => true])->save();
        $this->actingAs($a)->get(route('admin.campaign.edit'))->assertOk()->assertSee('Festival campaign')->assertSee('Diwali 2026');
        $this->get(route('admin.users.whatsapp'))->assertOk();
        $this->get(route('admin.profit'))->assertOk();
    }
}
