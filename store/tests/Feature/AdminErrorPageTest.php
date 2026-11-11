<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_500_page_shows_the_error_to_admins_only(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_boom', fn () => throw new \RuntimeException('Route [admin.orders.charges] not defined.'));

        $this->get('/_boom')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('not defined');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/_boom')->assertStatus(500)->assertDontSee('not defined');

        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get('/_boom')->assertStatus(500)->assertSee('Only shop admins see this box')
            ->assertSee('RuntimeException: Route [admin.orders.charges] not defined.');
    }

    public function test_update_banner_runs_pending_migrations(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Finish updating the site');

        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_11_05_000100_add_extra_charge_to_orders')->delete();
        \Illuminate\Support\Facades\Schema::table('orders', fn ($t) => $t->dropColumn(['extra_charge', 'extra_charge_label']));
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Finish updating the site')->assertSee('1 database change');

        $this->actingAs($admin)->from('/admin')->post('/admin/speed', ['do' => 'update'])->assertRedirect('/admin')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 database change applied'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('orders', 'extra_charge'));
        $this->actingAs($admin)->get('/admin')->assertDontSee('Finish updating the site');
    }
}
