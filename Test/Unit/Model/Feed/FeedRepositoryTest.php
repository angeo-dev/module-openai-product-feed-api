<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Feed;

use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeedRepositoryTest extends TestCase
{
    private ResourceConnection|MockObject   $resourceConnection;
    private AdapterInterface|MockObject     $connection;
    private ScopeConfigInterface|MockObject $scopeConfig;
    private Random|MockObject               $random;
    private StoreRepositoryInterface|MockObject $storeRepository;
    private FeedRepository                  $repository;

    protected function setUp(): void
    {
        $this->connection         = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->scopeConfig        = $this->createMock(ScopeConfigInterface::class);
        $this->random             = $this->createMock(Random::class);
        $this->storeRepository    = $this->createMock(StoreRepositoryInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->repository = new FeedRepository(
            $this->resourceConnection,
            $this->scopeConfig,
            $this->random,
            $this->storeRepository,
        );
    }

    public function testCreateInsertsAndReturnsFormattedRow(): void
    {
        $this->random->method('getRandomString')->willReturn('abc123xyz789');
        $this->scopeConfig->method('getValue')->willReturn('DE');

        $this->connection->expects($this->once())->method('insert')
            ->with('angeo_acp_feed', $this->arrayHasKey('feed_id'));

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'feed_id'        => 'feed_abc123xyz789',
            'target_country' => 'DE',
            'store_id'       => 0,
            'updated_at'     => '2026-04-15 10:00:00',
            'created_at'     => '2026-04-15 10:00:00',
        ]);

        $result = $this->repository->create('DE', 0);

        $this->assertSame('feed_abc123xyz789', $result['id']);
        $this->assertSame('DE', $result['target_country']);
        $this->assertArrayHasKey('updated_at', $result);
        $this->assertArrayHasKey('created_at', $result);
    }

    public function testGetReturnsFormattedRow(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'feed_id'        => 'feed_test001',
            'target_country' => 'US',
            'store_id'       => 1,
            'updated_at'     => '2026-04-15 12:00:00',
            'created_at'     => '2026-04-15 10:00:00',
        ]);

        $result = $this->repository->get('feed_test001');

        $this->assertSame('feed_test001', $result['id']);
        $this->assertSame('US', $result['target_country']);
        $this->assertSame(1, $result['store_id']);
    }

    public function testGetThrowsNoSuchEntityWhenNotFound(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->get('nonexistent_feed');
    }

    public function testListReturnsAllFeeds(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            ['feed_id' => 'feed_1', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => ''],
            ['feed_id' => 'feed_2', 'target_country' => 'DE', 'store_id' => 1, 'updated_at' => '', 'created_at' => ''],
        ]);

        $result = $this->repository->list();

        $this->assertCount(2, $result);
        $this->assertSame('feed_1', $result[0]['id']);
        $this->assertSame('feed_2', $result[1]['id']);
    }

    public function testListFiltersByStoreId(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('store_id = ?', 1)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([]);

        $this->repository->list(1);
    }

    public function testExistsReturnsTrueWhenFound(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'feed_id' => 'feed_x', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => '',
        ]);

        $this->assertTrue($this->repository->exists('feed_x'));
    }

    public function testExistsReturnsFalseWhenNotFound(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        $this->assertFalse($this->repository->exists('ghost_feed'));
    }
}
