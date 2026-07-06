# OpenAI Agentic Commerce Protocol (ACP) API for Magento 2

[![Latest Version](https://img.shields.io/packagist/v/angeo/module-openai-product-feed-api)](https://packagist.org/packages/angeo/module-openai-product-feed-api)
[![Total Downloads](https://img.shields.io/packagist/dt/angeo/module-openai-product-feed-api)](https://packagist.org/packages/angeo/module-openai-product-feed-api)
[![License](https://img.shields.io/packagist/l/angeo/module-openai-product-feed-api)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/dependency-v/angeo/module-openai-product-feed-api/php)](composer.json)
![Magento](https://img.shields.io/badge/Magento-2.4.x-orange)
![ACP Feed API](https://img.shields.io/badge/ACP%20Feed%20API-Feeds%20%7C%20Products%20%7C%20Promotions-blue)

Magento 2 REST implementation of the [OpenAI Agentic Commerce Protocol (ACP) feed API](https://developers.openai.com/commerce/specs/api/overview): **Feeds**, **Products** and **Promotions** resources, so ChatGPT can retrieve and update your catalog data through API-based delivery instead of (or alongside) daily file uploads.

All endpoints are **bearer-token authenticated** per the ACP specification. Feeds are **persisted in the database** (they survive cache flushes and restarts), product export is **fully paginated** with configurable-product variants, and promotions are **sourced from Magento Cart Price Rules** including coupon codes and free shipping.

Works together with [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) (CSV file-upload feed): per OpenAI's guidance, provide the full feed daily via file upload and send incremental updates through this API.

## Requirements

- PHP >= 8.1
- Magento 2.4.x (Open Source or Adobe Commerce)
- `angeo/module-openai-product-feed` ^2.0

## Installation

```bash
composer require angeo/module-openai-product-feed-api
bin/magento setup:upgrade
bin/magento cache:flush
```

## Authentication (required)

Every endpoint requires a bearer token carrying the `Angeo_OpenAiProductFeedApi::manage_feeds` ACL resource, matching the ACP requirement that requests include `Authorization: Bearer <api_key>`.

1. In the Magento admin, go to **System → Extensions → Integrations → Add New Integration**.
2. On the **API** tab, select the resource **Angeo — Manage Product Feed API** (under Marketing).
3. Save, then **Activate** the integration and copy the **Access Token**.
4. Provide that token to OpenAI as the feed API key; use it in the `Authorization: Bearer` header for your own calls as well.

Requests without a valid token receive `401 Unauthorized`.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/rest/V1/angeo/product_feeds` | Create a product feed |
| `GET`  | `/rest/V1/angeo/product_feeds` | List all feeds (extension beyond the ACP spec) |
| `GET`  | `/rest/V1/angeo/product_feeds/:id` | Get feed metadata |
| `GET`  | `/rest/V1/angeo/product_feeds/:id/products` | Get products (paginated) |
| `POST` | `/rest/V1/angeo/product_feeds/:id/products/upsert` | Upsert products (PATCH bridge) |
| `POST` | `/rest/V1/angeo/product_feeds/:id/products/invalidate` | Reset product cache for the feed |
| `GET`  | `/rest/V1/angeo/product_feeds/:id/promotions` | Get promotions |
| `POST` | `/rest/V1/angeo/product_feeds/:id/promotions/upsert` | Upsert promotions (PATCH bridge) |

Invalid `target_country` returns `400 Bad Request`; unknown feed or store IDs return `404 Not Found`, per the ACP error model.

## PATCH bridge — web server setup

Magento 2's REST framework does not support HTTP `PATCH`. The ACP spec defines upserts as `PATCH /product_feeds/:id/products`. Bridge this with a rewrite **before** the request reaches Magento:

```nginx
# nginx — add inside your server {} block
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

```apache
# Apache — .htaccess in the Magento root
RewriteCond %{REQUEST_METHOD} ^PATCH$
RewriteRule ^rest/V1/angeo/product_feeds/([^/]+)/products$ rest/V1/angeo/product_feeds/$1/products/upsert [L]
RewriteCond %{REQUEST_METHOD} ^PATCH$
RewriteRule ^rest/V1/angeo/product_feeds/([^/]+)/promotions$ rest/V1/angeo/product_feeds/$1/promotions/upsert [L]
```

## Quick start

```bash
TOKEN="<integration access token>"

# 1. Create a feed
curl -X POST https://yourstore.com/rest/V1/angeo/product_feeds \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"targetCountry":"US","storeId":1}'
# → {"id":"feed_a1b2c3d4e5f6","target_country":"US","store_id":1,"updated_at":"...","created_at":"..."}

# 2. Get products (page 1, 100 per page)
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products?page=1&pageSize=100"

# 3. Upsert products (POST to /upsert; OpenAI's PATCH is rewritten by nginx)
curl -X POST https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products/upsert \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"feedId":"feed_a1b2c3","products":[{"id":"42","variants":[{"id":"43","title":"Black / M"}]}]}'
# → {"id":"feed_a1b2c3","accepted":true,"upserted_count":1,"errors":[]}

# 4. Get promotions (sourced from Magento Cart Price Rules)
curl -H "Authorization: Bearer $TOKEN" \
  https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/promotions

# 5. Reset the product cache after catalog changes
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://yourstore.com/rest/V1/angeo/product_feeds/feed_a1b2c3/products/invalidate
```

## How caching works

- **Catalog snapshot** — built from the Magento catalog on the first `GET`, persisted until invalidated. Store-scoped feeds are built under frontend store emulation, so URLs, currency and locale match the store view.
- **Upsert overlay** — products received via PATCH/upsert are stored separately and merged over the snapshot at read time (overlay wins). Catalog rebuilds never discard upserted products.
- **Page slices** — formatted responses are cached for 30 minutes with their own tag; any upsert invalidates them immediately.
- **`/invalidate`** — full reset for the feed: snapshot, overlay and pages.

## Configuration

**Stores → Configuration → Angeo → Product Feed API**

| Setting | Description | Default |
|---------|-------------|---------|
| Enabled | Enable/disable the API | Yes |
| Default Target Country | ISO 3166-1 alpha-2, validated on feed creation | US |
| UTM Medium | Appended to all product URLs for attribution | feed |
| Include List Price | Original price when a special price is active | Yes |
| Include Barcodes | Read EAN/UPC/GTIN attributes | Yes |
| Seller Name | Store/brand name in ACP responses | Store name |
| ToS / Privacy / Refund / Shipping / FAQ URLs | Emitted as `seller.links` | — |

## ACP Product schema coverage

| Field | Source |
|-------|--------|
| `id` | `product.entity_id` — stable, never changes |
| `title` | Product name |
| `description.plain` / `description.html` | `description` / `short_description` |
| `url` | Product URL + `utm_medium` + `utm_source=chatgpt` |
| `media` | Gallery images via Magento's `ImageUrlBuilder` |
| `variants[].id` / `title` | Configurable children, or the product itself |
| `variants[].price` / `list_price` | Final price / original price, minor units (cents) |
| `variants[].availability` | Stock status → `in_stock` / `out_of_stock` |
| `variants[].categories` | Category names, `merchant` taxonomy |
| `variants[].variant_options` | Configurable attribute labels/values (color, size, …) |
| `variants[].barcodes` | `ean` / `upc` / `gtin` / `barcode` / `isbn` attributes |
| `variants[].condition` | `["new"]` |
| `variants[].seller` | Name + policy links from configuration |

## ACP Promotion schema coverage

Sourced from active Magento Cart Price Rules (SalesRules):

| Magento SalesRule | ACP field |
|-------------------|-----------|
| `rule_id` | `id` as `promo_rule_{id}` |
| `name` | `title` |
| `description` + coupon code (via `CouponRepositoryInterface`) | `description.plain` |
| `is_active` + dates | `status`: `active` / `scheduled` / `expired` / `disabled` |
| `from_date` / `to_date` | `active_period` (RFC 3339) |
| `by_percent` | `{type:"percent_off"}` |
| `by_fixed` / `cart_fixed` | `{type:"amount_off"}` (minor units) |
| `simple_free_shipping` | `{type:"free_shipping"}` — combinable with a discount benefit |

## Testing

```bash
vendor/bin/phpunit -c app/code/Angeo/OpenAiProductFeedApi/phpunit.xml
```

The PromotionMapper suite (14 tests) covers benefit mapping, free-shipping detection, coupon-code resolution and status transitions.

## The Angeo agentic commerce suite

| Module | Purpose |
|--------|---------|
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | OpenAI CSV product feed (file upload, all product types) |
| `angeo/module-openai-product-feed-api` | **This module** — ACP REST API |
| [`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp) | Universal Commerce Protocol discovery + MCP binding |
| [`angeo/module-mcp-server`](https://packagist.org/packages/angeo/module-mcp-server) | Read-only commerce tools over MCP |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | `llms.txt` AI content map |
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | AEO readiness audit |

---

**Need help with agentic commerce for Magento?** Professional support, AEO audits and implementation at [angeo.dev](https://angeo.dev/). Check how your store looks to AI agents with the free scanner at [api.angeo.dev](https://api.angeo.dev/).

*Questions? Contact me at info@angeo.dev*

## License

MIT — see [LICENSE](LICENSE)
