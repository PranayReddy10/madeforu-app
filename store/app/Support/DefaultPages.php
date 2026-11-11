<?php

namespace App\Support;

use App\Models\Page;

/**
 * About, Contact and the policy pages, with the layout each one uses. The text is MadeForU's own
 * (from the WordPress site), rewritten as clean HTML: the WordPress versions only looked right with
 * the page builder's styling, which can't be imported.
 *
 * apply() creates missing pages and replaces bodies that are empty or still page-builder markup.
 * A page edited in admin is never overwritten.
 */
class DefaultPages
{
    public static function templateFor(string $slug): ?string
    {
        return match (true) {
            $slug === 'about-us' => 'about',
            $slug === 'contact-us' => 'contact',
            $slug === 'legal' => 'legal-index',
            str_starts_with($slug, 'legal/') => 'legal',
            default => null,
        };
    }

    /** Icon name (partials.icon) for a policy page. */
    public static function icon(string $slug): string
    {
        foreach (['privacy' => 'lock', 'refund' => 'return', 'shipping' => 'truck', 'terms' => 'doc'] as $word => $icon) {
            if (str_contains($slug, $word)) {
                return $icon;
            }
        }

        return 'doc';
    }

    /** Markup that came from the WordPress page builder rather than from a person. */
    public static function looksImported(?string $body): bool
    {
        $body = (string) $body;

        return trim(strip_tags($body)) === '' || str_contains($body, 'data-element_type') || str_contains($body, 'wpcf7') || stripos($body, '<h1') !== false;
    }

    public static function apply(): int
    {
        $changed = 0;
        foreach (self::pages() as $slug => $p) {
            $page = Page::firstOrNew(['slug' => $slug]);
            if ($page->exists && ! self::looksImported($page->body)) {
                if (! $page->template) {
                    $page->template = self::templateFor($slug);
                    $page->save();
                }

                continue;
            }
            $page->fill([
                'title' => $page->title ?: $p['title'],
                'template' => self::templateFor($slug),
                'intro' => $p['intro'],
                'body' => $p['body'],
                'meta_description' => $page->meta_description ?: $p['meta'],
                'is_active' => $page->exists ? $page->is_active : true,
            ])->save();
            $changed++;
        }

        return $changed;
    }

