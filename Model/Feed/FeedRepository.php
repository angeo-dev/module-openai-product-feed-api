<?php

/**
 * @copyright Copyright (c) 2025 Ievgenii Gryshkun
 * @author    Ievgenii Gryshkun <info@angeo.dev>
 * @license   MIT
 */

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Feed;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * DB-persisted feed repository.
 *
 * Feeds are stored in the angeo_acp_feed table (declarative schema) and
 * survive cache flushes and server restarts. Cache is only used for the
 * large, short-lived product/promotion payload data.
 */
class FeedRepository
{
    private const TABLE            = 'angeo_acp_feed';
    private const CONFIG_COUNTRY   = 'angeo_feed_api/general/target_country';
    private const ID_PREFIX        = 'feed_';
    private const ID_RANDOM_LENGTH = 12;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Random $random,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {}

    /**
     * Create and persist a new feed record.
     *
     * @throws InputException When target country is not ISO 3166-1 alpha-2
     * @throws NoSuchEntityException When the store ID does not exist
     */
    public function create(?string $targetCountry, ?int $storeId = 0): array
    {
        $country = strtoupper(trim(
            (string) ($targetCountry
                ?? ((string) $this->scopeConfig->getValue(self::CONFIG_COUNTRY, ScopeInterface::SCOPE_STORE) ?: 'US'))
        ));

        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InputException(
                __('target_country must be a two letter ISO 3166-1 alpha-2 code, "%1" given.', $country)
            );
        }

        $storeId = $storeId ?? 0;

        if ($storeId > 0) {
            // Throws NoSuchEntityException for unknown stores.
            $this->storeRepository->getById($storeId);
        }

        $feedId = self::ID_PREFIX . $this->random->getRandomString(
            self::ID_RANDOM_LENGTH,
            Random::CHARS_LOWERS . Random::CHARS_DIGITS
        );

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insert($table, [
            'feed_id'        => $feedId,
            'target_country' => $country,
            'store_id'       => $storeId,
        ]);

        return $this->get($feedId);
    }

    /**
     * Load a feed by feed_id string.
     *
     * @throws NoSuchEntityException
     */
    public function get(string $feedId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::TABLE);

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('feed_id = ?', $feedId)
        );

        if (!$row) {
            throw new NoSuchEntityException(__('Product feed not found: %1', $feedId));
        }

        return $this->formatRow($row);
    }

    /**
     * @return array<int, array>  All feeds, optionally filtered by store_id
     */
    public function list(?int $storeId = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()->from($table)->order('created_at DESC');

        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }

        return array_map([$this, 'formatRow'], $connection->fetchAll($select));
    }

    /**
     * Touch updated_at timestamp (called after product/promotion upsert).
     */
    public function touch(string $feedId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::TABLE);

        $connection->update($table, ['updated_at' => gmdate('Y-m-d H:i:s')], ['feed_id = ?' => $feedId]);
    }

    /**
     * Check existence without throwing.
     */
    public function exists(string $feedId): bool
    {
        try {
            $this->get($feedId);
            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    private function formatRow(array $row): array
    {
        return [
            'id'             => $row['feed_id'],
            'target_country' => $row['target_country'],
            'store_id'       => (int) $row['store_id'],
            'updated_at'     => $row['updated_at'],
            'created_at'     => $row['created_at'],
        ];
    }
}
