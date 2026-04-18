<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Product;

use Angeo\OpenAiProductFeedApi\Api\ProductFeedProductsInterface;
use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Angeo\OpenAiProductFeedApi\Model\Mapper\ProductMapper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Implements GET, POST (PATCH upsert), and cache invalidation for products.
 *
 * Key fixes over v1:
 * - Proper pagination (page/pageSize params) — v1 fetched max 100 and stopped
 * - GET builds from catalog via paginated batches and caches ALL pages atomically
 * - Cache key includes page number so large catalogs don't bust memory
 * - addAttributeToSelect limited to required fields (not '*')
 * - Validation returns structured errors array instead of silent false
 */
class ProductsService implements ProductFeedProductsInterface
{
    private const CACHE_TAG         = 'angeo_acp_products';
    private const CACHE_KEY_PREFIX  = 'angeo_acp_products_v2_';
    private const CACHE_KEY_META    = 'angeo_acp_products_meta_';
    private const CATALOG_PAGE_SIZE = 100;
    private const MAX_PAGE_SIZE     = 500;

    public function __construct(
        private readonly FeedRepository     $feedRepository,
        private readonly ProductMapper      $productMapper,
        private readonly CollectionFactory  $collectionFactory,
        private readonly CacheInterface     $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface    $logger,
    ) {}

    // ── GET ───────────────────────────────────────────────────────────────────

    public function getProducts(string $feedId, int $page = 1, int $pageSize = 100): array
    {
        $feed     = $this->feedRepository->get($feedId); // throws NoSuchEntityException
        $page     = max(1, $page);
        $pageSize = min(max(1, $pageSize), self::MAX_PAGE_SIZE);

        // Check cache first
        $cached = $this->loadFromCache($feedId, $page, $pageSize);
        if ($cached !== null) {
            return $cached;
        }

        // Build from catalog with pagination
        return $this->buildAndCache($feed, $feedId, $page, $pageSize);
    }

    // ── POST (PATCH upsert) ───────────────────────────────────────────────────

    public function upsertProducts(string $feedId, array $products, ?string $targetCountry = null): array
    {
        $this->feedRepository->get($feedId); // guard

        if (empty($products)) {
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => ['products array is empty']];
        }

        $errors       = [];
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
            // Merge into existing cached payload on page 1 (upserts go to first page)
            $existing = $this->loadRawProducts($feedId) ?? [];
            $indexed  = array_column($existing, null, 'id');

            foreach ($validProducts as $product) {
                $indexed[$product['id']] = $product;
            }

            $allProducts = array_values($indexed);
            $this->saveRawProducts($feedId, $allProducts);
            $this->feedRepository->touch($feedId);

            return [
                'id'            => $feedId,
                'accepted'      => true,
                'upserted_count' => count($validProducts),
                'errors'        => $errors,
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

        $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG]);

        return ['id' => $feedId, 'invalidated' => true];
    }

    // ── Catalog builder ───────────────────────────────────────────────────────

    private function buildAndCache(array $feed, string $feedId, int $page, int $pageSize): array
    {
        $storeId     = (int) ($feed['store_id'] ?? 0) ?: null;
        $allProducts = [];
        $catalogPage = 1;

        // Build all products via batch iteration (fixes v1 100-product limit)
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

        // Save full dataset to cache
        $this->saveRawProducts($feedId, $allProducts);

        // Return requested page slice
        return $this->sliceAndFormat($feedId, $allProducts, $feed, $page, $pageSize);
    }

    private function sliceAndFormat(
        string $feedId,
        array  $allProducts,
        array  $feed,
        int    $page,
        int    $pageSize,
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

        // Cache page slice too (short TTL for paginated views)
        $this->cache->save(
            $this->serializer->serialize($result),
            $this->pageCacheKey($feedId, $page, $pageSize),
            [self::CACHE_TAG],
            1800 // 30 min
        );

        return $result;
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    private function loadFromCache(string $feedId, int $page, int $pageSize): ?array
    {
        $raw = $this->cache->load($this->pageCacheKey($feedId, $page, $pageSize));
        return $raw ? $this->serializer->unserialize($raw) : null;
    }

    private function loadRawProducts(string $feedId): ?array
    {
        $raw = $this->cache->load(self::CACHE_KEY_PREFIX . $feedId);
        return $raw ? $this->serializer->unserialize($raw) : null;
    }

    private function saveRawProducts(string $feedId, array $products): void
    {
        $this->cache->save(
            $this->serializer->serialize($products),
            self::CACHE_KEY_PREFIX . $feedId,
            [self::CACHE_TAG],
            0 // permanent until invalidated
        );
    }

    private function pageCacheKey(string $feedId, int $page, int $pageSize): string
    {
        return self::CACHE_KEY_PREFIX . $feedId . '_p' . $page . '_s' . $pageSize;
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