    /** @return array<string, array{title: string, intro: string, meta: string, body: string}> */
    public static function pages(): array
    {
        return [
            'about-us' => [
                'title' => 'About us',
                'intro' => 'MadeForU turns your photos, names and little moments into keepsakes people hold on to — custom magnets, cups, keychains, tees and more, each one made by hand, to order.',
                'meta' => 'MadeForU makes personalised photo gifts by hand in Hyderabad — magnets, mugs, keychains, bottles and more, made to order and delivered across India.',
                'body' => '<p>We started MadeForU on a simple belief: a gift means more when it’s personal. Anyone can buy something off a shelf — but a photo of someone you love, a name, a date, an inside joke? That’s the thing that stays on the desk, the fridge, the keyring for years.</p>'
                    ."\n".'<p>So we built a small business around making exactly that. No giant warehouse, no mass production — just a hands-on team in Hyderabad turning your ideas into things worth keeping.</p>',
            ],
            'contact-us' => [
                'title' => 'Contact us',
                'intro' => 'A question about an order, a custom idea, or help with something — reach out and we’ll usually reply within 24 hours.',
                'meta' => 'Contact MadeForU: call or WhatsApp +91 93810 24794, email support@madeforu.co.in. Help with orders, custom and bulk gifts. Mon–Sat, 10am–7pm IST.',
                'body' => '<p>For bulk or corporate orders, tell us the product, the quantity and when you need it — we’ll send a quote.</p>',
            ],
            'legal' => [
                'title' => 'Legal',
                'intro' => 'The policies that apply when you shop with MadeForU.',
                'meta' => 'MadeForU policies: terms and conditions, privacy, refund and cancellation, shipping and delivery.',
                'body' => '',
            ],
            'legal/shipping-delivery-policy' => [
                'title' => 'Shipping & Delivery Policy',
                'intro' => 'We ship handmade, personalised gifts across India. Here’s what to expect after you place your order.',
                'meta' => 'MadeForU shipping policy: made to order in 2–5 business days, shipped with Delhivery tracking across India, delivered in 2–7 days after dispatch.',
                'body' => <<<'HTML'
<h2>Processing time</h2>
<p>Because every item is made to order, we need time to produce and quality-check your gift before dispatch. <strong>Processing usually takes 2–5 business days</strong>, depending on the product and current order volume. Bulk or highly custom orders may take a little longer — we’ll let you know if so.</p>
<h2>Shipping &amp; delivery</h2>
<p>We ship via <strong>Delhivery</strong> with tracking on every order. Once dispatched, delivery typically takes:</p>
<ul>
<li><strong>Metro cities:</strong> 2–4 business days after dispatch</li>
<li><strong>Other locations:</strong> 4–7 business days after dispatch</li>
</ul>
<p>Delivery times are estimates and may vary due to courier delays, weather, or public holidays.</p>
<h2>Tracking your order</h2>
<p>Once your order ships, we’ll share a <strong>tracking link</strong> so you can follow it to your door. You’ll also get updates on WhatsApp where available. You can check any order on the <a href="/track-order">Track order</a> page.</p>
<h2>Shipping charges</h2>
<p>Any shipping charges are shown at checkout before you pay. Free-shipping offers, when available, are clearly stated on the product page or at checkout.</p>
<p><strong>Please note:</strong> make sure your delivery address and phone number are correct at checkout. We can’t be responsible for delays or failed deliveries caused by an incorrect or incomplete address.</p>
HTML,
            ],
            'legal/terms-and-condition' => [
                'title' => 'Terms & Conditions',
                'intro' => 'Welcome to MadeForU. By using madeforu.co.in or ordering from us, you agree to the terms below.',
                'meta' => 'MadeForU terms and conditions: personalised products, pricing and payment, cancellations, shipping, intellectual property and governing law.',
                'body' => <<<'HTML'
<h2>1. About us</h2>
<p>MadeForU is a handmade personalised gifts business based in Hyderabad, Telangana, India. Contact: <a href="mailto:support@madeforu.co.in">support@madeforu.co.in</a>, <a href="tel:+919381024794">+91 93810 24794</a>.</p>
<h2>2. Products &amp; personalisation</h2>
<p>Our products are handmade and personalised to order based on the details you provide. You’re responsible for the accuracy of the photos, spellings, names, dates, and options you submit. We produce items as ordered, so please review your details carefully before confirming.</p>
<h2>3. Colours &amp; appearance</h2>
<p>As items are handmade, slight variations in colour, size, or finish may occur. Screen colours may also differ slightly from the finished product. These variations aren’t defects.</p>
<h2>4. Pricing &amp; payment</h2>
<p>All prices are in Indian Rupees (INR) and include applicable taxes unless stated otherwise. We may change prices at any time. Orders are confirmed once payment is received.</p>
<h2>5. Orders &amp; cancellations</h2>
<p>Once production begins, personalised orders can’t be cancelled or changed. See our <a href="/legal/refund-cancellation-policy/">Refund &amp; Cancellation Policy</a> for full details.</p>
<h2>6. Shipping</h2>
<p>We ship via Delhivery across India. Delivery timelines are estimates. See our <a href="/legal/shipping-delivery-policy/">Shipping &amp; Delivery Policy</a>.</p>
<h2>7. Intellectual property</h2>
<p>You confirm that any photos, images, or text you submit for personalisation are yours to use, or that you have permission to use them. You’re responsible for the content you upload. All MadeForU branding, designs, and website content remain our property.</p>
<h2>8. Limitation of liability</h2>
<p>To the extent permitted by law, MadeForU isn’t liable for indirect or consequential losses arising from the use of our products or website. Our maximum liability is limited to the value of your order.</p>
<h2>9. Governing law</h2>
<p>These terms are governed by the laws of India, with jurisdiction in Hyderabad, Telangana.</p>
HTML,
            ],
            'legal/refund-cancellation-policy' => [
                'title' => 'Refund & Cancellation Policy',
                'intro' => 'Every MadeForU product is handmade and personalised to order — made just for you with your photos, names, text or design choices. Because a personalised item can’t be resold, please read this before ordering.',
                'meta' => 'MadeForU refund policy: damaged, defective or wrong items are replaced or refunded if reported within 48 hours. Personalised items can’t be returned for change of mind.',
                'body' => <<<'HTML'
<p class="callout"><strong>In short:</strong> personalised items can’t be returned for change of mind or typos — but if your order arrives damaged, defective, or wrong, we’ll replace it or refund you.</p>
<h2>Personalised &amp; custom items</h2>
<p>Personalised items <strong>cannot be returned or refunded</strong> for reasons such as change of mind, choosing the wrong option, or errors in the details you provided. Please double-check every spelling, photo, date, and option before confirming your order.</p>
<h2>Damaged, defective, or wrong items</h2>
<p>We stand behind our work. If your order arrives damaged, defective, or is not what you ordered, we’ll make it right. To be eligible:</p>
<ul>
<li>Contact us within <strong>48 hours of delivery</strong> at <a href="mailto:support@madeforu.co.in">support@madeforu.co.in</a> or WhatsApp <a href="https://wa.me/919381024794">+91 93810 24794</a>.</li>
<li>Share clear <strong>photos or an unboxing video</strong> showing the issue.</li>
<li>Include your <strong>order number</strong>.</li>
</ul>
<p>Once we verify the issue, we’ll arrange a free replacement or issue a refund to your original payment method.</p>
<h2>How refunds are processed</h2>
<p>Approved refunds are processed within <strong>5–7 business days</strong> to your original payment method. Your bank or UPI provider may take a few extra days to reflect it.</p>
<h2>Order cancellations</h2>
<p>Since items are made to order, cancellations are only possible <strong>before production begins</strong>. Please contact us as soon as possible if you need to cancel — once production has started, an order can’t be cancelled.</p>
HTML,
            ],
            'legal/privacy-policy-2' => [
                'title' => 'Privacy Policy',
                'intro' => 'MadeForU (“we”, “us”, “our”) respects your privacy. This policy explains what we collect, how we use it, and your choices.',
                'meta' => 'MadeForU privacy policy: what we collect to make and deliver your order, how your photos are used, who we share data with, cookies and your rights.',
                'body' => <<<'HTML'
<p>By using <a href="/">madeforu.co.in</a>, you agree to this policy.</p>
<h2>Information we collect</h2>
<ul>
<li><strong>Order details:</strong> your name, delivery address, phone number, and email, so we can process and deliver your order.</li>
<li><strong>Personalisation content:</strong> photos, text, and design choices you upload for your custom products.</li>
<li><strong>Payment information:</strong> processed securely by our payment providers. We don’t store your full card details.</li>
<li><strong>Usage data:</strong> basic analytics such as pages visited, to help us improve the site.</li>
</ul>
<h2>How we use your information</h2>
<ul>
<li>To produce, process, and deliver your orders.</li>
<li>To contact you about your order by email, phone, or WhatsApp.</li>
<li>To respond to enquiries and provide support.</li>
<li>To improve our products, website, and service.</li>
<li>To send offers or updates <em>only</em> if you’ve opted in.</li>
</ul>
<h2>Your personalisation content</h2>
<p>Photos and text you upload are used solely to create your order. We don’t sell or share your personal images with third parties for marketing.</p>
<h2>Sharing your information</h2>
<p>We only share information with trusted parties needed to fulfil your order — for example, our courier partner <strong>Delhivery</strong> for delivery, and our payment provider for processing. We don’t sell your personal data.</p>
<h2>Cookies</h2>
<p>Our website uses cookies to keep your cart working, remember preferences, and understand site usage. You can control cookies in your browser settings.</p>
<h2>Data security</h2>
<p>Our site uses SSL encryption (HTTPS). We take reasonable measures to protect your data, though no method of transmission over the internet is completely secure.</p>
<h2>Your rights</h2>
<p>You may request access to, correction of, or deletion of your personal data by emailing <a href="mailto:support@madeforu.co.in">support@madeforu.co.in</a>. We’ll respond within a reasonable time.</p>
<p><strong>Contact:</strong> MadeForU, Plot No. 93, Laxmi Nagar Colony, Korremula, Hyderabad, Telangana – 500088, India.</p>
HTML,
            ],
        ];
    }
}
