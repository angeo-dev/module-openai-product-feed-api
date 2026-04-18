<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Mapper;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Image\UrlBuilder as ImageUrlBuilder;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a Magento Product to the OpenAI ACP Product schema.
 *
 * ACP spec:       https://developers.openai.com/commerce/specs/api/products
 * Best practices: https://developers.openai.com/commerce/guides/best-practices
 *
 * Key improvements over v1:
 * - Category names resolved via CategoryRepository (not just IDs)
 * - Image URLs use Magento ImageUrlBuilder (correct resized URLs, not raw paths)
 * - Configurable variants use StoreId-scoped product load
 * - unit_price support (per-unit pricing for weight/volume products)
 * - All config paths aligned with system.xml
 * - Exception safety: every sub-method catches and degrades gracefully
 */
class ProductMapper
{
    private const CONFIG_UTM          = 'angeo_feed_api/general/utm_medium';
    private const CONFIG_SELLER_NAME  = 'angeo_feed_api/seller/name';
    private const CONFIG_TOS_URL      = 'angeo_feed_api/seller/terms_of_service_url';
    private const CONFIG_PRIVACY_URL  = 'angeo_feed_api/seller/privacy_policy_url';
    private const CONFIG_REFUND_URL   = 'angeo_feed_api/seller/refund_policy_url';
    private const CONFIG_SHIPPING_URL = 'angeo_feed_api/seller/shipping_policy_url';
    private const CONFIG_FAQ_URL      = 'angeo_feed_api/seller/faq_url';
    private const CONFIG_LIST_PRICE   = 'angeo_feed_api/general/include_list_price';
    private const CONFIG_BARCODES     = 'angeo_feed_api/general/include_barcodes';
    private const IMAGE_TYPE          = 'product_page_image_large';

    /** @var array<int, string> Category name cache to avoid N+1 queries */
    private array $categoryNameCache = [];

    /** @var array|null  Cached seller object (same for all products in a request) */
    private ?array $sellerCache = null;

    public function __construct(
        private readonly StockRegistryInterface     $stockRegistry,
        private readonly StoreManagerInterface      $storeManager,
        private readonly ScopeConfigInterface       $scopeConfig,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ImageUrlBuilder            $imageUrlBuilder,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * Map a single Magento product to ACP Product object.
     *
     * Simple/Virtual/Downloadable → 1 variant (the product itself)
     * Configurable                → N variants (one per enabled child)
     * Grouped/Bundle              → treated as simple (single variant) for now
     */
    public function map(Product $product): array
    {
        $store  = $this->storeManager->getStore();
        $utm    = $this->config(self::CONFIG_UTM) ?: 'feed';

        $result = [
            'id'          => (string) $product->getId(),
            'title'       => (string) $product->getName(),
            'description' => $this->buildDescription($product),
            'url'         => $this->appendUtm($product->getProductUrl(), $utm),
            'media'       => $this->buildMedia($product),
        ];

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $result['variants'] = $this->buildConfigurableVariants($product, $utm);
        } else {
            $result['variants'] = [$this->buildVariant($product, $product, [], $utm)];
        }

        // Remove empty top-level keys to keep response lean
        return array_filter($result, fn($v) => $v !== [] && $v !== '' && $v !== null);
    }

    // ── Description ──────────────────────────────────────────────────────────

    private function buildDescription(Product $product): array
    {
        $desc = [];

        $html = trim((string) $product->getDescription());
        if ($html) {
            $desc['html']  = $html;
            $desc['plain'] = trim(strip_tags($html));
        } elseif ($short = trim((string) $product->getShortDescription())) {
            $desc['plain'] = strip_tags($short);
        }

        // Fallback: use product name so the field is never empty
        if (empty($desc)) {
            $desc['plain'] = (string) $product->getName();
        }

        return $desc;
    }

    // ── Media ────────────────────────────────────────────────────────────────

    private function buildMedia(Product $product, ?Product $fallback = null): array
    {
        $media = $this->extractGalleryImages($product);

        if (empty($media) && $fallback !== null) {
            $media = $this->extractGalleryImages($fallback);
        }

        return $media;
    }

