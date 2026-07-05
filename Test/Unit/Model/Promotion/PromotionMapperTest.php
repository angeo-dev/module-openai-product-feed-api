<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Promotion;

use Angeo\OpenAiProductFeedApi\Model\Promotion\PromotionMapper;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\CouponSearchResultInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromotionMapperTest extends TestCase
{
    private StoreManagerInterface|MockObject $storeManager;
    private CouponRepositoryInterface|MockObject $couponRepository;
    private SearchCriteriaBuilder|MockObject $criteriaBuilder;
    private PromotionMapper $mapper;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->couponRepository = $this->createMock(CouponRepositoryInterface::class);

        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->criteriaBuilder->method('addFilter')->willReturnSelf();
        $this->criteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->mapper = new PromotionMapper(
            $this->storeManager,
            $this->couponRepository,
            $this->criteriaBuilder,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function mockRule(array $data): RuleInterface|MockObject
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getRuleId')->willReturn($data['rule_id'] ?? 1);
        $rule->method('getName')->willReturn($data['name'] ?? 'Test Rule');
        $rule->method('getDescription')->willReturn($data['description'] ?? '');
        $rule->method('getIsActive')->willReturn($data['is_active'] ?? true);
        $rule->method('getFromDate')->willReturn($data['from_date'] ?? null);
        $rule->method('getToDate')->willReturn($data['to_date'] ?? null);
        $rule->method('getSimpleAction')->willReturn($data['simple_action'] ?? RuleInterface::DISCOUNT_ACTION_BY_PERCENT);
        $rule->method('getDiscountAmount')->willReturn($data['discount_amount'] ?? 10.0);
        $rule->method('getSimpleFreeShipping')->willReturn($data['simple_free_shipping'] ?? RuleInterface::FREE_SHIPPING_NONE);
        $rule->method('getCouponType')->willReturn($data['coupon_type'] ?? RuleInterface::COUPON_TYPE_NO_COUPON);

        return $rule;
    }

    private function expectCoupon(string $code): void
    {
        $coupon = $this->createMock(CouponInterface::class);
        $coupon->method('getCode')->willReturn($code);

        $result = $this->createMock(CouponSearchResultInterface::class);
        $result->method('getItems')->willReturn([$coupon]);

        $this->couponRepository->method('getList')->willReturn($result);
    }

    public function testMapPercentOffRule(): void
    {
        $rule   = $this->mockRule(['simple_action' => 'by_percent', 'discount_amount' => 15.0]);
        $result = $this->mapper->map($rule);

        $this->assertNotNull($result);
        $this->assertSame('active', $result['status']);
        $this->assertCount(1, $result['benefits']);
        $this->assertSame('percent_off', $result['benefits'][0]['type']);
        $this->assertSame(15.0, $result['benefits'][0]['percent_off']);
    }

    public function testMapFixedAmountOffRule(): void
    {
        $rule   = $this->mockRule(['simple_action' => 'by_fixed', 'discount_amount' => 5.0]);
        $result = $this->mapper->map($rule);

        $this->assertNotNull($result);
        $benefit = $result['benefits'][0];
        $this->assertSame('amount_off', $benefit['type']);
        $this->assertSame(500, $benefit['amount_off']['amount']); // minor units
        $this->assertSame('USD', $benefit['amount_off']['currency']);
    }

    public function testMapCartFixedRule(): void
    {
        $rule   = $this->mockRule(['simple_action' => 'cart_fixed', 'discount_amount' => 20.0]);
        $result = $this->mapper->map($rule);

        $this->assertSame('amount_off', $result['benefits'][0]['type']);
        $this->assertSame(2000, $result['benefits'][0]['amount_off']['amount']);
    }

    public function testMapFreeShippingFromSimpleFreeShippingField(): void
    {
        $rule = $this->mockRule([
            'simple_action'        => 'by_percent',
            'discount_amount'      => 10.0,
            'simple_free_shipping' => RuleInterface::FREE_SHIPPING_WITH_MATCHING_ITEMS,
        ]);

        $result = $this->mapper->map($rule);
        $types  = array_column($result['benefits'], 'type');

        $this->assertContains('free_shipping', $types);
        $this->assertContains('percent_off', $types);
    }

    public function testMapFreeShippingOnlyRule(): void
    {
        $rule = $this->mockRule([
            'simple_action'        => 'buy_x_get_y',
            'discount_amount'      => 0.0,
            'simple_free_shipping' => RuleInterface::FREE_SHIPPING_MATCHING_ITEMS_ONLY,
        ]);

        $result = $this->mapper->map($rule);

        $this->assertNotNull($result);
        $this->assertSame([['type' => 'free_shipping']], $result['benefits']);
    }

    public function testMapReturnsNullForUnknownActionWithoutFreeShipping(): void
    {
        $rule = $this->mockRule([
            'simple_action'        => 'buy_x_get_y',
            'discount_amount'      => 0.0,
            'simple_free_shipping' => RuleInterface::FREE_SHIPPING_NONE,
        ]);

        $this->assertNull($this->mapper->map($rule));
    }

    public function testCouponCodeAppendedForSpecificCouponRules(): void
    {
        $this->expectCoupon('SAVE10');

        $rule = $this->mockRule([
            'description' => 'Save on everything',
            'coupon_type' => RuleInterface::COUPON_TYPE_SPECIFIC_COUPON,
        ]);

        $result = $this->mapper->map($rule);

        $this->assertSame('Save on everything Use code: SAVE10', $result['description']['plain']);
    }

    public function testCouponRepositoryNotQueriedForNoCouponRules(): void
    {
        $this->couponRepository->expects($this->never())->method('getList');

        $rule = $this->mockRule(['coupon_type' => RuleInterface::COUPON_TYPE_NO_COUPON]);
        $this->mapper->map($rule);
    }

    public function testMapStatusScheduled(): void
    {
        $rule = $this->mockRule(['from_date' => date('Y-m-d', strtotime('+30 days'))]);

        $this->assertSame('scheduled', $this->mapper->map($rule)['status']);
    }

    public function testMapStatusExpired(): void
    {
        $rule = $this->mockRule(['to_date' => date('Y-m-d', strtotime('-1 day'))]);

        $this->assertSame('expired', $this->mapper->map($rule)['status']);
    }

    public function testMapStatusDisabledWhenNotActive(): void
    {
        $rule = $this->mockRule(['is_active' => false]);

        $this->assertSame('disabled', $this->mapper->map($rule)['status']);
    }

    public function testMapActivePeriodDefaults(): void
    {
        $result = $this->mapper->map($this->mockRule([]));

        $this->assertArrayHasKey('start_time', $result['active_period']);
        $this->assertArrayHasKey('end_time', $result['active_period']);
    }

    public function testMapIdFormat(): void
    {
        $result = $this->mapper->map($this->mockRule(['rule_id' => 42]));

        $this->assertSame('promo_rule_42', $result['id']);
    }

    public function testMapDescriptionIncludedWhenPresent(): void
    {
        $result = $this->mapper->map($this->mockRule(['description' => 'Save 10% on all items']));

        $this->assertSame('Save 10% on all items', $result['description']['plain']);
    }
}
