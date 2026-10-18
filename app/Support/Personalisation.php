<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Ready-made personalisation setups. Each field: type, label, help, required, min/max photos,
 * max length, slot labels (photo fields: one label per photo, e.g. "His photo").
 */
class Personalisation
{
    private const PHOTO_HELP = 'Clear, well-lit photos print best. JPG, PNG or HEIC.';

    public static function presets(): array
    {
        $photo = fn (int $n, string $label = '', array $slots = []) => [
            'type' => 'images', 'label' => $label ?: ($n > 1 ? "Upload your {$n} photos" : 'Upload your photo'),
            'help' => self::PHOTO_HELP, 'required' => true, 'min_files' => $n, 'max_files' => $n, 'slots' => $slots,
        ];
        $text = fn (string $label, int $max, bool $required = true, string $help = '') => [
            'type' => 'text', 'label' => $label, 'help' => $help, 'required' => $required, 'max_length' => $max,
        ];

        return [
            'photo1' => ['1 photo', [$photo(1)]],
            'photo2' => ['2 photos', [$photo(2)]],
            'photo4' => ['4 photos', [$photo(4)]],
            'photo6' => ['6 photos', [$photo(6)]],
            'photo1_name' => ['1 photo + name', [$photo(1), $text('Name to print', 30, true, 'Exactly as it should appear.')]],
            'photo2_name' => ['2 photos + name', [$photo(2), $text('Name to print', 30, true, 'Exactly as it should appear.')]],
            'photo1_message' => ['1 photo + message', [$photo(1), ['type' => 'textarea', 'label' => 'Message to print', 'help' => 'Leave blank for no message.', 'required' => false, 'max_length' => 150]]],
            'couple' => ['Couple (his & her photo + names)', [$photo(2, 'Upload your photos', ['His photo', 'Her photo']), $text('Names', 40, true, 'e.g. Ravi & Priya')]],
            'photo1_name_date' => ['1 photo + name + date', [$photo(1), $text('Name to print', 30), ['type' => 'date', 'label' => 'Special date', 'help' => 'Birthday, anniversary…', 'required' => false]]],
            'front_back' => ['Front & back photos', [$photo(2, 'Upload your photos', ['Front side', 'Back side'])]],
            'text_only' => ['Name / text only', [$text('Name / text to print', 40)]],
            'none' => ['No personalisation', []],
        ];
    }

    /** Fields of a product in the same shape as a preset (to copy it to others). */
    public static function fromProduct(Product $product): array
    {
        return $product->customizationFields->map(fn ($f) => [
            'type' => $f->type, 'label' => $f->label, 'help' => $f->help, 'required' => $f->required,
            'min_files' => $f->min_files, 'max_files' => $f->max_files, 'max_length' => $f->max_length,
            'extra_price' => (float) $f->extra_price,
            'slots' => $f->type === 'images' ? collect($f->options ?? [])->pluck('label')->all() : [],
            'options' => $f->type === 'select' ? $f->options : null,
        ])->all();
    }

    /** Replace a product's personalisation fields. Past orders keep their own copy, so nothing is lost. */
    public static function apply(Product $product, array $fields): void
    {
        DB::transaction(function () use ($product, $fields) {
            $product->customizationFields()->delete();
            foreach (array_values($fields) as $i => $f) {
                $product->customizationFields()->create([
                    'type' => $f['type'],
                    'label' => $f['label'],
                    'help' => $f['help'] ?? null,
                    'required' => (bool) ($f['required'] ?? false),
                    'min_files' => (int) ($f['min_files'] ?? 1),
                    'max_files' => (int) ($f['max_files'] ?? 1),
                    'max_length' => $f['max_length'] ?? null,
                    'options' => $f['type'] === 'images'
                        ? (($f['slots'] ?? []) ? array_map(fn ($s) => ['label' => $s], $f['slots']) : null)
                        : ($f['options'] ?? null),
                    'extra_price' => $f['extra_price'] ?? 0,
                    'position' => $i,
                ]);
            }
        });
    }
}
