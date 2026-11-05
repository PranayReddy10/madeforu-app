<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingController;
use App\Models\User;
use App\Services\Google;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleKeySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_service_account_json_can_be_pasted_and_is_checked(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
        $json = json_encode(['type' => 'service_account', 'project_id' => 'madeforu-reports', 'private_key_id' => str_repeat('a', 40),
            'private_key' => $pem, 'client_email' => 'reports@madeforu-reports.iam.gserviceaccount.com', 'client_id' => '1234567890',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth', 'token_uri' => 'https://oauth2.googleapis.com/token'], JSON_PRETTY_PRINT);
        $this->assertGreaterThan(1500, strlen($json));
        $field = SettingController::inputName('google.service_account');

        $this->put('/admin/settings/', [$field => 'just some text'])->assertSessionHasErrors($field);

        $this->put('/admin/settings/', [$field => $json])->assertSessionHasNoErrors();
        $this->assertSame('reports@madeforu-reports.iam.gserviceaccount.com', Google::credentials()['client_email']);
        $this->get('/admin/settings/')->assertSee('Saved ✓ — reports@madeforu-reports.iam.gserviceaccount.com', false)->assertDontSee('BEGIN PRIVATE KEY');

        $this->put('/admin/settings/', [$field => ''])->assertSessionHasNoErrors();   // blank keeps the saved key
        $this->assertNotNull(Google::credentials());
    }
}
