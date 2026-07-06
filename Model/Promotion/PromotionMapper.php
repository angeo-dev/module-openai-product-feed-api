<?php

/**
 * @copyright Copyright (c) 2025 Ievgenii Gryshkun
 * @author    Ievgenii Gryshkun <info@angeo.dev>
 * @license   MIT
 */

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Promotion;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a Magento SalesRule to the OpenAI ACP Promotion schema.
 *
 * Spec: https://developers.openai.com/commerce/specs/api/promotions
 *
 * Supported benefit mappings:
 *   by_percent            → percent_off
 *   by_fixed / cart_fixed → amount_off
 *   simple_free_shipping  → free_shipping (combinable with a discount benefit)
 *
 * Implementation notes:
 * - Free shipping lives in the `simple_free_shipping` rule field, not in
 *   `simple_action`. The repository data model exposes it via
 *   getSimpleFreeShipping() with RuleInterface::FREE_SHIPPING_* string
 *   constants (legacy integer values 1/2 are tolerated as well).
 * - Coupon codes: the repository data model converts coupon_type to the
 *   RuleInterface::COUPON_TYPE_* string constants and does not expose
 *   getPrimaryCoupon(); the code is resolved through CouponRepositoryInterface.
 */
class PromotionMapper
{
    private const FREE_SHIPPING_VALUES = [
        RuleInterface::FREE_SHIPPING_MATCHING_ITEMS_ONLY,
        RuleInterface::FREE_SHIPPING_WITH_MATCHING_ITEMS,
        1, // legacy Model\Rule::FREE_SHIPPING_ITEM
        2, // legacy Model\Rule::FREE_SHIPPING_ADDRESS
        '1',
        '2',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CouponRepositoryInterface $couponRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array|null  null = cannot map this rule to a valid ACP promotion
     */
    public function map(RuleInterface $rule): ?array
    {
        $benefits = $this->buildBenefits($rule);

        if (empty($benefits)) {
            return null;
        }

        $promotion = [
            'id'            => 'promo_rule_' . $rule->getRuleId(),
            'title'         => $rule->getName() ?: ('Promotion #' . $rule->getRuleId()),
            'status'        => $this->resolveStatus($rule),
            'active_period' => $this->buildActivePeriod($rule),
            'benefits'      => $benefits,
        ];

        $descParts = [];

        if ($desc = trim((string) $rule->getDescription())) {
            $descParts[] = $desc;
        }

        $couponCode = $this->resolveCouponCode($rule);
        if ($couponCode !== '') {
            $descParts[] = 'Use code: ' . $couponCode;
        }

        if (!empty($descParts)) {
            $promotion['description'] = ['plain' => implode(' ', $descParts)];
        }

        return $promotion;
    }

    // ── Benefits ──────────────────────────────────────────────────────────────

    private function buildBenefits(RuleInterface $rule): array
    {
        $benefits = [];

        if (in_array($rule->getSimpleFreeShipping(), self::FREE_SHIPPING_VALUES, true)) {
            $benefits[] = ['type' => 'free_shipping'];
        }

        switch ($rule->getSimpleAction()) {
            case RuleInterface::DISCOUNT_ACTION_BY_PERCENT:
                $pct = (float) $rule->getDiscountAmount();
                if ($pct > 0.0 && $pct <= 100.0) {
                    $benefits[] = ['type' => 'percent_off', 'percent_off' => round($pct, 2)];
                }
                break;

            case RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT:
            case RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART:
                $amount = (int) round((float) $rule->getDiscountAmount() * 100);
                if ($amount > 0) {
                    $benefits[] = [
                        'type'       => 'amount_off',
                        'amount_off' => ['amount' => $amount, 'currency' => $this->getCurrency()],
                    ];
                }
                break;
        }

        return $benefits;
    }

    // ── Coupon code ───────────────────────────────────────────────────────────

    private function resolveCouponCode(RuleInterface $rule): string
    {
        $couponType = $rule->getCouponType();

        // Repository data model uses the string constant; legacy int 2 tolerated.
        if ($couponType !== RuleInterface::COUPON_TYPE_SPECIFIC_COUPON
            && (int) $couponType !== 2
        ) {
            return '';
        }

        try {
            $criteria = $this->criteriaBuilder
                ->addFilter('rule_id', $rule->getRuleId())
                ->setPageSize(1)
                ->create();

            foreach ($this->couponRepository->getList($criteria)->getItems() as $coupon) {
                return (string) $coupon->getCode();
            }
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    '[Angeo FeedApi] Could not resolve coupon code for rule %d: %s',
                    (int) $rule->getRuleId(),
                    $exception->getMessage()
                )
            );
        }

        return '';
    }

    // ── Status ────────────────────────────────────────────────────────────────

    private function resolveStatus(RuleInterface $rule): string
    {
        if (!$rule->getIsActive()) {
            return 'disabled';
        }

        $now  = time();
        $from = $rule->getFromDate() ? strtotime($rule->getFromDate()) : 0;
        $to   = $rule->getToDate()   ? strtotime($rule->getToDate())   : PHP_INT_MAX;

        if ($from > $now) {
            return 'scheduled';
        }
        if ($to < $now) {
            return 'expired';
        }

        return 'active';
    }

    // ── Active period ─────────────────────────────────────────────────────────

    private function buildActivePeriod(RuleInterface $rule): array
    {
        $start = $rule->getFromDate()
            ? date('c', strtotime($rule->getFromDate()))
            : date('c');

        $end = $rule->getToDate()
            ? date('c', strtotime($rule->getToDate()))
            : date('c', strtotime('+1 year'));

        return ['start_time' => $start, 'end_time' => $end];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getCurrency(): string
    {
        try {
            return strtoupper($this->storeManager->getStore()->getCurrentCurrencyCode() ?? 'USD');
        } catch (\Throwable) {
            return 'USD';
        }
    }
}
