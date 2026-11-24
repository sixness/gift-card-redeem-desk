# Gift Card Redeem Desk

In-store redemption desk for WooCommerce gift cards, brandable per project.

- Staff web page (`/redeem-desk/`) for tablets, phones and desktops
- Look up by code or QR scan; QR codes in gift card emails via `[REDEEM_QR]` (generated on-site, no external service)
- Partial redeem, recharge and void, with outlet + reference on every transaction
- Full audit log, admin report with filters, CSV export, order notes
- Per-card DB lock to stop double spending across counters
- Brand / CI settings (logo, colours, fonts, radius, custom CSS) with JSON export/import for reuse
- Roles: Gift Card Staff (redeem) and Gift Card Manager (recharge, void, reports)
- Thai translation included
- Works with WP Swings / MakeWebBetter Ultimate Gift Cards; other plugins via the `gcrd_is_gift_card` filter

See [readme.txt](readme.txt) for setup, hooks and REST endpoints.

## Tests

```
php tests/run.php
```

Runs the ledger, lookup and settings tests against an in-memory SQLite stub (no WordPress needed).

## Build

```
cd .. && zip -r gift-card-redeem-desk.zip gift-card-redeem-desk -x "*/tests/*" "*/.git/*" "*/.gitignore" "*/README.md"
```
