<?php

namespace Benzine\Services;

use Benzine\Redis\Redis;
use Benzine\Workers\WorkerWorkItem;
use Gone\UUID\UUID;
use Monolog\Logger;

class QueueService
{
    public const MAX_QUEUE_AGE = 60 * 60 * 24 * 365 * 20;
    protected Redis $redis;
    protected Logger $logger;

    public function __construct(
        Redis $redis,
        Logger $logger
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    /**
     * @param \Serializable[] $queueItems
     *
     * @return int the number of items successfully added
     */
    public function push(string $queueName, array $queueItems): int
    {
        $this->redis->multi();
        foreach ($queueItems as $item) {
            $itemId = UUID::v4();
            $serialised = serialize($item);
            // Set the data element itself
            $this->redis->set("queue:data:{$queueName}:{$itemId}", $serialised);
            // Push the element into the index list
            $this->redis->rPush("queue:index:{$queueName}", $itemId);
            // Increment the length count
            $this->redis->incr("queue:length:{$queueName}");
            // Set the queue identifier to the current time, if it doesn't already exist
            $this->redis->setnx("queue:queues:{$queueName}", date('Y-m-d H:i:s'));
            // And set that identifier to expire in a day.
            $this->redis->expire("queue:queues:{$queueName}", self::MAX_QUEUE_AGE);
        }
        $this->redis->exec();
        $this->redis->setifhigher("queue:length-peak:{$queueName}", $this->redis->get("queue:length:{$queueName}"));

        return count($queueItems);
    }

    /**
     * Get the length of the queue.
     */
    public function getQueueLength(string $queueName): int
    {
        return $this->redis->get("queue:length:{$queueName}") ?? 0;
    }

    /**
     * Get the peak/maximum length of the queue.
     */
    public function getQueueLengthPeak(string $queueName): int
    {
        return $this->redis->get("queue:length-peak:{$queueName}") ?? 0;
    }

    /**
     * Number of seconds that the queue was created ago.
     */
    public function getQueueCreatedAgo(string $queueName): \DateTime
    {
        return (new \DateTime())
            ->setTimestamp(strtotime($this->redis->get("queue:queues:{$queueName}")))
        ;
    }

    /**
     * Number of seconds ago that the queue was updated.
     */
    public function getQueueUpdatedAgo(string $queueName): \DateTime
    {
        return (new \DateTime())
            ->setTimestamp(time() - abs($this->getQueueExpiresIn($queueName) - self::MAX_QUEUE_AGE))
        ;
    }

    /**
     * Number of seconds until a given queue will expire.
     *
     * @return \DateTime
     */
    public function getQueueExpiresIn(string $queueName): int
    {
        return $this->redis->ttl("queue:queues:{$queueName}");
    }

    /**
     * @return WorkerWorkItem[]
     */
    public function pop(string $queueName, int $count = 1): array
    {
        $workerWorkItems = [];
        for ($i = 0; $i < $count; ++$i) {
            $itemId = $this->redis->lPop("queue:index:{$queueName}");
            if (!$itemId) {
                continue;
            }
            $this->redis->multi();
            $this->redis->get("queue:data:{$queueName}:{$itemId}");
            $this->redis->del(["queue:data:{$queueName}:{$itemId}"]);
            $this->redis->decr("queue:length:{$queueName}");
            $response = $this->redis->exec();
            $workerWorkItems[] = unserialize($response[0]);
        }
        if ($this->redis->get("queue:length:{$queueName}") <= 0) {
            $this->redis->set("queue:length:{$queueName}", 0);
        }

        return array_filter($workerWorkItems);
    }

    /**
     * Destroy a queue and all data inside it.
     * Returns number of redis keys deleted.
     */
    public function destroyQueue(string $queueName): int
    {
        $queueDataKeys = $this->redis->keys("queue:data:{$queueName}:*");

        return $this->redis->del([...$queueDataKeys, "queue:length:{$queueName}", "queue:length-peak:{$queueName}", "queue:index:{$queueName}", "queue:queues:{$queueName}"]);
    }

    /**
     * @return string[]
     */
    public function listLists(): array
    {
        $lists = [];
        foreach ($this->redis->keys(('queue:queues:*')) as $queue) {
            $lists[$queue] = substr($queue, strlen('queue:queues:'));
        }
        ksort($lists);

        return $lists;
    }

    /**
     * Return an key->value array of queue lengths.
     */
    public function allQueueLengths(): array
    {
        $lengths = [];
        foreach ($this->listLists() as $key => $name) {
            $lengths[$name] = $this->getQueueLength($name);
        }

        return $lengths;
    }
}
