# Aevum Bio — Buy One Get One

A small WooCommerce plugin: when a customer applies the promo code, a second unit
of each qualifying item drops into the cart free of charge, and stays free all the
way through checkout, the order and the thank-you page.

## Install

1. WordPress admin → Plugins → Add New → Upload Plugin → choose `aevum-bogo.zip` → Install → Activate.
2. That's it. It works straight away with the agreed settings:
   - code: `laborday`
   - one free unit per item, per order
   - qualifying items: Tesamorelin 10mg, GHK-CU (both sizes), Retatrutide (both sizes)

## Changing it later

WooCommerce → Buy One Get One.

- **Promo code** — what the customer types. Case does not matter.
- **Free units per item, per order** — `1` means one free unit no matter how many
  they buy. `0` removes the cap, so every paid unit earns a free one.
- **Items included** — tick any product or size. Variable products are listed one
  row per size, so you can include 10mg without including 20mg.

Ticking anything on that screen replaces the launch defaults.

## How it behaves

- The free unit is a separate cart line priced at `$0.00`, marked FREE, with the
  original price struck through.
- Its quantity cannot be edited and it has no remove link, so a customer cannot
  turn it into a paid line or strip it out and confuse the totals.
- Remove the code and the free lines disappear. Empty the cart, change quantities,
  switch sizes — the free lines re-sync on the next page load.
- The code is refused with a clear message if nothing in the cart qualifies.
- Products outside the list are never touched.
- A free line can never earn another free line.
- Stock is respected: if there isn't enough stock for the free unit, it isn't added.

The code is defined in the plugin rather than as a coupon in Marketing → Coupons,
so it can't be accidentally edited into a real percentage discount.

## What was tested

Two suites, both green, against WordPress + WooCommerce with these exact products:

`test/cli_test.php` — 21 checks on the cart logic itself:
no free unit before the code, free unit on the code, cap of 1 respected at paid
quantity 3, uncapped mode giving 3 free, ineligible product untouched, code removal
cleaning up, per-size handling on variable products, both sizes qualifying
independently, the code refused when nothing qualifies, and no runaway lines after
repeated totals passes.

`test/test_bogo.py` — 14 checks through a real browser: cart display, badge,
totals, cap, ineligible product, code removal, a variation-level offer, the
checkout page, a completed order and the thank-you page.
