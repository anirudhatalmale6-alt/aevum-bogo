"""End-to-end check of the Buy One Get One plugin on the local test store.

Covers: free unit added on code, correct totals, cap respected, ineligible
products untouched, code removal cleans up, and the order/thank-you page.
"""
import re
import sys
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8899"
SHOT = "/var/lib/freelancer/projects/40666439/test/shots"
results = []


def check(label, ok, detail=""):
    results.append((label, ok, detail))
    print(("PASS  " if ok else "FAIL  ") + label + ("   " + detail if detail else ""))


def cart_lines(page):
    """Returns [(name, subtotal_text)] for each cart row, block or shortcode cart."""
    rows = []
    for sel in ("tr.woocommerce-cart-form__cart-item", ".wc-block-cart-items__row"):
        for row in page.query_selector_all(sel):
            txt = " ".join(row.inner_text().split())
            rows.append(txt)
        if rows:
            break
    return rows


def totals_text(page):
    for sel in (".cart_totals", ".wc-block-components-totals-wrapper", ".order-total"):
        el = page.query_selector(sel)
        if el:
            return " ".join(el.inner_text().split())
    return ""


def add_to_cart(page, product_slug, variation=None, qty=1):
    page.goto(f"{BASE}/?product={product_slug}", wait_until="domcontentloaded")
    if variation:
        sel = page.query_selector("select")
        if sel:
            sel.select_option(label=variation)
            page.wait_for_timeout(600)
    if qty > 1:
        q = page.query_selector("input.qty")
        if q:
            q.fill(str(qty))
    btn = page.query_selector("button[name=add-to-cart], button.single_add_to_cart_button")
    btn.click()
    page.wait_for_timeout(1200)


def apply_code(page, code):
    page.goto(f"{BASE}/?page_id={CART_ID}", wait_until="domcontentloaded")
    field = page.query_selector("#coupon_code")
    if not field:
        return False
    field.fill(code)
    page.query_selector("button[name=apply_coupon]").click()
    page.wait_for_timeout(1500)
    return True


with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    # Discover the cart page id.
    page.goto(f"{BASE}/?page_id=6", wait_until="domcontentloaded")
    CART_ID = 6
    if "cart" not in page.title().lower():
        for pid in range(2, 30):
            page.goto(f"{BASE}/?page_id={pid}", wait_until="domcontentloaded")
            if "cart" in page.title().lower():
                CART_ID = pid
                break
    print(f"cart page id = {CART_ID} ({page.title()})")

    # --- 1. Eligible item + code -> free unit appears ---------------------
    add_to_cart(page, "tesamorelin-10mg")
    apply_code(page, "laborday")
    page.screenshot(path=f"{SHOT}/01-cart-bogo.png")
    lines = cart_lines(page)
    body = " ".join(lines)
    check("free unit added for Tesamorelin", body.count("Tesamorelin") >= 2, f"{len(lines)} rows")
    check("free unit shows FREE badge", "FREE" in body.upper())
    tot = totals_text(page)
    check("total is 80.00, not 160.00", "80.00" in tot and "160.00" not in tot, tot[:90])

    # --- 2. Cap of 1: paid qty 3 still gives exactly 1 free ---------------
    page.goto(f"{BASE}/?page_id={CART_ID}", wait_until="domcontentloaded")
    qty_input = page.query_selector("input.qty")
    if qty_input:
        qty_input.fill("3")
        page.query_selector("button[name=update_cart]").click()
        page.wait_for_timeout(1500)
    page.screenshot(path=f"{SHOT}/02-cart-cap.png")
    tot = totals_text(page)
    check("cap holds: 3 paid + 1 free = 240.00", "240.00" in tot, tot[:90])

    # --- 3. Ineligible product is untouched -------------------------------
    add_to_cart(page, "nad-500mg")
    page.goto(f"{BASE}/?page_id={CART_ID}", wait_until="domcontentloaded")
    page.screenshot(path=f"{SHOT}/03-cart-ineligible.png")
    rows = cart_lines(page)
    nad_rows = [r for r in rows if "NAD" in r]
    check("NAD+ gets no free unit", len(nad_rows) == 1 and "FREE" not in " ".join(nad_rows).upper(),
          f"{len(nad_rows)} NAD row(s)")
    tot = totals_text(page)
    check("total now 310.00 (240 + 70)", "310.00" in tot, tot[:90])

    # --- 4. Removing the code removes the free unit -----------------------
    remove = page.query_selector("a.woocommerce-remove-coupon")
    if remove:
        remove.click()
        page.wait_for_timeout(1500)
    page.screenshot(path=f"{SHOT}/04-cart-code-removed.png")
    body = " ".join(cart_lines(page))
    check("free unit removed with the code", body.upper().count("FREE") == 0, body[:80])
    tot = totals_text(page)
    check("total back to 310.00 paid-only", "310.00" in tot, tot[:90])

    # --- 5. Variation-level: Retatrutide 10mg only ------------------------
    page.goto(f"{BASE}/?page_id={CART_ID}", wait_until="domcontentloaded")
    while True:
        link = page.query_selector("a.remove")
        if not link:
            break
        link.click()
        page.wait_for_timeout(1200)
    add_to_cart(page, "retatrutide", variation="10mg")
    apply_code(page, "laborday")
    page.screenshot(path=f"{SHOT}/05-cart-variation.png")
    rows = cart_lines(page)
    reta = [r for r in rows if "Retatrutide" in r]
    check("Retatrutide 10mg gets its free unit",
          len(reta) == 2 and any("FREE" in r.upper() for r in reta), f"{len(reta)} rows")
    tot = totals_text(page)
    check("Retatrutide total is 90.00", "90.00" in tot, tot[:90])

    # --- 6. Checkout and thank-you page -----------------------------------
    page.goto(f"{BASE}/?page_id={CART_ID + 1}", wait_until="domcontentloaded")
    if "checkout" not in page.title().lower():
        for pid in range(2, 30):
            page.goto(f"{BASE}/?page_id={pid}", wait_until="domcontentloaded")
            if "checkout" in page.title().lower():
                break
    page.wait_for_timeout(1500)
    page.screenshot(path=f"{SHOT}/06-checkout.png")
    order_review = page.inner_text("body")
    check("free unit shown on checkout", order_review.count("Retatrutide") >= 2)

    fields = {
        "#billing_first_name": "Test", "#billing_last_name": "Buyer",
        "#billing_address_1": "1 Test Street", "#billing_city": "Austin",
        "#billing_postcode": "78701", "#billing_phone": "5125550100",
        "#billing_email": "buyer@example.com",
    }
    for sel, val in fields.items():
        el = page.query_selector(sel)
        if el:
            el.fill(val)
    cod = page.query_selector("#payment_method_cod")
    if cod:
        cod.check()
    place = page.query_selector("#place_order")
    if place:
        place.click()
        page.wait_for_timeout(6000)
    page.screenshot(path=f"{SHOT}/07-thankyou.png")
    ty = page.inner_text("body")
    check("order placed", "received" in ty.lower() or "thank" in ty.lower(), page.title()[:60])
    check("free unit on thank-you page", ty.count("Retatrutide") >= 2)
    m = re.search(r"Total:?\s*\$?([\d,]+\.\d\d)", ty)
    check("thank-you total is 90.00", bool(m) and m.group(1) == "90.00", m.group(1) if m else "no total found")

    browser.close()

print("\n" + "=" * 60)
failed = [r for r in results if not r[1]]
print(f"{len(results) - len(failed)}/{len(results)} checks passed")
for label, ok, detail in failed:
    print("  FAILED:", label, detail)
sys.exit(1 if failed else 0)
