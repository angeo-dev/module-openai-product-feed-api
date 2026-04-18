<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Promotion;

use Angeo\OpenAiProductFeedApi\Model\Promotion\PromotionMapper;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PromotionMapperTest extends TestCase
{
    private StoreManagerInterface|MockObject $storeManager;
    private PromotionMapper $mapper;

    protected function setUp(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->mapper = new PromotionMapper($this->storeManager);
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
        $rule->method('getSimpleAction')->willReturn($data['simple_action'] ?? 'by_percent');
        $rule->method('getDiscountAmount')->willReturn($data['discount_amount'] ?? 10.0);
        $rule->method('getSimpleAction')
            ->willReturn(RuleInterface::FREE_SHIPPING_WITH_MATCHING_ITEMS);
        $rule->method('getApplyToShipping')->willReturn($data['apply_to_shipping'] ?? false);
        $rule->method('getCouponType')->willReturn($data['coupon_type'] ?? 1);
        return $rule;
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

    public function testMapFreeShippingAdded(): void
    {
        $rule = $this->mockRule([
            'simple_action'    => 'by_percent',
            'discount_amount'  => 10.0,
            'free_shipping'    => true,
        ]);

        $result   = $this->mapper->map($rule);
        $types    = array_column($result['benefits'], 'type');

        $this->assertContains('free_shipping', $types);
        $this->assertContains('percent_off', $types);
    }

    public function testMapReturnsNullForUnknownAction(): void
    {
        $rule = $this->mockRule([
            'simple_action'   => 'buy_x_get_y',
            'discount_amount' => 0.0,
            'free_shipping'   => false,
            'apply_to_shipping' => false,
        ]);

        $result = $this->mapper->map($rule);

        $this->assertNull($result);
    }

    public function testMapStatusScheduled(): void
    {
        $rule = $this->mockRule([
            'simple_action'   => 'by_percent',
            'discount_amount' => 5.0,
            'from_date'       => date('Y-m-d', strtotime('+30 days')),
        ]);

        $result = $this->mapper->map($rule);

        $this->assertSame('scheduled', $result['status']);
    }

    public function testMapStatusExpired(): void
    {
        $rule = $this->mockRule([
            'simple_action'   => 'by_percent',
            'discount_amount' => 5.0,
            'to_date'         => date('Y-m-d', strtotime('-1 day')),
        ]);

        $result = $this->mapper->map($rule);

        $this->assertSame('expired', $result['status']);
    }

    public function testMapStatusDisabledWhenNotActive(): void
    {
        $rule   = $this->mockRule(['is_active' => false, 'simple_action' => 'by_percent', 'discount_amount' => 5.0]);
        $result = $this->mapper->map($rule);

        $this->assertSame('disabled', $result['status']);
    }

    public function testMapActivePeriodDefaults(): void
    {
        $rule   = $this->mockRule(['simple_action' => 'by_percent', 'discount_amount' => 10.0]);
        $result = $this->mapper->map($rule);

        $this->assertArrayHasKey('start_time', $result['active_period']);
        $this->assertArrayHasKey('end_time', $result['active_period']);
    }

    public function testMapIdFormat(): void
    {
        $rule   = $this->mockRule(['rule_id' => 42, 'simple_action' => 'by_percent', 'discount_amount' => 5.0]);
        $result = $this->mapper->map($rule);

        $this->assertSame('promo_rule_42', $result['id']);
    }

    public function testMapDescriptionIncludedWhenPresent(): void
    {
        $rule   = $this->mockRule(['description' => 'Save 10% on all items', 'simple_action' => 'by_percent', 'discount_amount' => 10.0]);
        $result = $this->mapper->map($rule);

        $this->assertSame('Save 10% on all items', $result['description']['plain']);
    }
}
