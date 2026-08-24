"""Checks the merged/variable products from a customer's point of view."""
import sys
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8899"
SHOT = "/var/lib/freelancer/projects/40666439/test/shots"
results = []


def check(label, ok, detail=""):
    results.append((label, ok, detail))
    print(("PASS  " if ok else "FAIL  ") + label + ("   " + detail if detail else ""))


with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    # --- merged GHK-CU listing --------------------------------------------
    page.goto(f"{BASE}/?product=ghk-cu", wait_until="domcontentloaded")
    page.wait_for_timeout(1200)
    page.screenshot(path=f"{SHOT}/10-ghkcu-merged.png")
    body = page.inner_text("body")
    check("merged listing loads", "GHK-CU" in body, page.title()[:50])

    select = page.query_selector("select")
    options = [o.strip() for o in select.inner_text().split("\n") if o.strip()] if select else []
    check("size dropdown offers both sizes",
          any("50mg" in o for o in options) and any("100mg" in o for o in options), str(options))

    select.select_option(label="100mg")
    page.wait_for_timeout(900)
    price = page.inner_text(".woocommerce-variation-price, .single_variation") if page.query_selector(".single_variation") else ""
    check("picking 100mg shows $70.00", "70.00" in price, " ".join(price.split())[:60])

    select.select_option(label="50mg")
    page.wait_for_timeout(900)
    price = page.inner_text(".woocommerce-variation-price, .single_variation") if page.query_selector(".single_variation") else ""
    check("picking 50mg shows $55.00", "55.00" in price, " ".join(price.split())[:60])

    # --- it still buys, and still earns the free unit ----------------------
    page.query_selector("button.single_add_to_cart_button").click()
    page.wait_for_timeout(1500)
    page.goto(f"{BASE}/?page_id=6", wait_until="domcontentloaded")
    page.query_selector("#coupon_code").fill("laborday")
    page.query_selector("button[name=apply_coupon]").click()
    page.wait_for_timeout(1800)
    page.screenshot(path=f"{SHOT}/11-ghkcu-cart-bogo.png")
    rows = [" ".join(r.inner_text().split()) for r in page.query_selector_all("tr.woocommerce-cart-form__cart-item")]
    ghk = [r for r in rows if "GHK-CU" in r]
    check("merged product still qualifies for the offer",
          len(ghk) == 2 and any("FREE" in r.upper() for r in ghk), f"{len(ghk)} rows")
    totals = " ".join(page.inner_text(".cart_totals").split())
    check("total is 55.00, the free 50mg costs nothing", "55.00" in totals and "110.00" not in totals, totals[:80])

    # --- the two other listings that gained a size ------------------------
    for slug, sizes, first_price in (
        ("bacteriostatic-water", ("3ML", "10ML"), "7.00"),
        ("bpc-157-tb-500-10mg", ("10mg", "20mg"), "75.00"),
    ):
        page.goto(f"{BASE}/?product={slug}", wait_until="domcontentloaded")
        page.wait_for_timeout(1000)
        sel = page.query_selector("select")
        opts = sel.inner_text() if sel else ""
        check(f"{slug} offers {sizes[0]} and {sizes[1]}",
              sizes[0] in opts and sizes[1] in opts, " ".join(opts.split())[:50])
        if sel:
            sel.select_option(label=sizes[0])
            page.wait_for_timeout(900)
            txt = page.inner_text(".single_variation") if page.query_selector(".single_variation") else ""
            check(f"{slug} {sizes[0]} keeps its old price ${first_price}", first_price in txt, " ".join(txt.split())[:50])
    page.screenshot(path=f"{SHOT}/12-bacwater.png")

    # --- the retired listing must not 404 ---------------------------------
    for old in ("ghk-cu-100mg", "ghk-cu-50mg", "bacteriostatic-water-3ml"):
        resp = page.goto(f"{BASE}/?product={old}", wait_until="domcontentloaded")
        status = resp.status if resp else 0
        landed = page.url
        check(f"/product/{old}/ does not dead-end",
              status == 200 and "404" not in page.title().lower(), f"{status} -> {landed.split('?')[-1][:40]}")

    browser.close()

print("\n" + "=" * 60)
failed = [r for r in results if not r[1]]
print(f"{len(results) - len(failed)}/{len(results)} checks passed")
for label, ok, detail in failed:
    print("  FAILED:", label, detail)
sys.exit(1 if failed else 0)
