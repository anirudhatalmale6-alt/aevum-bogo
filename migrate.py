#!/usr/bin/env python3
"""
Turns the separate GHK-CU listings into one variable product, and adds the new
sizes to bacteriostatic water and BPC-157/TB-500.

Everything goes through the WooCommerce REST API, so no theme, plugin or payment
setting is touched. Run --dry-run first: it prints exactly what it would change
and writes nothing.

  ./migrate.py --url https://aevumbio.shop --key ck_... --secret cs_... --dry-run
  ./migrate.py --url https://aevumbio.shop --key ck_... --secret cs_...
"""

import argparse
import json
import sys
import requests

# ---------------------------------------------------------------------------
# What the client asked for.
# ---------------------------------------------------------------------------

PLAN = {
    # Two listings become one, on a generic URL.
    "merges": [
        {
            "keep": "ghk-cu-50mg",             # this listing survives and becomes the parent
            "retire": ["ghk-cu-100mg"],        # these are folded in and then unpublished
            "new_name": "GHK-CU",
            "new_slug": "ghk-cu",
            "sizes": [                          # order is the order of the dropdown
                {"label": "50mg", "from": "ghk-cu-50mg"},
                {"label": "100mg", "from": "ghk-cu-100mg"},
            ],
        }
    ],
    # A simple product gains a size dropdown; the existing price becomes the first size.
    "add_sizes": [
        {
            "slug": "bacteriostatic-water-3ml",
            "new_name": "Bacteriostatic Water",
            "new_slug": "bacteriostatic-water",
            "sizes": [
                {"label": "3ML", "price": None},   # None = keep the price it has now
                {"label": "10ML", "price": "15"},
            ],
        },
        {
            "slug": "bpc-157-tb-500-10mg",
            "new_name": None,                     # leave the name alone
            "new_slug": None,                     # leave the URL alone
            "sizes": [
                {"label": "10mg", "price": None},
                {"label": "20mg", "price": "90"},
            ],
        },
    ],
}

ATTRIBUTE_NAME = "Size"


class Woo:
    def __init__(self, base, key, secret, dry_run=False):
        self.root = base.rstrip("/")
        self.auth = (key, secret)
        self.dry_run = dry_run
        self.session = requests.Session()
        self.pretty = True   # flipped on the first call if the site has plain permalinks

    def _url(self, path, params):
        """Handles both pretty permalinks and the ?rest_route= fallback."""
        if self.pretty:
            return self.root + "/wp-json/wc/v3/" + path, params
        merged = dict(params or {})
        merged["rest_route"] = "/wc/v3/" + path
        return self.root + "/", merged

    def _call(self, method, path, params=None, **kw):
        url, params = self._url(path, params)
        r = self.session.request(method, url, auth=self.auth, params=params, timeout=60, **kw)
        if self.pretty and (r.status_code == 404 or not r.text.strip().startswith(("{", "["))):
            self.pretty = False
            url, params = self._url(path, params)
            r = self.session.request(method, url, auth=self.auth, params=params, timeout=60, **kw)
        if r.status_code >= 400:
            raise RuntimeError(f"{method} {path} -> {r.status_code} {r.text[:400]}")
        return r.json() if r.text else {}

    def get(self, path, **params):
        return self._call("GET", path, params=params)

    # note: params are stripped back out of kw by _call

    def write(self, method, path, payload, what):
        if self.dry_run:
            print(f"    WOULD {method} {path}: {json.dumps(payload)[:220]}")
            return {"id": 0, "_dry_run": True}
        result = self._call(method, path, json=payload)
        print(f"    {what}")
        return result


def find_product(woo, slug):
    hits = woo.get("products", slug=slug, status="any")
    return hits[0] if hits else None


