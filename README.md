# Invoices for Slotara (free add-on)

Pages live at `/manage/invoices` and `/invoices/view/{private link}` (module.json `"url"`), so
white-labelled sites never show "slotara" in an address.

Professional invoices for every business on a Slotara platform.

- **Automatic**: every booking with a price gets an invoice, already marked paid when the booking
  was paid online; offline/pay-later bookings are marked paid when their payment comes in, and marking an
  invoice paid marks its booking paid too (invoice number as the payment reference).
  Cancelling an unpaid booking voids its invoice (reopened if the booking becomes active
  again); rescheduling keeps the same invoice.
- **By hand**: line items, quantity × price, tax, live totals, notes.
- **Customer link**: a clean, print-ready page (Print / Save as PDF) at a private link. No login
  needed, hidden from search engines.
- **At a glance**: outstanding, overdue and paid-this-month totals; a menu badge for unpaid invoices;
  overdue invoices highlighted with how late they are.
- **Settings per business**: name and details on invoices, number prefix, tax name and rate,
  payment terms, how-to-pay instructions, footer. Uses the business's logo and brand colour.

Business panel → **Payments → Invoices** (settings from the Invoices page).

## Develop

```bash
# Slotara .env: LICENTRA_MODULES_DEV=true
ln -s ~/Sites/gitlab/licentra/modules/slotara-invoices ~/Sites/gitlab/slotline/modules-dev/slotara-invoices
cd ~/Sites/gitlab/slotline && php artisan module:migrate slotara-invoices
php artisan test ../licentra/modules/slotara-invoices/tests/InvoicesModuleTest.php
```

## Release (free add-on)

1. Licentra admin → Products → New: slug `slotara-invoices`, Add-on for **Slotara**, pricing **Free**.
2. Bump `version` in module.json, then from the Slotara folder:
   `vendor/bin/licentra-release module modules-dev/slotara-invoices --upload`
3. Publish the release in Licentra admin.

## Tables

`slotara_invoices`, `slotara_invoices_items`, `slotara_invoices_settings` (all per business).
