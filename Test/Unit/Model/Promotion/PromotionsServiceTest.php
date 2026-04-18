<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Promotion;

use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Angeo\OpenAiProductFeedApi\Model\Promotion\PromotionMapper;
use Angeo\OpenAiProductFeedApi\Model\Promotion\PromotionsService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\SalesRule\Api\Data\RuleSearchResultInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromotionsServiceTest extends TestCase
{
    private FeedRepository|MockObject       $feedRepository;
    private PromotionMapper|MockObject      $promotionMapper;
    private RuleRepositoryInterface|MockObject $ruleRepository;
    private SearchCriteriaBuilder|MockObject $criteriaBuilder;
    private CacheInterface|MockObject       $cache;
    private SerializerInterface|MockObject  $serializer;
    private PromotionsService               $service;

    protected function setUp(): void
    {
        $this->feedRepository  = $this->createMock(FeedRepository::class);
        $this->promotionMapper = $this->createMock(PromotionMapper::class);
        $this->ruleRepository  = $this->createMock(RuleRepositoryInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->cache           = $this->createMock(CacheInterface::class);

        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->serializer->method('serialize')->willReturnCallback(fn($v) => json_encode($v));
        $this->serializer->method('unserialize')->willReturnCallback(fn($v) => json_decode($v, true));

        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn(new SearchCriteria());

        $this->service = new PromotionsService(
            $this->feedRepository,
            $this->promotionMapper,
            $this->ruleRepository,
            $this->criteriaBuilder,
            $this->cache,
            $this->serializer,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function feedExists(): void
    {
        $this->feedRepository->method('get')->willReturn([
            'id' => 'feed_test', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '',
        ]);
    }

    public function testGetPromotionsThrowsWhenFeedNotFound(): void
    {
        $this->feedRepository->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->expectException(NoSuchEntityException::class);
        $this->service->getPromotions('nonexistent');
    }

    public function testGetPromotionsServesFromCacheWhenAvailable(): void
    {
        $this->feedExists();
        $cached = [['id' => 'promo_1', 'title' => 'Spring Sale', 'active_period' => [], 'benefits' => []]];
        $this->cache->method('load')->willReturn(json_encode($cached));

        $this->ruleRepository->expects($this->never())->method('getList');

        $result = $this->service->getPromotions('feed_test');

        $this->assertCount(1, $result);
        $this->assertSame('promo_1', $result[0]['id']);
    }

    public function testGetPromotionsBuildFromSalesRulesOnCacheMiss(): void
    {
        $this->feedExists();
        $this->cache->method('load')->willReturn(false);

        $searchResult = $this->createMock(RuleSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([]);
        $this->ruleRepository->expects($this->once())->method('getList')->willReturn($searchResult);

        $result = $this->service->getPromotions('feed_test');

        $this->assertIsArray($result);
    }

    public function testUpsertThrowsWhenFeedNotFound(): void
    {
        $this->feedRepository->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->expectException(NoSuchEntityException::class);
        $this->service->upsertPromotions('nonexistent', []);
    }

    public function testUpsertRejectsEmptyArray(): void
    {
        $this->feedExists();

        $result = $this->service->upsertPromotions('feed_test', []);

        $this->assertFalse($result['accepted']);
        $this->assertSame(0, $result['upserted_count']);
    }

    public function testUpsertRejectsMissingId(): void
    {
        $this->feedExists();

        $result = $this->service->upsertPromotions('feed_test', [
            [
                'title'         => 'No ID Promo',
                'active_period' => ['start_time' => '2026-01-01T00:00:00Z', 'end_time' => '2026-12-31T23:59:59Z'],
                'benefits'      => [['type' => 'free_shipping']],
            ],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('id is required', $result['errors'][0]);
    }

    public function testUpsertRejectsMissingBenefits(): void
    {
        $this->feedExists();

        $result = $this->service->upsertPromotions('feed_test', [
            [
                'id'            => 'promo_bad',
                'title'         => 'Bad Promo',
                'active_period' => ['start_time' => '2026-01-01T00:00:00Z', 'end_time' => '2026-12-31T23:59:59Z'],
            ],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('benefits', implode(' ', $result['errors']));
    }

    public function testUpsertRejectsInvalidBenefitType(): void
    {
        $this->feedExists();

        $result = $this->service->upsertPromotions('feed_test', [
            [
                'id'            => 'promo_bad_type',
                'title'         => 'Bad Type',
                'active_period' => ['start_time' => '2026-01-01T00:00:00Z', 'end_time' => '2026-12-31T23:59:59Z'],
                'benefits'      => [['type' => 'mystery_discount']],
            ],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('amount_off|percent_off|free_shipping', $result['errors'][0]);
    }

    public function testUpsertAcceptsValidPromotion(): void
    {
        $this->feedExists();
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->feedRepository->expects($this->once())->method('touch');

        $result = $this->service->upsertPromotions('feed_test', [
            [
                'id'            => 'promo_spring',
                'title'         => '15% Spring Sale',
                'status'        => 'active',
                'active_period' => ['start_time' => '2026-03-01T00:00:00Z', 'end_time' => '2026-05-31T23:59:59Z'],
                'benefits'      => [['type' => 'percent_off', 'percent_off' => 15.0]],
            ],
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(1, $result['upserted_count']);
        $this->assertEmpty($result['errors']);
    }

    public function testUpsertMergesWithExistingPromotions(): void
    {
        $this->feedExists();

        $existing = [[
            'id'            => 'promo_old',
            'title'         => 'Old Promo',
            'active_period' => ['start_time' => '2026-01-01T00:00:00Z', 'end_time' => '2026-06-30T23:59:59Z'],
            'benefits'      => [['type' => 'free_shipping']],
        ]];
        $this->cache->method('load')->willReturn(json_encode($existing));

        $saved = null;
        $this->cache->expects($this->once())->method('save')
            ->willReturnCallback(function ($data) use (&$saved) {
                $saved = json_decode($data, true);
                return true;
            });

        $this->service->upsertPromotions('feed_test', [[
            'id'            => 'promo_new',
            'title'         => 'New Promo',
            'active_period' => ['start_time' => '2026-07-01T00:00:00Z', 'end_time' => '2026-12-31T23:59:59Z'],
            'benefits'      => [['type' => 'percent_off', 'percent_off' => 20]],
        ]]);

        $ids = array_column($saved, 'id');
        $this->assertContains('promo_old', $ids);
        $this->assertContains('promo_new', $ids);
    }
}
