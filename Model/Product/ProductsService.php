<?php

/**
 * @copyright Copyright (c) 2025 Ievgenii Gryshkun
 * @author    Ievgenii Gryshkun <info@angeo.dev>
 * @license   MIT
 */

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Product;

use Angeo\OpenAiProductFeedApi\Api\ProductFeedProductsInterface;
use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Angeo\OpenAiProductFeedApi\Model\Mapper\ProductMapper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Implements GET, POST (PATCH upsert), and cache invalidation for products.
 *
 * Cache layout (per feed):
 * - snapshot key: full catalog build, permanent until invalidated
 * - upserts key:  PATCH-upserted products (overlay, wins over snapshot rows)
 * - page keys:    formatted page slices, short TTL, tagged separately so an
 *                 upsert can invalidate served pages without dropping the
 *                 catalog snapshot or the upsert overlay
 *
 * A catalog rebuild therefore never discards PATCH-upserted products, and an
 * upsert is immediately visible on the next GET.
 */
class ProductsService implements ProductFeedProductsInterface
{
    private const CACHE_TAG          = 'angeo_acp_products';
    private const CACHE_TAG_PAGES    = 'angeo_acp_products_pages';
    private const CACHE_KEY_SNAPSHOT = 'angeo_acp_products_v3_snapshot_';
    private const CACHE_KEY_UPSERTS  = 'angeo_acp_products_v3_upserts_';
    private const CACHE_KEY_PAGE     = 'angeo_acp_products_v3_page_';
    private const CATALOG_PAGE_SIZE  = 100;
    private const MAX_PAGE_SIZE      = 500;
    private const PAGE_TTL           = 1800; // 30 min

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly ProductMapper $productMapper,
        private readonly CollectionFactory $collectionFactory,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly Emulation $emulation,
    ) {}

    // ── GET ───────────────────────────────────────────────────────────────────

    public function getProducts(string $feedId, int $page = 1, int $pageSize = 100): array
    {
        $feed     = $this->feedRepository->get($feedId); // throws NoSuchEntityException
        $page     = max(1, $page);
        $pageSize = min(max(1, $pageSize), self::MAX_PAGE_SIZE);

        $cached = $this->loadPage($feedId, $page, $pageSize);
        if ($cached !== null) {
            return $cached;
        }

        $snapshot = $this->loadList(self::CACHE_KEY_SNAPSHOT . $feedId);

        if ($snapshot === null) {
            $snapshot = $this->buildSnapshot($feed, $feedId);
        }

        $merged = $this->mergeWithUpserts($feedId, $snapshot);

        return $this->sliceAndFormat($feedId, $merged, $feed, $page, $pageSize);
    }

    // ── POST (PATCH upsert) ───────────────────────────────────────────────────

    public function upsertProducts(string $feedId, array $products, ?string $targetCountry = null): array
    {
        $this->feedRepository->get($feedId); // guard

        if (empty($products)) {
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => ['products array is empty']];
        }

        $errors        = [];
        $validProducts = [];

        foreach ($products as $i => $product) {
            $productErrors = $this->validateProduct($product);
            if (!empty($productErrors)) {
                foreach ($productErrors as $err) {
                    $errors[] = "products[{$i}]: {$err}";
                }
            } else {
                $validProducts[] = $product;
            }
        }

        if (empty($validProducts)) {
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => $errors];
        }

        try {
            $overlay = $this->loadList(self::CACHE_KEY_UPSERTS . $feedId) ?? [];
            $indexed = array_column($overlay, null, 'id');

            foreach ($validProducts as $product) {
                $indexed[$product['id']] = $product;
            }

            $this->saveList(self::CACHE_KEY_UPSERTS . $feedId, array_values($indexed));

            // Served page slices are now stale; the snapshot and overlay are not.
            $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG_PAGES]);

            $this->feedRepository->touch($feedId);

            return [
                'id'             => $feedId,
                'accepted'       => true,
                'upserted_count' => count($validProducts),
                'errors'         => $errors,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo FeedApi] upsertProducts error: ' . $e->getMessage());
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    // ── Invalidate ────────────────────────────────────────────────────────────

    public function invalidateProducts(string $feedId): array
    {
        $this->feedRepository->get($feedId); // guard

        // Full reset for this feed: catalog snapshot + PATCH overlay + all pages.
        $this->cache->remove(self::CACHE_KEY_SNAPSHOT . $feedId);
        $this->cache->remove(self::CACHE_KEY_UPSERTS . $feedId);
        $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG_PAGES]);

        return ['id' => $feedId, 'invalidated' => true];
    }

    // ── Catalog builder ───────────────────────────────────────────────────────

    private function buildSnapshot(array $feed, string $feedId): array
    {
        $storeId  = (int) ($feed['store_id'] ?? 0) ?: null;
        $emulated = false;

        if ($storeId !== null) {
            // Frontend emulation: correct product URLs, currency and locale
            // for the feed's store view.
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            $emulated = true;
        }

        $allProducts = [];

        try {
            $catalogPage = 1;

            do {
                $collection = $this->collectionFactory->create();
                $collection
                    ->addAttributeToSelect([
                        'name', 'description', 'short_description', 'url_key',
                        'image', 'price', 'special_price', 'special_from_date', 'special_to_date',
                        'visibility', 'status', 'type_id',
                        'ean', 'upc', 'gtin', 'barcode', 'brand', 'manufacturer',
                    ])
                    ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                    ->addAttributeToFilter('visibility', ['in' => [
                        Visibility::VISIBILITY_IN_CATALOG,
                        Visibility::VISIBILITY_IN_SEARCH,
                        Visibility::VISIBILITY_BOTH,
                    ]])
                    ->addMediaGalleryData()
                    ->addUrlRewrite()
                    ->setPageSize(self::CATALOG_PAGE_SIZE)
                    ->setCurPage($catalogPage);

                if ($storeId) {
                    $collection->addStoreFilter($storeId);
                }

                foreach ($collection as $product) {
                    try {
                        $allProducts[] = $this->productMapper->map($product);
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            '[Angeo FeedApi] Skipping product ' . $product->getId() . ': ' . $e->getMessage()
                        );
                    }
                }

                $totalItems = $collection->getSize();
                $catalogPage++;
            } while (self::CATALOG_PAGE_SIZE * ($catalogPage - 1) < $totalItems);
        } finally {
            if ($emulated) {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        $this->saveList(self::CACHE_KEY_SNAPSHOT . $feedId, $allProducts);

        return $allProducts;
    }

    private function mergeWithUpserts(string $feedId, array $snapshot): array
    {
        $overlay = $this->loadList(self::CACHE_KEY_UPSERTS . $feedId);

        if (empty($overlay)) {
            return $snapshot;
        }

        $indexed = array_column($snapshot, null, 'id');

        foreach ($overlay as $product) {
            $indexed[$product['id']] = $product;
        }

        return array_values($indexed);
    }

    private function sliceAndFormat(
        string $feedId,
        array $allProducts,
        array $feed,
        int $page,
        int $pageSize,
    ): array {
        $totalCount = count($allProducts);
        $offset     = ($page - 1) * $pageSize;
        $pageItems  = array_slice($allProducts, $offset, $pageSize);

        $result = [
            'target_country' => $feed['target_country'] ?? 'US',
            'total_count'    => $totalCount,
            'page'           => $page,
            'page_size'      => $pageSize,
            'products'       => $pageItems,
        ];

        $this->cache->save(
            $this->serializer->serialize($result),
            $this->pageCacheKey($feedId, $page, $pageSize),
            [self::CACHE_TAG, self::CACHE_TAG_PAGES],
            self::PAGE_TTL
        );

        return $result;
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    private function loadPage(string $feedId, int $page, int $pageSize): ?array
    {
        $raw = $this->cache->load($this->pageCacheKey($feedId, $page, $pageSize));
        return $raw ? $this->serializer->unserialize($raw) : null;
    }

    private function loadList(string $key): ?array
    {
        $raw = $this->cache->load($key);
        return $raw ? $this->serializer->unserialize($raw) : null;
    }

    private function saveList(string $key, array $products): void
    {
        $this->cache->save(
            $this->serializer->serialize($products),
            $key,
            [self::CACHE_TAG],
            0 // permanent until invalidated
        );
    }

    private function pageCacheKey(string $feedId, int $page, int $pageSize): string
    {
        return self::CACHE_KEY_PAGE . $feedId . '_p' . $page . '_s' . $pageSize;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateProduct(array $product): array
    {
        $errors = [];

        if (empty($product['id'])) {
            $errors[] = 'id is required';
        }

        if (empty($product['variants']) || !is_array($product['variants'])) {
            $errors[] = 'variants array is required';
        } else {
            foreach ($product['variants'] as $j => $variant) {
                if (empty($variant['id'])) {
                    $errors[] = "variants[{$j}].id is required";
                }
                if (empty($variant['title'])) {
                    $errors[] = "variants[{$j}].title is required";
                }
            }
        }

        return $errors;
    }
}
