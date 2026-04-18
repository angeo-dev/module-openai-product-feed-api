<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Promotion;

use Angeo\OpenAiProductFeedApi\Api\ProductFeedPromotionsInterface;
use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Psr\Log\LoggerInterface;

class PromotionsService implements ProductFeedPromotionsInterface
{
    private const CACHE_TAG    = 'angeo_acp_promotions';
    private const CACHE_PREFIX = 'angeo_acp_promotions_v2_';

    public function __construct(
        private readonly FeedRepository         $feedRepository,
        private readonly PromotionMapper        $promotionMapper,
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly SearchCriteriaBuilder  $criteriaBuilder,
        private readonly CacheInterface         $cache,
        private readonly SerializerInterface    $serializer,
        private readonly LoggerInterface        $logger,
    ) {}

    // ── GET ───────────────────────────────────────────────────────────────────

    public function getPromotions(string $feedId): array
    {
        $this->feedRepository->get($feedId); // guard

        $cached = $this->loadFromCache($feedId);
        if ($cached !== null) {
            return $cached;
        }

        return $this->buildFromSalesRules($feedId);
    }

    // ── POST (PATCH upsert) ───────────────────────────────────────────────────

    public function upsertPromotions(string $feedId, array $promotions): array
    {
        $this->feedRepository->get($feedId); // guard

        if (empty($promotions)) {
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => ['promotions array is empty']];
        }

        $errors         = [];
        $validPromotions = [];

        foreach ($promotions as $i => $promo) {
            $promoErrors = $this->validatePromotion($promo);
            if (!empty($promoErrors)) {
                foreach ($promoErrors as $err) {
                    $errors[] = "promotions[{$i}]: {$err}";
                }
            } else {
                $validPromotions[] = $promo;
            }
        }

        if (empty($validPromotions)) {
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => $errors];
        }

        try {
            $existing = $this->loadFromCache($feedId) ?? [];
            $indexed  = array_column($existing, null, 'id');

            foreach ($validPromotions as $promo) {
                $indexed[$promo['id']] = $promo;
            }

            $this->saveToCache($feedId, array_values($indexed));
            $this->feedRepository->touch($feedId);

            return [
                'id'            => $feedId,
                'accepted'      => true,
                'upserted_count' => count($validPromotions),
                'errors'        => $errors,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo FeedApi] upsertPromotions: ' . $e->getMessage());
            return ['id' => $feedId, 'accepted' => false, 'upserted_count' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    // ── SalesRule builder ─────────────────────────────────────────────────────

    private function buildFromSalesRules(string $feedId): array
    {
        $promotions = [];

        try {
            $criteria = $this->criteriaBuilder
                ->addFilter('is_active', 1)
                ->create();

            foreach ($this->ruleRepository->getList($criteria)->getItems() as $rule) {
                try {
                    $promo = $this->promotionMapper->map($rule);
                    if ($promo !== null) {
                        $promotions[] = $promo;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[Angeo FeedApi] Skipping rule ' . $rule->getRuleId() . ': ' . $e->getMessage());
                }
            }

            $this->saveToCache($feedId, $promotions);

        } catch (\Throwable $e) {
            $this->logger->error('[Angeo FeedApi] buildFromSalesRules: ' . $e->getMessage());
        }

        return $promotions;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validatePromotion(array $promo): array
    {
        $errors = [];

        if (empty($promo['id'])) {
            $errors[] = 'id is required';
        }
        if (empty($promo['title'])) {
            $errors[] = 'title is required';
        }
        if (empty($promo['active_period']['start_time'])) {
            $errors[] = 'active_period.start_time is required (RFC 3339)';
        }
        if (empty($promo['active_period']['end_time'])) {
            $errors[] = 'active_period.end_time is required (RFC 3339)';
        }
        if (empty($promo['benefits']) || !is_array($promo['benefits'])) {
            $errors[] = 'benefits array is required';
        } else {
            foreach ($promo['benefits'] as $j => $benefit) {
                $type = $benefit['type'] ?? '';
                if (!in_array($type, ['amount_off', 'percent_off', 'free_shipping'], true)) {
                    $errors[] = "benefits[{$j}].type must be amount_off|percent_off|free_shipping";
                }
                if ($type === 'amount_off' && empty($benefit['amount_off']['amount'])) {
                    $errors[] = "benefits[{$j}].amount_off.amount is required";
                }
                if ($type === 'percent_off' && !isset($benefit['percent_off'])) {
                    $errors[] = "benefits[{$j}].percent_off is required";
                }
            }
        }

        return $errors;
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    private function loadFromCache(string $feedId): ?array
    {
        $raw = $this->cache->load(self::CACHE_PREFIX . $feedId);
        return $raw ? $this->serializer->unserialize($raw) : null;
    }

    private function saveToCache(string $feedId, array $data): void
    {
        $this->cache->save(
            $this->serializer->serialize($data),
            self::CACHE_PREFIX . $feedId,
            [self::CACHE_TAG],
            3600 // 1 hour — promotions change less often than products
        );
    }
}
