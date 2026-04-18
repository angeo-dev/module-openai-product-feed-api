<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Promotion;

use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Maps a Magento SalesRule to the OpenAI ACP Promotion schema.
 *
 * Spec: https://developers.openai.com/commerce/specs/api/promotions
 *
 * Supported benefit mappings:
 *   by_percent   → percent_off
 *   by_fixed     → amount_off
 *   cart_fixed   → amount_off
 *   free_shipping flag → free_shipping
 *
 * Note on free_shipping detection:
 *   RuleInterface does NOT declare getFreeShipping(). Free shipping is
 *   expressed in two ways on a SalesRule:
 *     1. simple_action is a free-shipping type (RuleInterface::FREE_SHIPPING_* constants)
 *     2. apply_to_shipping = 1 (DataObject magic method via getApplyToShipping())
 */
class PromotionMapper
{
    private const ACTION_PERCENT    = 'by_percent';
    private const ACTION_FIXED      = 'by_fixed';
    private const ACTION_CART_FIXED = 'cart_fixed';

    // free_shipping values that can appear in the simple_action column
    private const FREE_SHIPPING_ACTIONS = [
        RuleInterface::FREE_SHIPPING_MATCHING_ITEMS_ONLY,
        RuleInterface::FREE_SHIPPING_WITH_MATCHING_ITEMS,
    ];

    public function __construct(private readonly StoreManagerInterface $storeManager) {}

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
        if ($rule->getCouponType() == 2 && $coupon = $rule->getPrimaryCoupon()) {
            try {
                $code = $coupon->getCode();
                if ($code) {
                    $descParts[] = 'Use code: ' . $code;
                }
            } catch (\Throwable) {}
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
        $action   = $rule->getSimpleAction();

        // Free shipping — two sources:
        // 1. simple_action matches a FREE_SHIPPING_* constant from RuleInterface
        // 2. apply_to_shipping = 1 (DataObject magic method, works on Model\Rule)
        $isFreeShippingAction = in_array($action, self::FREE_SHIPPING_ACTIONS, true);
        $isApplyToShipping    = (bool) $rule->getApplyToShipping();

        if ($isFreeShippingAction || $isApplyToShipping) {
            $benefits[] = ['type' => 'free_shipping'];
        }

        switch ($action) {
            case self::ACTION_PERCENT:
                $pct = (float) $rule->getDiscountAmount();
                if ($pct > 0.0 && $pct <= 100.0) {
                    $benefits[] = ['type' => 'percent_off', 'percent_off' => round($pct, 2)];
                }
                break;

            case self::ACTION_FIXED:
            case self::ACTION_CART_FIXED:
                $amount   = (int) round((float) $rule->getDiscountAmount() * 100);
                $currency = $this->getCurrency();
                if ($amount > 0) {
                    $benefits[] = [
                        'type'       => 'amount_off',
                        'amount_off' => ['amount' => $amount, 'currency' => $currency],
                    ];
                }
                break;
        }

        return $benefits;
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

        if ($from > $now) return 'scheduled';
        if ($to < $now)   return 'expired';

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
