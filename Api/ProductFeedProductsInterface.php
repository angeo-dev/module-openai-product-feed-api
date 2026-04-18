<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Api;

/**
 * OpenAI ACP — Products resource.
 *
 * Spec:         https://developers.openai.com/commerce/specs/api/products
 * Best practices: https://developers.openai.com/commerce/guides/best-practices
 *
 * ─── PATCH workaround ────────────────────────────────────────────────────────
 * Magento 2 REST framework does not support HTTP PATCH natively.
 * The upsertProducts() method is therefore exposed via POST at:
 *   POST /V1/angeo/product_feeds/:feedId/products/upsert
 *
 * OpenAI's ACP crawler will call PATCH /product_feeds/:id/products.
 * To bridge this, add a reverse-proxy rewrite rule (nginx/Varnish) that
 * rewrites PATCH → POST before the request reaches Magento:
 *
 *   # nginx example (add to your server block):
 *   location ~ ^/rest/V1/angeo/product_feeds/[^/]+/products$ {
 *       if ($request_method = PATCH) {
 *           rewrite ^(.*)$ $1/upsert last;
 *       }
 *   }
 *
 * This keeps the Magento module clean and ACP-compliant.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Product schema (per ACP spec + best practices):
 *   id           string  required  Stable product identifier (entity_id)
 *   title        string  optional  Product name
 *   description  object  optional  {plain?, html?, markdown?} — at least one
 *   url          string  optional  Canonical URL + utm_medium=feed
 *   media        array   optional  [{type, url, alt_text, width?, height?}]
 *   variants     array   required  See Variant schema
 *
 * Variant schema:
 *   id               string  required  Stable variant ID (child entity_id)
 *   title            string  required  Variant display name
 *   description      object  optional
 *   url              string  optional
 *   barcodes         array   optional  [{type:"gtin"|"upc", value}]
 *   price            object  optional  {amount (minor units), currency}
 *   list_price       object  optional  Original price before discount
 *   availability     object  optional  {available:bool, status:string}
 *   categories       array   optional  [{value, taxonomy}]
 *   condition        array   optional  ["new"|"secondhand"]
 *   variant_options  array   optional  [{name, value}]
 *   media            array   optional  Variant-specific images (first = primary)
 *   seller           object  optional  {name, links:[{type, title?, url}]}
 */
interface ProductFeedProductsInterface
{
    /**
     * GET /V1/angeo/product_feeds/:feedId/products
     *
     * Returns all products for the feed, paginated.
     * First call builds from Magento catalog and caches; subsequent calls serve from cache.
     *
     * @param string   $feedId
     * @param int      $page      1-based page number (default: 1)
     * @param int      $pageSize  Items per page (default: 100, max: 500)
     * @return mixed[]  {target_country, total_count, page, page_size, products: Product[]}
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getProducts(string $feedId, int $page = 1, int $pageSize = 100): array;

    /**
     * POST /V1/angeo/product_feeds/:feedId/products/upsert
     * (Handles PATCH via nginx rewrite — see interface docblock)
     *
     * Upsert products into feed. Matched by `id`; unmentioned products unchanged.
     *
     * @param string      $feedId
     * @param mixed[]     $products      Array of Product objects per ACP spec
     * @param string|null $targetCountry Optional override
     * @return mixed[]  {id: feedId, accepted: bool, upserted_count: int, errors: string[]}
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function upsertProducts(string $feedId, array $products, ?string $targetCountry = null): array;

    /**
     * POST /V1/angeo/product_feeds/:feedId/products/invalidate
     *
     * Invalidate the product cache for this feed, forcing rebuild on next GET.
     * Call after catalog changes (event observer or CLI).
     *
     * @param string $feedId
     * @return mixed[]  {id: feedId, invalidated: bool}
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function invalidateProducts(string $feedId): array;
}