def ensure_attribute(woo):
    """Returns the id of the global Size attribute, creating it if the store has none."""
    for attr in woo.get("products/attributes"):
        if attr["name"].lower() == ATTRIBUTE_NAME.lower() or attr["slug"] in ("pa_size", "size"):
            return attr["id"]
    print(f"  no global '{ATTRIBUTE_NAME}' attribute on this store, creating one")
    created = woo.write("POST", "products/attributes",
                        {"name": ATTRIBUTE_NAME, "slug": "size", "type": "select", "order_by": "menu_order"},
                        f"created attribute '{ATTRIBUTE_NAME}'")
    return created.get("id", 0)


def ensure_terms(woo, attr_id, labels):
    """Creates any missing sizes and orders them the way the client reads them.

    Without an explicit menu_order the dropdown falls back to alphabetical, which
    puts 100mg above 50mg and 10ML above 3ML.
    """
    if not attr_id:
        return
    existing = {t["name"].lower(): t for t in woo.get(f"products/attributes/{attr_id}/terms", per_page=100)}
    for position, label in enumerate(labels):
        term = existing.get(label.lower())
        if not term:
            woo.write("POST", f"products/attributes/{attr_id}/terms",
                      {"name": label, "menu_order": position}, f"added size '{label}'")
        elif term.get("menu_order") != position:
            woo.write("PUT", f"products/attributes/{attr_id}/terms/{term['id']}",
                      {"menu_order": position}, f"size '{label}' moved to position {position + 1}")


def variation_payload(source, label, price, attr_id):
    """Builds a variation that inherits what the original simple listing had.

    The attribute id matters: without it WooCommerce records the size as a local
    attribute and the variation ends up matching "any size", which breaks the dropdown.
    """
    payload = {
        "regular_price": str(price if price is not None else source.get("regular_price") or source.get("price") or "0"),
        "attributes": [{"id": attr_id, "name": ATTRIBUTE_NAME, "option": label}],
        "status": "publish",
    }
    if source.get("sale_price"):
        payload["sale_price"] = source["sale_price"]
    if source.get("manage_stock"):
        payload["manage_stock"] = True
        payload["stock_quantity"] = source.get("stock_quantity") or 0
    payload["stock_status"] = source.get("stock_status", "instock")
    if source.get("weight"):
        payload["weight"] = source["weight"]
    dims = source.get("dimensions") or {}
    if any( dims.values() ):
        payload["dimensions"] = dims
    if source.get("sku"):
        payload["sku"] = source["sku"]
    imgs = source.get("images") or []
    if imgs:
        payload["image"] = {"id": imgs[0]["id"]}
    return payload


def merge_images(keeper, donors):
    """Keeper's images first, then anything the donors add, with no duplicates."""
    seen, merged = set(), []
    for product in [keeper] + donors:
        for img in product.get("images") or []:
            if img["id"] not in seen:
                seen.add(img["id"])
                merged.append({"id": img["id"]})
    return merged


