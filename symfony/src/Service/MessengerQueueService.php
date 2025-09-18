<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class MessengerQueueService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Get the number of pending messages in the queue
     */
    public function getPendingMessagesCount(string $queueName = 'wacz_processing'): int
    {
        try {
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NULL';
            $result = $this->connection->fetchAssociative($sql, [$queueName]);

            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get pending messages count', [
                'queue_name' => $queueName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get the number of failed messages in the queue
     */
    public function getFailedMessagesCount(string $queueName = 'wacz_processing'): int
    {
        try {
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL AND headers LIKE ?';
            $result = $this->connection->fetchAssociative($sql, [
                $queueName,
                '%"X-Message-Stamp-Symfony\\\\Component\\\\Messenger\\\\Stamp\\\\ErrorDetailsStamp"%'
            ]);

            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get failed messages count', [
                'queue_name' => $queueName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStatistics(string $queueName = 'wacz_processing'): array
    {
        try {
            // Get total messages
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE queue_name = ?';
            $result = $this->connection->fetchAssociative($sql, [$queueName]);
            $totalMessages = (int) ($result['count'] ?? 0);

            // Get pending messages
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NULL';
            $result = $this->connection->fetchAssociative($sql, [$queueName]);
            $pendingMessages = (int) ($result['count'] ?? 0);

            // Get delivered (processed + failed) messages
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL';
            $result = $this->connection->fetchAssociative($sql, [$queueName]);
            $deliveredMessages = (int) ($result['count'] ?? 0);

            // For now, treat all delivered messages as processed (we can improve this later)
            $processedMessages = $deliveredMessages;
            $failedMessages = 0;

            return [
                'total_messages' => $totalMessages,
                'pending_messages' => $pendingMessages,
                'processed_messages' => $processedMessages,
                'failed_messages' => $failedMessages
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get queue statistics', [
                'queue_name' => $queueName,
                'error' => $e->getMessage()
            ]);

            return [
                'total_messages' => 0,
                'pending_messages' => 0,
                'processed_messages' => 0,
                'failed_messages' => 0
            ];
        }
    }

    /**
     * Purge old processed messages from the queue
     */
    public function purgeOldMessages(string $queueName = 'wacz_processing', int $daysOld = 7): int
    {
        try {
            $cutoffDate = new \DateTime("-{$daysOld} days");
            $sql = 'DELETE FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL AND delivered_at < ?';
            $deletedCount = $this->connection->executeStatement($sql, [
                $queueName,
                $cutoffDate->format('Y-m-d H:i:s')
            ]);

            return $deletedCount;
        } catch (\Exception $e) {
            $this->logger->error('Failed to purge old messages', [
                'queue_name' => $queueName,
                'days_old' => $daysOld,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Check if there are workers actively processing messages
     */
    public function areWorkersActive(): bool
    {
        try {
            // Check for recent activity by looking at recently delivered messages
            $recentTime = new \DateTime('-5 minutes');
            $sql = 'SELECT COUNT(*) as count FROM messenger_messages WHERE delivered_at > ?';
            $result = $this->connection->fetchAssociative($sql, [$recentTime->format('Y-m-d H:i:s')]);

            return (int) ($result['count'] ?? 0) > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check worker activity', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}