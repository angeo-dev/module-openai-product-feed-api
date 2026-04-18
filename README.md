# Angeo OpenAI Product Feed API — Magento 2

[![Packagist](https://img.shields.io/packagist/v/angeo/module-openai-product-feed-api.svg)](https://packagist.org/packages/angeo/module-openai-product-feed-api)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)

**Magento 2 REST API for the OpenAI Agentic Commerce Protocol (ACP). Full 7-endpoint feed surface with DB-persisted feeds, paginated product export, and SalesRule → Promotion mapping.**

---

## What this module fixes

- **DB-persisted feeds** — feed IDs survive cache flushes and server restarts (`angeo_acp_feed` table via Schema Patch)
- **Proper pagination** — `getProducts` now iterates the full catalog in batches (v1 silently capped at 100 products)
- **PATCH workaround documented** — Magento has no native PATCH support; upserts exposed via `/upsert` POST + nginx rewrite
- **Structured validation errors** — upsert responses include `errors: string[]` per field, not a silent `false`
- **Category names resolved** — products now return `{value: "Tools", taxonomy: "merchant"}` instead of `{value: "42"}`
- **ImageUrlBuilder** — product images use Magento's proper resized URL builder, not raw file paths
- **`/invalidate` endpoint** — bust product cache without a deploy
- **Coupon codes in promotion descriptions** — auto-appended when rule has a specific coupon
- **Multiple benefits per promotion** — e.g. 10% off + free shipping in one `benefits` array
- **`GET /product_feeds`** — list all feeds (new endpoint)

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/rest/V1/angeo/product_feeds` | Create a product feed |
| `GET`  | `/rest/V1/angeo/product_feeds` | List all feeds |
| `GET`  | `/rest/V1/angeo/product_feeds/:id` | Get feed metadata |
| `GET`  | `/rest/V1/angeo/product_feeds/:id/products` | Get products (paginated) |
| `POST` | `/rest/V1/angeo/product_feeds/:id/products/upsert` | Upsert products (PATCH workaround) |
| `POST` | `/rest/V1/angeo/product_feeds/:id/products/invalidate` | Bust product cache |
| `GET`  | `/rest/V1/angeo/product_feeds/:id/promotions` | Get promotions |
| `POST` | `/rest/V1/angeo/product_feeds/:id/promotions/upsert` | Upsert promotions (PATCH workaround) |

---

## PATCH workaround — nginx setup

Magento 2's REST framework does not support HTTP `PATCH`. OpenAI's ACP crawler sends `PATCH /product_feeds/:id/products`. Bridge this with an nginx rewrite **before** the request reaches Magento:

```nginx
# Add inside your server {} block
location ~ ^/rest/V1/angeo/product_feeds/[^/]+/products$ {
    if ($request_method = PATCH) {
        rewrite ^(.*)$ $1/upsert last;
    }
}

location ~ ^/rest/V1/angeo/product_feeds/[^/]+/promotions$ {
    if ($request_method = PATCH) {
        rewrite ^(.*)$ $1/upsert last;
    }
}
```

**Apache** (`.htaccess` in Magento root):

```apache
RewriteCond %{REQUEST_METHOD} ^PATCH$
RewriteRule ^rest/V1/angeo/product_feeds/([^/]+)/products$ rest/V1/angeo/product_feeds/$1/products/upsert [L]
RewriteCond %{REQUEST_METHOD} ^PATCH$
RewriteRule ^rest/V1/angeo/product_feeds/([^/]+)/promotions$ rest/V1/angeo/product_feeds/$1/promotions/upsert [L]
```

---

## Installation

```bash
composer require angeo/module-openai-product-feed-api
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Configuration

Navigate to **Stores → Configuration → Angeo → Product Feed API**.

| Setting | Description | Default |
|---------|-------------|---------|
| Enabled | Enable/disable the API | Yes |
| Default Target Country | ISO 3166-1 alpha-2 | US |
| UTM Medium | Appended to all product URLs for attribution | feed |
| Include List Price | Original price when special price active | Yes |
| Include Barcodes | Read EAN/UPC/GTIN attributes | Yes |
| Seller Name | Store/brand name in ACP response | Store name |
| Terms of Service URL | Linked in seller.links | — |
| Privacy Policy URL | Linked in seller.links | — |
| Refund Policy URL | Linked in seller.links | — |
| Shipping Policy URL | Linked in seller.links | — |
| FAQ URL | Linked in seller.links | — |

---

## Quick start

```bash
# 1. Create a feed
curl -X POST https://yourstore.com/rest/V1/angeo/product_feeds \
  -H "Content-Type: application/json" \
  -d '{"targetCountry":"US","storeId":1}'
# → {"id":"feed_a1b2c3d4e5f6","target_country":"US","store_id":1,"updated_at":"...","created_at":"..."}

# 2. Get products (page 1, 100 per page)
curl "https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products?page=1&pageSize=100"

# 3. Upsert products (POST to /upsert; OpenAI PATCH is rewritten by nginx)
curl -X POST https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products/upsert \
  -H "Content-Type: application/json" \
  -d '{"feedId":"feed_a1b2c3","products":[{"id":"SKU_42","variants":[{"id":"SKU_42_BLK","title":"Black"}]}]}'
# → {"id":"feed_a1b2c3","accepted":true,"upserted_count":1,"errors":[]}

# 4. Get promotions (sourced from Magento SalesRules)
curl https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/promotions

# 5. Invalidate product cache (after catalog changes)
curl -X POST https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products/invalidate \
  -H "Authorization: Bearer <admin_token>"
```

---

## ACP Product Schema Coverage

### Product level
| Field | Source |
|-------|--------|
| `id` | `product.entity_id` — stable, never changes |
| `title` | `product.name` |
| `description.plain` | `short_description` stripped, or `description` stripped |
| `description.html` | `description` raw HTML |
| `url` | Product URL + `utm_medium=feed&utm_source=chatgpt` |
| `media` | Gallery images via `ImageUrlBuilder` |

### Variant level
| Field | Source |
|-------|--------|
| `id` | Child `entity_id` for configurable, product `entity_id` otherwise |
| `title` | Child or parent product name |
| `price` | `final_price` in minor units (cents) |
| `list_price` | `price` when `special_price` is active (per ACP best practices) |
| `availability` | `StockRegistry` → `in_stock` / `out_of_stock` |
| `categories` | Category **names** with `merchant` taxonomy |
| `variant_options` | Configurable attribute labels/values (color, size, …) |
| `barcodes` | `ean` / `upc` / `gtin` / `barcode` / `isbn` product attributes |
| `condition` | `["new"]` (override in `ProductMapper`) |
| `seller.name` | Config or store name |
| `seller.links` | ToS, privacy, refund, shipping, FAQ from config |

---

## ACP Promotion Schema Coverage

Sourced from active Magento SalesRules:

| Magento SalesRule | ACP field |
|-------------------|-----------|
| `rule_id` | `id` as `promo_rule_{id}` |
| `name` | `title` |
| `description` + coupon code | `description.plain` |
| `is_active` + dates | `status`: `active` / `scheduled` / `expired` / `disabled` |
| `from_date` / `to_date` | `active_period.start_time` / `end_time` (RFC 3339) |
| `by_percent` | `{type:"percent_off", percent_off: N}` |
| `by_fixed` / `cart_fixed` | `{type:"amount_off", amount_off:{amount, currency}}` |
| `free_shipping` flag | `{type:"free_shipping"}` (combinable with other benefits) |

---

## Testing

```bash
vendor/bin/phpunit -c app/code/Angeo/OpenAiProductFeedApi/phpunit.xml
```

---

## The Angeo AI Suite

| Module | Purpose |
|--------|---------|
| `angeo/module-aeo-audit` | AEO audit — 8 signals scored |
| `angeo/module-llms-txt` | Generates `/llms.txt` |
| `angeo/module-openai-product-feed` | CSV product feed file generator |
| `angeo/module-openai-product-feed-api` | **This module** — ACP REST API |
---

## License

MIT — see [LICENSE](LICENSE)