def do_merge(woo, attr_id, spec, redirects):
    print(f"\n  merge: {', '.join([spec['keep']] + spec['retire'])}  ->  /product/{spec['new_slug']}/")
    keeper = find_product(woo, spec["keep"])
    if not keeper:
        print(f"    SKIPPED - no product with slug '{spec['keep']}'")
        return
    donors = []
    for slug in spec["retire"]:
        d = find_product(woo, slug)
        if not d:
            print(f"    SKIPPED - no product with slug '{slug}'")
            return
        donors.append(d)

    by_slug = {spec["keep"]: keeper}
    by_slug.update({d["slug"]: d for d in donors})

    labels = [s["label"] for s in spec["sizes"]]
    ensure_terms(woo, attr_id, labels)

    reviews = keeper.get("rating_count", 0) + sum(d.get("rating_count", 0) for d in donors)
    print(f"    keeper #{keeper['id']}, folding in {[d['id'] for d in donors]}, {reviews} review(s) in play")

    woo.write("PUT", f"products/{keeper['id']}", {
        "name": spec["new_name"],
        "slug": spec["new_slug"],
        "type": "variable",
        "images": merge_images(keeper, donors),
        "attributes": [{
            "id": attr_id,
            "name": ATTRIBUTE_NAME,
            "position": 0,
            "visible": True,
            "variation": True,
            "options": labels,
        }],
    }, f"#{keeper['id']} is now a variable product at /product/{spec['new_slug']}/")

    for size in spec["sizes"]:
        source = by_slug.get(size["from"], keeper)
        woo.write("POST", f"products/{keeper['id']}/variations",
                  variation_payload(source, size["label"], size.get("price"), attr_id),
                  f"size {size['label']} at ${source.get('regular_price') or source.get('price')}")

    for donor in donors:
        woo.write("PUT", f"products/{donor['id']}", {"status": "draft", "catalog_visibility": "hidden"},
                  f"#{donor['id']} ({donor['slug']}) unpublished, its URL now redirects")
        redirects[f"/product/{donor['slug']}/"] = f"/product/{spec['new_slug']}/"

    if spec["keep"] != spec["new_slug"]:
        redirects[f"/product/{spec['keep']}/"] = f"/product/{spec['new_slug']}/"


def do_add_sizes(woo, attr_id, spec, redirects):
    print(f"\n  add sizes to /product/{spec['slug']}/: {', '.join(s['label'] for s in spec['sizes'])}")
    product = find_product(woo, spec["slug"])
    if not product:
        print(f"    SKIPPED - no product with slug '{spec['slug']}'")
        return

    labels = [s["label"] for s in spec["sizes"]]
    ensure_terms(woo, attr_id, labels)

    payload = {
        "type": "variable",
        "attributes": [{
            "id": attr_id,
            "name": ATTRIBUTE_NAME,
            "position": 0,
            "visible": True,
            "variation": True,
            "options": labels,
        }],
    }
    if spec.get("new_name"):
        payload["name"] = spec["new_name"]
    if spec.get("new_slug"):
        payload["slug"] = spec["new_slug"]

    woo.write("PUT", f"products/{product['id']}", payload, f"#{product['id']} is now a variable product")

    existing = {}
    if product.get("type") == "variable":
        for v in woo.get(f"products/{product['id']}/variations", per_page=100):
            for a in v.get("attributes", []):
                existing[str(a.get("option", "")).lower()] = v

    for size in spec["sizes"]:
        if size["label"].lower() in existing:
            print(f"    size {size['label']} already exists, left alone")
            continue
        woo.write("POST", f"products/{product['id']}/variations",
                  variation_payload(product, size["label"], size.get("price"), attr_id),
                  f"size {size['label']} at ${size.get('price') or product.get('regular_price')}")

    if spec.get("new_slug") and spec["new_slug"] != spec["slug"]:
        redirects[f"/product/{spec['slug']}/"] = f"/product/{spec['new_slug']}/"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True)
    ap.add_argument("--key", required=True)
    ap.add_argument("--secret", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    woo = Woo(args.url, args.key, args.secret, dry_run=args.dry_run)

    print(("DRY RUN - nothing will be written" if args.dry_run else "LIVE RUN") + f" against {args.url}")
    try:
        woo.get("products", per_page=1)
    except RuntimeError as e:
        print(f"Could not reach the store: {e}")
        return 2

    attr_id = ensure_attribute(woo)
    redirects = {}

    for spec in PLAN["merges"]:
        do_merge(woo, attr_id, spec, redirects)
    for spec in PLAN["add_sizes"]:
        do_add_sizes(woo, attr_id, spec, redirects)

    print("\n  redirects to put in place (handled by the plugin):")
    for old, new in redirects.items():
        print(f"    {old}  ->  {new}")
    with open("redirects.json", "w") as fh:
        json.dump(redirects, fh, indent=2)

    print("\nDone." if not args.dry_run else "\nDry run complete, nothing was changed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