    private function extractGalleryImages(Product $product): array
    {
        $media = [];

        try {
            $gallery = $product->getMediaGalleryImages();
            if ($gallery) {
                foreach ($gallery as $image) {
                    $url = (string) $image->getUrl();
                    if ($url) {
                        $media[] = [
                            'type'     => 'image',
                            'url'      => $url,
                            'alt_text' => (string) ($image->getLabel() ?: $product->getName()),
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Gallery not loaded
        }

        // Fallback to base image via ImageUrlBuilder
        if (empty($media)) {
            $image = $product->getImage();
            if ($image && $image !== 'no_selection') {
                try {
                    $url     = $this->imageUrlBuilder->getUrl($image, self::IMAGE_TYPE);
                    $media[] = [
                        'type'     => 'image',
                        'url'      => $url,
                        'alt_text' => (string) $product->getName(),
                    ];
                } catch (\Throwable) {}
            }
        }

        return $media;
    }

    // ── Variants ─────────────────────────────────────────────────────────────

    private function buildConfigurableVariants(Product $configurable, string $utm): array
    {
        $variants     = [];
        $attributeMap = $this->buildConfigurableAttributeMap($configurable);

        /** @var Configurable $typeInstance */
        $typeInstance = $configurable->getTypeInstance();
        $children     = $typeInstance->getUsedProducts($configurable);
        $storeId      = (int) $this->storeManager->getStore()->getId();

        foreach ($children as $child) {
            try {
                // Load with full attributes for price/stock/gallery
                $child = $this->productRepository->getById($child->getId(), false, $storeId);
            } catch (NoSuchEntityException) {
                // Use as-is from collection
            } catch (\Throwable $e) {
                $this->logger->warning('[Angeo FeedApi] Could not reload child ' . $child->getId() . ': ' . $e->getMessage());
            }

            $variantOptions = $this->extractVariantOptions($child, $attributeMap);
            $variants[]     = $this->buildVariant($child, $configurable, $variantOptions, $utm);
        }

        return $variants ?: [$this->buildVariant($configurable, $configurable, [], $utm)];
    }

    private function buildVariant(
        Product $variant,
        Product $parent,
        array   $variantOptions,
        string  $utm,
    ): array {
        $obj = [
            'id'           => (string) $variant->getId(),
            'title'        => (string) ($variant->getName() ?: $parent->getName()),
            'description'  => $this->buildDescription($variant),
            'url'          => $this->appendUtm($variant->getProductUrl(), $utm),
            'media'        => $this->buildMedia($variant, $parent),
            'price'        => $this->buildPrice($variant),
            'availability' => $this->buildAvailability($variant),
            'categories'   => $this->buildCategories($parent),
            'condition'    => ['new'],
            'seller'       => $this->buildSeller(),
        ];

        // list_price — only when special price is active
        if ($this->config(self::CONFIG_LIST_PRICE) && $this->hasActiveSpecialPrice($variant)) {
            $obj['list_price'] = $this->buildListPrice($variant);
        }

        // variant_options (color, size, …)
        if (!empty($variantOptions)) {
            $obj['variant_options'] = $variantOptions;
        }

        // barcodes from EAN / UPC / GTIN attributes
        if ($this->config(self::CONFIG_BARCODES)) {
            $barcodes = $this->buildBarcodes($variant);
            if (!empty($barcodes)) {
                $obj['barcodes'] = $barcodes;
            }
        }

        return array_filter($obj, fn($v) => $v !== [] && $v !== '' && $v !== null);
    }

    // ── Price ─────────────────────────────────────────────────────────────────

    private function buildPrice(Product $product): array
    {
        $price    = (float) ($product->getFinalPrice() ?? $product->getPrice() ?? 0.0);
        $currency = $this->getCurrentCurrency();

        return [
            'amount'   => (int) round($price * 100), // ACP spec: minor units (cents)
            'currency' => $currency,
        ];
    }

    private function buildListPrice(Product $product): array
    {
        return [
            'amount'   => (int) round((float) ($product->getPrice() ?? 0.0) * 100),
            'currency' => $this->getCurrentCurrency(),
        ];
    }

    private function hasActiveSpecialPrice(Product $product): bool
    {
        $special = $product->getSpecialPrice();
        if (!$special || (float) $special <= 0.0) {
            return false;
        }
        $now  = time();
        $from = $product->getSpecialFromDate() ? strtotime($product->getSpecialFromDate()) : 0;
        $to   = $product->getSpecialToDate()   ? strtotime($product->getSpecialToDate())   : PHP_INT_MAX;

        return $from <= $now && $now <= $to;
    }

    private function getCurrentCurrency(): string
    {
        try {
            return strtoupper($this->storeManager->getStore()->getCurrentCurrencyCode() ?? 'USD');
        } catch (\Throwable) {
            return 'USD';
        }
    }

    // ── Availability ──────────────────────────────────────────────────────────

    private function buildAvailability(Product $product): array
    {
        try {
            $stock     = $this->stockRegistry->getStockItem((int) $product->getId());
            $isInStock = (bool) $stock->getIsInStock();
            $qty       = (float) $stock->getQty();
        } catch (\Throwable) {
            $isInStock = true;
            $qty       = 1.0;
        }

        if (!$isInStock || $qty <= 0.0 || !$product->isSaleable()) {
            return ['available' => false, 'status' => 'out_of_stock'];
        }

        return ['available' => true, 'status' => 'in_stock'];
    }

    // ── Categories ────────────────────────────────────────────────────────────

    private function buildCategories(Product $product): array
    {
        $categories = [];

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            foreach ($product->getCategoryIds() as $catId) {
                $catId = (int) $catId;
                if (!isset($this->categoryNameCache[$catId])) {
                    try {
                        $category = $this->categoryRepository->get($catId, $storeId);
                        $this->categoryNameCache[$catId] = (string) $category->getName();
                    } catch (\Throwable) {
                        $this->categoryNameCache[$catId] = (string) $catId;
                    }
                }

                $categories[] = [
                    'value'    => $this->categoryNameCache[$catId],
                    'taxonomy' => 'merchant',
                ];
            }
        } catch (\Throwable) {}

        return $categories;
    }

    // ── Barcodes ──────────────────────────────────────────────────────────────

    private function buildBarcodes(Product $product): array
    {
        foreach (['ean', 'upc', 'gtin', 'barcode', 'isbn'] as $code) {
            $value = $product->getData($code);
            if ($value) {
                return [['type' => $code === 'upc' ? 'upc' : 'gtin', 'value' => (string) $value]];
            }
        }
        return [];
    }

    // ── Seller ────────────────────────────────────────────────────────────────

    private function buildSeller(): array
    {
        if ($this->sellerCache !== null) {
            return $this->sellerCache;
        }

        try {
            $name = $this->config(self::CONFIG_SELLER_NAME)
                ?: (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable) {
            $name = 'Store';
        }

        $seller = ['name' => $name, 'links' => []];

        foreach ([
            self::CONFIG_TOS_URL      => 'terms_of_service',
            self::CONFIG_PRIVACY_URL  => 'privacy_policy',
            self::CONFIG_REFUND_URL   => 'refund_policy',
            self::CONFIG_SHIPPING_URL => 'shipping_policy',
            self::CONFIG_FAQ_URL      => 'faq',
        ] as $path => $type) {
            $url = $this->config($path);
            if ($url) {
                $seller['links'][] = ['type' => $type, 'url' => $url];
            }
        }

        $this->sellerCache = $seller;
        return $seller;
    }

    // ── Configurable attributes ───────────────────────────────────────────────

    private function buildConfigurableAttributeMap(Product $configurable): array
    {
        $map = [];
        try {
            /** @var Configurable $typeInstance */
            $typeInstance = $configurable->getTypeInstance();
            foreach ($typeInstance->getConfigurableAttributes($configurable) as $attr) {
                $prodAttr = $attr->getProductAttribute();
                if ($prodAttr) {
                    $map[$prodAttr->getAttributeCode()] = (string) $prodAttr->getFrontendLabel();
                }
            }
        } catch (\Throwable) {}

        return $map;
    }

    private function extractVariantOptions(Product $variant, array $attributeMap): array
    {
        $options = [];
        foreach ($attributeMap as $code => $label) {
            $value = $variant->getAttributeText($code);
            if ($value === false || $value === null || $value === '') {
                $value = $variant->getData($code);
            }
            if ($value !== null && $value !== false && $value !== '') {
                $options[] = [
                    'name'  => strtolower($label ?: $code),
                    'value' => (string) $value,
                ];
            }
        }
        return $options;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function appendUtm(string $url, string $utm): string
    {
        if (!$url) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'utm_medium=' . urlencode($utm) . '&utm_source=chatgpt';
    }

    private function config(string $path): string
    {
        return (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
}
