# One change the website needs

The app can take an order with no customer details — a walk-in sale. The
website cannot yet **edit** one, because `save.php`'s `customer()` function
demands a name and a 10-digit phone on every write:

```php
function customer(array $post): array {
    $name  = trim($post['name'] ?? '');
    $phone = normalise_phone($post['phone'] ?? '');
    $notes = trim($post['notes'] ?? '');
    if ($name === '')          throw new Exception('Customer name is required.');
    if (strlen($phone) !== 10) throw new Exception('Phone must be 10 digits.');
    return [$name, $phone, ($notes !== '' ? $notes : null)];
}
```

Open a walk-in order on `edit.php`, change anything, press save, and it is
refused with "Phone must be 10 digits." — the order is stuck until someone
invents a phone number for it.

## The fix

Replace that function in `save.php` with this. Nothing else changes, and
every existing order keeps behaving exactly as it does now.

```php
/**
 * Customer details. Both are optional: a stall sale where nobody wants to
 * give a number is a walk-in, stored as name 'Walk-in' with an empty
 * phone. A phone that IS given must still be 10 digits — silently storing
 * a typo would break every wa.me link built from it later.
 */
function customer(array $post): array {
    $name  = trim($post['name'] ?? '');
    $raw   = trim($post['phone'] ?? '');
    $notes = trim($post['notes'] ?? '');

    $phone = $raw === '' ? '' : normalise_phone($raw);
    if ($raw !== '' && strlen($phone) !== 10) {
        throw new Exception('Phone must be 10 digits, or leave it empty for a walk-in sale.');
    }
    if ($name === '') $name = 'Walk-in';

    return [$name, $phone, ($notes !== '' ? $notes : null)];
}
```

## Why this is safe

- `orders.phone` stays `NOT NULL`; a walk-in stores `''`, not NULL. No
  index, foreign key or query changes meaning.
- `whatsapp_link()` already returns `null` for an unusable number, so the
  WhatsApp buttons hide themselves on a walk-in order rather than linking
  to a broken page.
- `normalise_phone('')` returns `''`, so nothing downstream sees a
  malformed value.
- The website's **new order** form still asks for a name and phone as it
  does today; only the validation floor moves. If you want walk-ins from
  the website too, drop the `required` attribute from those two inputs in
  `index.php`.

## Optional: the same field on index.php

The order list shows `$o['name']`, which reads "Walk-in" for these orders —
no change needed. If you want them visually marked, the app's own test is
`trim($o['phone']) === ''`.
