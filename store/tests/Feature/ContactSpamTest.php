<?php

namespace Tests\Feature;

use App\Mail\AdminAlert;
use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactSpamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
    }

    private function send(array $over = [], int $secondsOnPage = 30)
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        return $this->from('/contact-us/')->post('/contact-message', $over + [
            '_ts' => Crypt::encryptString((string) (time() - $secondsOnPage)),
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'message' => 'Can you make 20 mugs with our team photo?',
        ])->assertRedirect('/contact-us/')->assertSessionHas('contact_sent');
    }

    public function test_bots_are_thrown_away_and_look_like_success(): void
    {
        $this->send(['website' => 'http://spam.example']);   // hidden field filled
        $this->send([], 1);                                    // sent within a second of opening
        $this->assertSame(0, ContactMessage::count());

        $this->send();
        $this->send();                                         // same message again
        $this->assertSame(1, ContactMessage::count());
        Mail::assertSentCount(1);
    }

    public function test_spam_goes_to_the_spam_folder_without_an_email(): void
    {
        $this->send(['message' => 'We offer SEO services to rank your website on the first page of Google.', 'phone' => '9876500001']);
        $this->send(['message' => 'Visit https://a.example and https://b.example now', 'phone' => '9876500002']);
        $this->send(['message' => 'Здравствуйте, хочу купить', 'phone' => '9876500003']);
        $this->send(['_ts' => 'forged', 'phone' => '9876500004']);
        $this->assertSame(4, ContactMessage::whereNotNull('spam_reason')->count());
        Mail::assertNothingSent();

        // One link is fine: customers share a Google Drive folder of photos.
        $this->send(['message' => 'Photos are here https://drive.google.com/drive/folders/abc', 'phone' => '9876500005']);
        $this->assertNull(ContactMessage::where('phone', '9876500005')->value('spam_reason'));
    }

    public function test_spam_button_blocks_the_sender_and_spam_tab_lists_it(): void
    {
        $this->send();
        $msg = ContactMessage::first();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->post(route('admin.messages.spam', $msg))->assertRedirect();
        $this->assertStringContainsString('asha@example.com', setting('contact.block'));
        $this->get('/admin/messages/?box=spam')->assertOk()->assertSee('Can you make 20 mugs')->assertSee('Not spam');
        $this->get('/admin/messages/')->assertDontSee('Can you make 20 mugs');

        $this->send(['message' => 'Another message from me', 'email' => 'ASHA@example.com', 'phone' => '9000000000']);
        $this->assertSame('Blocked sender', ContactMessage::latest('id')->value('spam_reason'));

        Setting::put(['contact.block' => "lottery"]);
        $this->send(['message' => 'You won the lottery', 'email' => 'x@y.com', 'phone' => '9000000001']);
        $this->assertSame('Blocked word: lottery', ContactMessage::latest('id')->value('spam_reason'));

        $this->actingAs($admin)->post(route('admin.messages.not-spam', $msg));
        $this->assertNull($msg->fresh()->spam_reason);
    }

    public function test_settings_and_contact_pages_render(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Block list')->assertSee('Cloudflare Turnstile');
        $this->get('/contact-us/')->assertOk()->assertSee('name="_ts"', false)->assertDontSee('cf-turnstile');
        Setting::put(['turnstile.site_key' => 'site', 'turnstile.secret' => 'sec']);
        $this->get('/contact-us/')->assertSee('cf-turnstile');
    }
}
