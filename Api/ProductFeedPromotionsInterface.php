<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Api;

/**
 * OpenAI ACP — Promotions resource.
 *
 * Spec: https://developers.openai.com/commerce/specs/api/promotions
 *
 * Same PATCH-as-POST workaround applies (see ProductFeedProductsInterface).
 *
 * Promotion schema:
 *   id            string  required
 *   title         string  required
 *   description   object  optional  {plain?, html?, markdown?}
 *   status        string  optional  draft|scheduled|active|expired|disabled
 *   active_period object  required  {start_time: RFC3339, end_time: RFC3339}
 *   benefits      array   required  PromotionBenefit[]
 *   applies_to    array   optional  ProductTarget[]
 *   url           string  optional
 *
 * PromotionBenefit (union):
 *   AmountOff:    {type:"amount_off",  amount_off:{amount, currency}}
 *   PercentOff:   {type:"percent_off", percent_off: number}
 *   FreeShipping: {type:"free_shipping"}
 *
 * ProductTarget:
 *   {product_id: string, variant_ids?: string[]}
 */
interface ProductFeedPromotionsInterface
{
    /**
     * GET /V1/angeo/product_feeds/:feedId/promotions
     *
     * Returns active promotions sourced from Magento SalesRules.
     * PATCH-upserted promotions override SalesRule-generated ones for the same id.
     *
     * @param string $feedId
     * @return mixed[]  Promotion[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getPromotions(string $feedId): array;

    /**
     * POST /V1/angeo/product_feeds/:feedId/promotions/upsert
     * (Handles PATCH via nginx rewrite)
     *
     * Upsert promotions. Matched by `id`; unmentioned promotions unchanged.
     *
     * @param string  $feedId
     * @param mixed[] $promotions  Promotion[]
     * @return mixed[]  {id: feedId, accepted: bool, upserted_count: int, errors: string[]}
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function upsertPromotions(string $feedId, array $promotions): array;
}
