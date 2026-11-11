# WhatsApp messages

Website → **WhatsApp messages** (sidebar). Send one message, or the same
message to a list from Excel or CSV, personalised per row.

## Free or paid

| | Free | Automatic |
|---|---|---|
| Cost | ₹0 | Meta charges per message (marketing costs more than order updates; see Meta's WhatsApp pricing for India) |
| How | Each chat opens in WhatsApp Web, the desktop app or the phone with the message typed in; you press Send. One click per contact. | Sends by itself through Meta's WhatsApp Business Cloud API |
| Wording | Anything | Only templates Meta has approved |
| Setup | None | One-time, below |
| Risk to your number | None | None (official) |

Not built: bots that click through WhatsApp Web for you. They break
WhatsApp's terms, they are how business numbers get banned, and shared
hosting cannot run them anyway.

## The list

- Demo file: download it from the page (Excel or CSV). Replace its rows;
  its three sample numbers are skipped automatically, so a forgotten demo
  row is never messaged.
- A column named **Phone** (or Mobile, WhatsApp, Number) is required.
  Numbers can be written any way: `98765 43210`, `+91 98765 43210`,
  `098765 43210`.
- Every other column can go into the message: `{Name}`, `{Order}`,
  `{Amount}`… `{first_name}` is the first word of Name.
- Invalid numbers, duplicates and numbers on the **do-not-message list**
  are skipped, and the page says how many.

## Automatic sending: one-time setup

1. developers.facebook.com → create an app (Business) → add **WhatsApp**.
2. WhatsApp → API Setup: add the business number (a number on the API
   cannot also stay in the normal WhatsApp app) and copy its
   **Phone number ID**.
3. Business Settings → System users: create one, assign the app, generate
   a **permanent access token** with `whatsapp_business_messaging`.
4. WhatsApp Manager → Message templates: create one, e.g. `order_ready`:
   "Hi {{1}}, your order {{2}} of ₹{{3}} is ready." Wait for approval.
5. Add a payment method in Meta Business.
6. On the WhatsApp page, open *Automatic sending* and fill in the token,
   the phone number ID, the template name and its language, and which
   columns fill {{1}}, {{2}}, … (e.g. `Name, Order, Amount`).

New numbers can message a limited number of people a day until Meta
raises the limit. The send runs from the open page, about one message a
second; pause and resume any time; failures can be retried.

Tables `wa_campaigns` and `wa_recipients` create themselves on first use
(`api/migrations/2026-10-whatsapp.sql` if you prefer to run it).
