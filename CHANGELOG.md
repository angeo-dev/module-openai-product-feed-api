# Changelog

All notable changes to this module are documented in this file.

## [2.0.0] - 2026-07-05

### Security

- **All REST routes now require bearer-token authentication** via the
  `Angeo_OpenAiProductFeedApi::manage_feeds` ACL resource. Previous releases
  exposed every endpoint anonymously, which allowed anyone to dump the full
  catalog with prices and stock, create unlimited feed records in the
  database, poison the feed payload served to OpenAI through unauthenticated
  upserts, and flush the feed caches. This also aligns the module with the
  ACP API specification, which requires `Authorization: Bearer <api_key>` on
  every request. See the README for the Magento Integration setup that
  produces the token to hand to OpenAI.
- `target_country` input is validated as ISO 3166-1 alpha-2 (returns
  `400 Bad Request` on invalid input instead of failing at the database
  layer), and `storeId` is validated against existing stores (returns
  `404 Not Found` for unknown stores).

### Breaking changes

- Authentication is now required on every endpoint (see Security). Update any
  callers to send a bearer token.
- Requires `angeo/module-openai-product-feed` `^2.0` and PHP `>= 8.3`.
- Cache key layout changed (`v3` prefix, split snapshot / upsert overlay /
  page slices). Caches are rebuilt automatically on first request.

### Fixed

- **Free-shipping promotions were never detected.** Free shipping lives in the
  `simple_free_shipping` rule field, not `simple_action`; the mapper now reads
  `getSimpleFreeShipping()` against the `RuleInterface::FREE_SHIPPING_*`
  constants (legacy integer values tolerated). The previous fallback on
  `apply_to_shipping` was also removed — that flag means "apply the discount
  to shipping cost", not "free shipping".
- **Coupon codes were never appended to promotion descriptions.** The rule
  repository data model returns `coupon_type` as the string constant
  `SPECIFIC_COUPON` (the old `== 2` check could never match) and does not
  expose `getPrimaryCoupon()`. Codes are now resolved through
  `CouponRepositoryInterface`.
- **Stale reads after upsert.** PATCH-upserted products are now stored in a
  dedicated overlay cache and served page slices carry their own cache tag,
  so an upsert invalidates the pages immediately without discarding the
  catalog snapshot.
- **Catalog rebuilds no longer discard PATCH-upserted products.** The upsert
  overlay is merged over the catalog snapshot at read time and survives
  snapshot rebuilds; `/invalidate` performs the full reset (snapshot +
  overlay + pages).
- Product builds for store-scoped feeds now run under frontend store
  emulation, so product URLs, currency and locale match the feed's store view
  instead of the REST default scope.
- `updated_at` touch now writes UTC, consistent with the database defaults.
- Unit tests are now runnable: the previous suite mocked
  `getCurrentCurrencyCode()` on `StoreInterface`, where the method does not
  exist, and stubbed `getSimpleAction()` twice in the same mock. The
  PromotionMapper suite (14 tests) passes against the rewritten mapper.

### Changed

- `/invalidate` now performs a scoped reset for the requested feed (snapshot
  and overlay keys) plus the shared page-slice tag, instead of flushing every
  feed's payload cache.

## [1.0.0]

- Initial release: ACP feed/products/promotions REST surface, DB-persisted
  feeds, paginated product export, SalesRule → Promotion mapping,
  PATCH-as-POST bridge.
