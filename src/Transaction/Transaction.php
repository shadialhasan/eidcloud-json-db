<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Transaction;

use EidCloud\JsonDb\Database;
use EidCloud\JsonDb\Exception\DatabaseException;

/**
 * Manages ACID transactions across collections using snapshots.
 */
class Transaction
{
    private Database $db;
    private bool $isActive = false;

    /**
     * Map of collection name => snapshot of collection state before transaction.
     * @var array<string, array{documents: array, auto_increment: int, indexes: array}>
     */
    private array $snapshots = [];

    /**
     * List of collections modified during this transaction.
     * @var array<string, bool>
     */
    private array $modifiedCollections = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function begin(): void
    {
        if ($this->isActive) {
            throw new DatabaseException("Transaction already in progress.");
        }

        $this->isActive = true;
        $this->snapshots = [];
        $this->modifiedCollections = [];
    }

    /**
     * Take a snapshot of a collection before it is modified for the first time in this transaction.
     */
    public function recordCollectionSnapshot(string $collectionName): void
    {
        if (!$this->isActive) {
            return;
        }

        if (!isset($this->snapshots[$collectionName])) {
            $collection = $this->db->collection($collectionName);
            $this->snapshots[$collectionName] = $collection->createSnapshot();
        }

        $this->modifiedCollections[$collectionName] = true;
    }

    /**
     * Commit the transaction, persisting all changes.
     */
    public function commit(): void
    {
        if (!$this->isActive) {
            throw new DatabaseException("No active transaction to commit.");
        }

        // Flush all modified collections to disk
        foreach (array_keys($this->modifiedCollections) as $collectionName) {
            $this->db->collection($collectionName)->flush();
        }

        $this->isActive = false;
        $this->snapshots = [];
        $this->modifiedCollections = [];
    }

    /**
     * Rollback the transaction, restoring state to snapshot.
     */
    public function rollback(): void
    {
        if (!$this->isActive) {
            throw new DatabaseException("No active transaction to rollback.");
        }

        // Restore in-memory state from snapshot
        foreach ($this->snapshots as $collectionName => $snapshot) {
            $this->db->collection($collectionName)->restoreSnapshot($snapshot);
        }

        $this->isActive = false;
        $this->snapshots = [];
        $this->modifiedCollections = [];
    }
}
