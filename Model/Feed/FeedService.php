<?php

declare(strict_types=1);

namespace Angeo\OpenAiProductFeedApi\Model\Feed;

use Angeo\OpenAiProductFeedApi\Api\ProductFeedInterface;

class FeedService implements ProductFeedInterface
{
    public function __construct(private readonly FeedRepository $repository) {}

    public function create(?string $targetCountry = null, ?int $storeId = null): array
    {
        return $this->repository->create($targetCountry, $storeId ?? 0);
    }

    public function get(string $feedId): array
    {
        return $this->repository->get($feedId); // throws NoSuchEntityException
    }

    public function list(?int $storeId = null): array
    {
        return $this->repository->list($storeId);
    }
}
