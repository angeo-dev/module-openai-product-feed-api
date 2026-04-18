<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Api;

/**
 * OpenAI ACP — Feeds resource.
 *
 * Spec: https://developers.openai.com/commerce/specs/api/feeds
 */
interface ProductFeedInterface
{
    /**
     * POST /V1/angeo/product_feeds
     *
     * Create a new product feed for the given target country and store.
     *
     * @param string|null $targetCountry  ISO 3166-1 alpha-2, e.g. "US". Falls back to config default.
     * @param int|null    $storeId        Magento store ID. 0 = default store.
     * @return mixed[]  {id, target_country, store_id, updated_at, created_at}
     */
    public function create(?string $targetCountry = null, ?int $storeId = null): array;

    /**
     * GET /V1/angeo/product_feeds/:feedId
     *
     * Return metadata for an existing feed.
     *
     * @param string $feedId
     * @return mixed[]  {id, target_country, store_id, updated_at, created_at}
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(string $feedId): array;

    /**
     * GET /V1/angeo/product_feeds
     *
     * List all feeds, optionally filtered by store.
     *
     * @param int|null $storeId
     * @return mixed[]  Feed[]
     */
    public function list(?int $storeId = null): array;
}
