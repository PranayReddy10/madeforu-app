<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $a = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $a->forceFill(['is_admin' => true])->save();

        return $a;
    }

    public function test_customers_page_lists_accounts_with_orders_and_spend(): void
    {
        $admin = $this->admin();
        $c = User::factory()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210']);
        Order::create(['number' => 'MFU1', 'user_id' => $c->id, 'status' => 'delivered', 'payment_method' => 'cod', 'payment_status' => 'paid',
            'subtotal' => 400, 'total' => 450, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'address_line1' => 'x', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001']);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Asha Rao')->assertSee('₹450')->assertSee('Add an admin');
        $this->get(route('admin.users.index', ['q' => '98765']))->assertSee('Asha Rao')->assertDontSee('owner@example.com');
        $this->get(route('admin.users.index', ['role' => 'admins']))->assertSee('owner@example.com')->assertDontSee('asha@example.com');
        $this->get(route('admin.users.show', $c))->assertOk()->assertSee('MFU1')->assertSee('₹450');
        $this->get('/admin/')->assertSee('Customers');
    }

    public function test_add_admin_with_password_or_emailed_link(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Ravi', 'email' => 'Ravi@Example.com', 'phone' => '+91 99887 76655', 'password' => 'secret-pass-1'])
            ->assertRedirect()->assertSessionHas('status');
        $ravi = User::where('email', 'ravi@example.com')->first();
        $this->assertTrue($ravi->is_admin);
        $this->assertSame('9988776655', $ravi->phone);
        $this->assertTrue(Hash::check('secret-pass-1', $ravi->password));

        // An existing customer becomes an admin; no password → they get a link
        $cust = User::factory()->create(['email' => 'shop@example.com']);
        $this->post(route('admin.users.store'), ['name' => 'Ignored', 'email' => 'shop@example.com'])->assertRedirect();
        $this->assertTrue($cust->fresh()->is_admin);
        Notification::assertSentTo($cust, ResetPassword::class);

        // New admin can open the panel
        auth()->logout();
        $this->post('/login', ['login' => 'ravi@example.com', 'password' => 'secret-pass-1']);
        $this->get('/admin/')->assertOk();
    }

    public function test_cannot_remove_own_access_or_the_last_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.users.update', $admin), ['name' => 'Owner', 'email' => 'owner@example.com', 'is_admin' => 0])
            ->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_admin);

        $other = User::factory()->create();
        $other->forceFill(['is_admin' => true])->save();
        $this->put(route('admin.users.update', $other), ['name' => $other->name, 'email' => $other->email, 'is_admin' => 0])->assertSessionHas('status');
        $this->assertFalse($other->fresh()->is_admin);

        // Customers can't reach any of it
        $this->actingAs($other->fresh())->get(route('admin.users.index'))->assertForbidden();
    }
}
