<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Product;

use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Angeo\OpenAiProductFeedApi\Model\Mapper\ProductMapper;
use Angeo\OpenAiProductFeedApi\Model\Product\ProductsService;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductsServiceTest extends TestCase
{
    private FeedRepository|MockObject      $feedRepository;
    private ProductMapper|MockObject       $productMapper;
    private CollectionFactory|MockObject   $collectionFactory;
    private CacheInterface|MockObject      $cache;
    private SerializerInterface|MockObject $serializer;
    private LoggerInterface|MockObject     $logger;
    private ProductsService                $service;

    protected function setUp(): void
    {
        $this->feedRepository    = $this->createMock(FeedRepository::class);
        $this->productMapper     = $this->createMock(ProductMapper::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->cache             = $this->createMock(CacheInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));
        $this->serializer->method('unserialize')->willReturnCallback(fn($v) => json_decode($v, true));

        $this->service = new ProductsService(
            $this->feedRepository,
            $this->productMapper,
            $this->collectionFactory,
            $this->cache,
            $this->serializer,
            $this->logger,
        );
    }

    // ── Guard ──────────────────────────────────────────────────────────────

    public function testGetProductsThrowsWhenFeedNotFound(): void
    {
        $this->feedRepository->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->expectException(NoSuchEntityException::class);
        $this->service->getProducts('nonexistent');
    }

    public function testUpsertProductsThrowsWhenFeedNotFound(): void
    {
        $this->feedRepository->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->expectException(NoSuchEntityException::class);
        $this->service->upsertProducts('nonexistent', [['id' => '1', 'variants' => [['id' => 'v1', 'title' => 'T']]]]);
    }

    // ── Cache hit ──────────────────────────────────────────────────────────

    public function testGetProductsServesFromCacheWhenAvailable(): void
    {
        $feed = ['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => ''];
        $this->feedRepository->method('get')->willReturn($feed);

        $cachedPayload = [
            'target_country' => 'US',
            'total_count'    => 1,
            'page'           => 1,
            'page_size'      => 100,
            'products'       => [['id' => '42', 'title' => 'Cached Widget']],
        ];
        $this->cache->method('load')->willReturn(json_encode($cachedPayload));

        // collectionFactory should NOT be called — cache hit
        $this->collectionFactory->expects($this->never())->method('create');

        $result = $this->service->getProducts('feed_1');

        $this->assertSame('Cached Widget', $result['products'][0]['title']);
    }

    // ── Upsert validation ──────────────────────────────────────────────────

    public function testUpsertRejectsEmptyArray(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);

        $result = $this->service->upsertProducts('feed_1', []);

        $this->assertFalse($result['accepted']);
        $this->assertSame(0, $result['upserted_count']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testUpsertRejectsProductWithoutId(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);

        $result = $this->service->upsertProducts('feed_1', [
            ['title' => 'No ID Product', 'variants' => [['id' => 'v1', 'title' => 'V1']]],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('id is required', $result['errors'][0]);
    }

    public function testUpsertRejectsVariantWithoutId(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);

        $result = $this->service->upsertProducts('feed_1', [
            ['id' => 'P1', 'variants' => [['title' => 'Missing variant ID']]],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('variants[0].id', $result['errors'][0]);
    }

    public function testUpsertRejectsVariantWithoutTitle(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);

        $result = $this->service->upsertProducts('feed_1', [
            ['id' => 'P1', 'variants' => [['id' => 'V1']]],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('variants[0].title', $result['errors'][0]);
    }

    public function testUpsertAcceptsValidProduct(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->feedRepository->expects($this->once())->method('touch');

        $result = $this->service->upsertProducts('feed_1', [
            [
                'id'       => 'SKU_123',
                'title'    => 'Great Widget',
                'variants' => [
                    ['id' => 'SKU_123_BLK', 'title' => 'Black'],
                    ['id' => 'SKU_123_WHT', 'title' => 'White'],
                ],
            ],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(1, $result['upserted_count']);
        $this->assertEmpty($result['errors']);
    }

    public function testUpsertMergesWithExistingProducts(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);

        $existing = [['id' => 'OLD_SKU', 'variants' => [['id' => 'OLD_V1', 'title' => 'Old']]]];
        $this->cache->method('load')->willReturn(json_encode($existing));

        $saved = null;
        $this->cache->expects($this->once())->method('save')
            ->willReturnCallback(function ($data) use (&$saved) {
                $saved = json_decode($data, true);
                return true;
            });

        $this->service->upsertProducts('feed_1', [
            ['id' => 'NEW_SKU', 'variants' => [['id' => 'NEW_V1', 'title' => 'New']]],
        ]);

        $this->assertCount(2, $saved);
        $ids = array_column($saved, 'id');
        $this->assertContains('OLD_SKU', $ids);
        $this->assertContains('NEW_SKU', $ids);
    }

    // ── Invalidate ────────────────────────────────────────────────────────

    public function testInvalidateReturnsTrueAndCleansCache(): void
    {
        $this->feedRepository->method('get')->willReturn(['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '']);
        $this->cache->expects($this->once())->method('clean');

        $result = $this->service->invalidateProducts('feed_1');

        $this->assertTrue($result['invalidated']);
        $this->assertSame('feed_1', $result['id']);
    }

    // ── Page size clamping ────────────────────────────────────────────────

    public function testPageSizeClampedToMax(): void
    {
        $feed = ['id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => ''];
        $this->feedRepository->method('get')->willReturn($feed);

        // Cache miss forces catalog build — we'll mock cache to capture page_size
        $this->cache->method('load')->willReturn(false);

        $collection = $this->createMock(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addMediaGalleryData')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);

        // pageSize 9999 should be clamped to 500
        $this->cache->expects($this->atLeastOnce())->method('save')
            ->willReturnCallback(function ($data) {
                $decoded = json_decode($data, true);
                if (isset($decoded['page_size'])) {
                    $this->assertLessThanOrEqual(500, $decoded['page_size']);
                }
                return true;
            });

        $this->service->getProducts('feed_1', 1, 9999);
    }
}
