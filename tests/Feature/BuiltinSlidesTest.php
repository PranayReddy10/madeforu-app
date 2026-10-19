<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuiltinSlidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_in_slides_can_be_switched_and_shown_with_banners(): void
    {
        $this->seed(DemoSeeder::class);
        Banner::query()->delete();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->get('/')->assertSee('hs-brand', false)->assertSee('hs-offer', false);
        $this->actingAs($admin)->get(route('admin.banners.index'))->assertOk()->assertSee('Built-in slides')->assertSee('Shown on the home page now');

        $this->post(route('admin.banners.builtin'), ['brand' => 1, 'product' => 1, 'offer' => 0, 'with_banners' => 0])->assertSessionHas('status');
        $this->get('/')->assertSee('hs-brand', false)->assertDontSee('hs-offer', false);

        Banner::create(['title' => 'Diwali sale', 'image' => 'banners/d.jpg', 'is_active' => true, 'position' => 0]);
        $this->get('/')->assertSee('Diwali sale')->assertDontSee('hs-brand', false);
        $this->get(route('admin.banners.index'))->assertSee('Hidden right now because you have your own banners');

        $this->post(route('admin.banners.builtin'), ['brand' => 1, 'product' => 0, 'offer' => 0, 'with_banners' => 1]);
        $this->get('/')->assertSee('Diwali sale')->assertSee('hs-brand', false);
    }
}
