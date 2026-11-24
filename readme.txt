=== Gift Card Redeem Desk ===
Requires at least: 6.2
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later

In-store redemption desk for WooCommerce gift cards, brandable per project.

== What it does ==

* A full-screen web page for counter staff (default address: /redeem-desk/). Works on tablets, phones and desktops.
* Look up a card by typing the code or scanning its QR code (in-page scanner on Chrome/Android; on iPhone, scan with the Camera app and the desk opens with the card filled in).
* Shows balance, original value, expiry, recipient, sender, buyer and message so staff can check ID.
* Redeem part or all of the balance, with an outlet (e.g. Front Desk, Restaurant, Spa) and a reference (folio / bill / room no.).
* Recharge (add value) and void (reverse) for managers.
* Every change is logged: who, how much, where, when, the balance before and after. Admin report with filters and CSV export.
* A note is added to the original gift card order for every transaction.
* A per-card database lock stops two counters spending the same balance at the same time.
* Prints a small receipt slip (80 mm printers).
* QR code in gift card emails via the [REDEEM_QR] placeholder. The QR is generated on your own site, with no outside QR service.
* Thai translation included. Each staff member gets the desk in their own profile language.

The balance is the WooCommerce coupon amount, so it always agrees with checkout and with your gift card plugin's balance page.

== Works with ==

* WP Swings "Ultimate Gift Cards for WooCommerce" (formerly MakeWebBetter), old mwb_ and new wps_ data.
* Any other plugin that issues WooCommerce coupons: either set "Which coupons are gift cards" to "Any fixed-cart coupon", or mark cards with the `gcrd_is_gift_card` filter.

== Setup ==

1. Plugins > Add New > Upload Plugin, choose the zip, then Activate.
2. Redeem Desk > Settings: set the brand (logo, colours, fonts), the outlets, and the reference rules.
3. Users: give counter staff the "Gift Card Staff" role (redeem only) and supervisors the "Gift Card Manager" role (redeem, recharge, void, reports). Administrators and Shop Managers can do everything.
4. Put [REDEEM_QR] in the gift card email template where the QR code should appear.
5. Open the desk address on the counter tablet and log in.

== Reusing on another project ==

Redeem Desk > Settings > "Download settings (.json)" exports the whole setup (brand, outlets, rules). Paste it into "Import settings" on another site, then change the logo and colours to that brand's CI. For deeper changes use the Custom CSS box; every desk element has a class starting with .gcrd-.

== For developers ==

Filters: gcrd_is_gift_card ( bool, WC_Coupon ), gcrd_card_data ( array, WC_Coupon ), gcrd_outlets ( string[] ), gcrd_lookup_limit_per_10min ( int ).
Action: gcrd_transaction_recorded ( array $transaction, WC_Coupon $coupon ).
Helpers: gcrd_desk_url_for_code( $code ), gcrd_qr_image_url( $code ), GCRD_QR_Endpoint::img_html( $code ).
REST (cookie + nonce): GET gcrd/v1/card?code=, POST gcrd/v1/card/redeem, POST gcrd/v1/card/recharge, POST gcrd/v1/transactions/{id}/void, GET gcrd/v1/transactions.

== Notes ==

* Transactions made before this plugin (for example on an outside gifting portal) are not in the desk history, but the balance is always the live coupon amount.
* Deleting the plugin keeps the transaction log unless "Delete settings and the transaction log" is ticked.
