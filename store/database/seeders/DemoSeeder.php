<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Services\WooImporter;
use Illuminate\Database\Seeder;

/** Sample catalogue for trying the store locally: php artisan db:seed --class=DemoSeeder. Not for production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $gifts = Category::create(['name' => 'Photo Frames', 'slug' => 'photo-frames', 'position' => 1]);
        $wall = Category::create(['name' => 'Wall Frames', 'slug' => 'wall-frames', 'parent_id' => $gifts->id]);
        $mugs = Category::create(['name' => 'Custom Mugs', 'slug' => 'custom-mugs', 'position' => 2]);
        $lamps = Category::create(['name' => 'Photo Lamps', 'slug' => 'photo-lamps', 'position' => 3]);
        $importer = new WooImporter;

        $frame = Product::create([
            'name' => 'Personalised Wooden Photo Frame', 'slug' => 'personalised-wooden-photo-frame', 'sku' => 'MFU-FRAME',
            'type' => 'variable', 'price' => 499, 'sale_price' => 399, 'is_featured' => true, 'primary_category_id' => $wall->id,
            'short_description' => '<p>Your favourite photo, printed and framed in solid wood.</p>',
            'description' => '<p>A handcrafted wooden frame with your photo printed on premium matte paper. Perfect for birthdays and anniversaries.</p>',
            'focus_keyword' => 'personalised photo frame',
            'faqs' => [['q' => 'Can I see a preview?', 'a' => 'Yes, we send a design preview on WhatsApp before printing.']],
        ]);
        $frame->categories()->sync([$gifts->id, $wall->id]);
        $frame->productAttributes()->create(['name' => 'Size', 'values' => ['8x10', '12x15'], 'is_variation' => true]);
        $frame->productAttributes()->create(['name' => 'Frame', 'values' => ['Black', 'Walnut'], 'is_variation' => true]);
        $i = 0;
        foreach (['8x10' => [499, 399], '12x15' => [799, 649]] as $size => [$p, $s]) {
            foreach (['Black', 'Walnut'] as $color) {
                $frame->variants()->create(['options' => ['Size' => $size, 'Frame' => $color], 'price' => $p, 'sale_price' => $s, 'sku' => "MFU-FRAME-{$size}-{$color}", 'position' => $i++]);
            }
        }
        $importer->defaultCustomization($frame);

        $collage = Product::create([
            'name' => 'Collage Frame with 6 Photos', 'slug' => 'collage-frame-6-photos', 'sku' => 'MFU-COL6',
            'price' => 1299, 'sale_price' => 999, 'is_featured' => true, 'primary_category_id' => $gifts->id,
            'description' => '<p>Six of your memories in one beautiful collage.</p>',
        ]);
        $collage->categories()->sync([$gifts->id]);
        $importer->defaultCustomization($collage);

        $mug = Product::create([
            'name' => 'Magic Photo Mug', 'slug' => 'magic-photo-mug', 'sku' => 'MFU-MUG', 'price' => 449,
            'primary_category_id' => $mugs->id, 'description' => '<p>Turns from black to your photo when hot tea is poured.</p>',
        ]);
        $mug->categories()->sync([$mugs->id]);
        $importer->defaultCustomization($mug);
        $mug->customizationFields()->create(['type' => 'select', 'label' => 'Gift box', 'required' => false, 'options' => [['label' => 'No box', 'price' => 0], ['label' => 'Premium gift box', 'price' => 99]], 'position' => 2]);

        $lamp = Product::create(['name' => '3D Moon Photo Lamp', 'slug' => '3d-moon-photo-lamp', 'price' => 899, 'sale_price' => 749, 'primary_category_id' => $lamps->id, 'is_featured' => true]);
        $lamp->categories()->sync([$lamps->id]);
        $importer->defaultCustomization($lamp);

        Review::create(['product_id' => $frame->id, 'name' => 'Priya', 'rating' => 5, 'body' => 'Beautiful quality and arrived quickly. My parents loved it!', 'is_approved' => true]);
        Review::create(['product_id' => $mug->id, 'name' => 'Rahul', 'rating' => 4, 'body' => 'The magic effect is great, print is sharp.', 'is_approved' => true]);
        Coupon::create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'max_discount' => 200]);
        Page::create(['title' => 'About Us', 'slug' => 'about-us', 'body' => '<p>We make personalised gifts by hand in Hyderabad.</p>']);
    }
}
