<?php

declare(strict_types=1);

namespace EidCloud\JsonDb;

use Closure;
use EidCloud\JsonDb\Exception\DatabaseException;
use EidCloud\JsonDb\Transaction\Transaction;

/**
 * Main Database entry point.
 */
class Database
{
    private string $path;

    /**
     * Cache of loaded collection instances.
     * @var array<string, Collection>
     */
    private array $collections = [];

    /**
     * Active transaction instance.
     */
    private Transaction $transaction;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/\\');
        if (!is_dir($this->path)) {
            if (!mkdir($this->path, 0777, true) && !is_dir($this->path)) {
                throw new DatabaseException("Failed to initialize database directory: {$this->path}");
            }
        }
        $this->transaction = new Transaction($this);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get or create a Collection/Table instance.
     *
     * @param string $name
     * @return Collection
     */
    public function collection(string $name): Collection
    {
        $name = strtolower(trim($name));
        if (!isset($this->collections[$name])) {
            $filePath = $this->path . DIRECTORY_SEPARATOR . $name . '.json';
            $this->collections[$name] = new Collection($this, $name, $filePath);
        }

        return $this->collections[$name];
    }

    /**
     * Alias for collection() to support $db->table('users').
     *
     * @param string $name
     * @return Collection
     */
    public function table(string $name): Collection
    {
        return $this->collection($name);
    }

    /**
     * List all collection names in the database directory.
     *
     * @return array<string>
     */
    public function listCollections(): array
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return [];
        }

        $names = [];
        foreach ($files as $file) {
            $base = basename($file, '.json');
            $names[] = $base;
        }

        return $names;
    }

    /**
     * Drop a collection and delete its storage file.
     *
     * @param string $name
     * @return bool
     */
    public function dropCollection(string $name): bool
    {
        $name = strtolower(trim($name));
        $filePath = $this->path . DIRECTORY_SEPARATOR . $name . '.json';
        $lockPath = $filePath . '.lock';

        unset($this->collections[$name]);

        $res = true;
        if (file_exists($filePath)) {
            $res = @unlink($filePath);
        }
        if (file_exists($lockPath)) {
            @unlink($lockPath);
        }

        return $res;
    }

    /**
     * Begin an ACID transaction.
     */
    public function beginTransaction(): void
    {
        $this->transaction->begin();
    }

    /**
     * Commit active transaction.
     */
    public function commit(): void
    {
        $this->transaction->commit();
    }

    /**
     * Rollback active transaction.
     */
    public function rollback(): void
    {
        $this->transaction->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->transaction->isActive();
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * Execute a callback inside an atomic transaction.
     * Automatically commits on success, rollbacks on exception.
     *
     * @template T
     * @param Closure(Database): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
