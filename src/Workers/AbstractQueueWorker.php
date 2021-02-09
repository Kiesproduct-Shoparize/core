<?php

namespace Benzine\Workers;

use Benzine\Services\EnvironmentService;
use Benzine\Services\QueueService;
use Monolog\Logger;

abstract class AbstractQueueWorker extends AbstractWorker
{
    protected QueueService $queueService;

    /** @var string Name of the input redis queue */
    protected ?string $inputQueue;
    /** @var string[] Name of the output redis queues */
    protected ?array $outputQueues;

    protected ?array $resultItems;

    protected bool $showRemainingQueueLength = true;

    protected int $lastLength = -1;

    public function __construct(
        QueueService $queueService,
        Logger $logger,
        EnvironmentService $environmentService
    ) {
        $this->queueService = $queueService;
        parent::__construct($logger, $environmentService);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set default queues
        if (!isset($this->inputQueue)) {
            $this->inputQueue = sprintf('%s:input', $this->getClassWithoutNamespace());
        }
        if (!isset($this->outputQueues)) {
            $this->outputQueues[] = sprintf('%s:output', $this->getClassWithoutNamespace());
        }

        $this->logger->debug(
            sprintf(
                'Worker %s: Listening to "%s" and outputting on %d channel(s)',
                $this->getClassWithoutNamespace(),
                $this->inputQueue,
                count($this->outputQueues)
            )
        );
    }

    public function getQueueService(): QueueService
    {
        return $this->queueService;
    }

    public function setQueueService(QueueService $queueService): AbstractQueueWorker
    {
        $this->queueService = $queueService;

        return $this;
    }

    public function getInputQueue(): string
    {
        return $this->inputQueue;
    }

    public function setInputQueue(string $inputQueue): AbstractQueueWorker
    {
        $this->inputQueue = $inputQueue;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getOutputQueues(): array
    {
        return $this->outputQueues;
    }

    /**
     * @param string[] $outputQueues
     */
    public function setOutputQueues(array $outputQueues): AbstractQueueWorker
    {
        $this->outputQueues = $outputQueues;

        return $this;
    }

    public function iterate(): bool
    {
        $queueLength = $this->queueService->getQueueLength($this->inputQueue);
        if ($this->showRemainingQueueLength && $queueLength != $this->lastLength) {
            $this->logger->debug(sprintf(
                'Queue %s Length: %d',
                $this->inputQueue,
                $queueLength
            ));
            $this->lastLength = $queueLength;
        }

        if ($this->stopOnZero && 0 == $queueLength) {
            $this->logger->warning('--stop-on-zero is set, and the queue length is zero! Stopping!');

            exit;
        }

        if ($queueLength <= 0) {
            return false;
        }

        $items = $this->queueService->pop($this->inputQueue);
        $this->resultItems = [];

        // If there are no items popped, return fast.
        if (count($items) == 0) {
            return false;
        }

        foreach ($items as $item) {
            try {
                $processResults = $this->process($item);
                if (is_array($processResults)) {
                    foreach ($processResults as $processResult) {
                        $this->resultItems[] = $processResult;
                    }
                } elseif (null !== $processResults) {
                    $this->resultItems[] = $processResults;
                }
            } catch (\Exception $e) {
                $this->returnToInputQueue($item);

                throw new \Exception($e);
            }
        }

        foreach ($this->outputQueues as $outputQueue) {
            $this->queueService->push($outputQueue, $this->resultItems);
        }

        return true;
    }

    public function getResultItems(): ?array
    {
        return $this->resultItems;
    }

    /**
     * Send work item back to the queue it came from.
     */
    protected function returnToInputQueue(WorkerWorkItem $item): void
    {
        $this->queueService->push($this->inputQueue, [$item]);
    }

    protected function sendToSuccessQueues(WorkerWorkItem $item): int
    {
        $queuedItems = 0;
        foreach ($this->outputQueues as $outputQueue) {
            $queuedItems += $this->queueService->push($outputQueue, [$item]);
        }

        return $queuedItems;
    }

    protected function sendToFailureQueue(WorkerWorkItem $item): void
    {
        $this->queueService->push($this->getFailureQueue(), [$item]);
    }

    protected function getFailureQueue(): string
    {
        return sprintf('%s:failures', $this->inputQueue);
    }

    /**
     * @return null|WorkerWorkItem|WorkerWorkItem[]
     */
    abstract protected function process(WorkerWorkItem $item);
}
