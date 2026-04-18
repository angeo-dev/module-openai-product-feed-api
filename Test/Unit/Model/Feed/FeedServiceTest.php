<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Test\Unit\Model\Feed;

use Angeo\OpenAiProductFeedApi\Model\Feed\FeedRepository;
use Angeo\OpenAiProductFeedApi\Model\Feed\FeedService;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeedServiceTest extends TestCase
{
    private FeedRepository|MockObject $repository;
    private FeedService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FeedRepository::class);
        $this->service    = new FeedService($this->repository);
    }

    public function testCreateDelegatesToRepository(): void
    {
        $expected = ['id' => 'feed_abc', 'target_country' => 'US', 'store_id' => 0, 'updated_at' => '', 'created_at' => ''];
        $this->repository->expects($this->once())->method('create')->with('US', 0)->willReturn($expected);

        $result = $this->service->create('US', 0);

        $this->assertSame('feed_abc', $result['id']);
    }

    public function testGetDelegatesToRepository(): void
    {
        $expected = ['id' => 'feed_abc', 'target_country' => 'DE', 'store_id' => 1, 'updated_at' => '', 'created_at' => ''];
        $this->repository->expects($this->once())->method('get')->with('feed_abc')->willReturn($expected);

        $result = $this->service->get('feed_abc');

        $this->assertSame('DE', $result['target_country']);
    }

    public function testGetPropagatesNotFoundException(): void
    {
        $this->repository->method('get')->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->expectException(NoSuchEntityException::class);
        $this->service->get('missing');
    }

    public function testListDelegatesToRepository(): void
    {
        $this->repository->expects($this->once())->method('list')->with(null)->willReturn([]);
        $this->service->list();
    }

    public function testListWithStoreIdFilter(): void
    {
        $this->repository->expects($this->once())->method('list')->with(2)->willReturn([]);
        $this->service->list(2);
    }
}
