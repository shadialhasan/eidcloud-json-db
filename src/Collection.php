<?php

declare(strict_types=1);

namespace EidCloud\JsonDb;

use EidCloud\JsonDb\Exception\DatabaseException;
use EidCloud\JsonDb\Exception\ValidationException;
use EidCloud\JsonDb\Index\IndexManager;
use EidCloud\JsonDb\Storage\AtomicStorage;

/**
 * Represents a document table/collection backed by a JSON file.
 */
class Collection
{
    private Database $db;
    private string $name;
    private string $filePath;
    private IndexManager $indexManager;

    /**
     * In-memory documents map: [id => document]
     * @var array<int|string, array<string, mixed>>
     */
    private array $documents = [];

    /**
     * Auto-increment counter for primary keys.
     */
    private int $autoIncrement = 1;

    /**
     * Optional JSON Schema or field type validation rules.
     * e.g. ['name' => 'string', 'age' => 'int', 'email' => 'string']
     * @var array<string, mixed>|null
     */
    private ?array $schema = null;

    /**
     * Primary key field name (default: 'id')
     */
    private string $primaryKey = 'id';

    /**
     * Whether memory is dirty (needs disk flush).
     */
    private bool $isDirty = false;

    public function __construct(Database $db, string $name, string $filePath)
    {
        $this->db = $db;
        $this->name = $name;
        $this->filePath = $filePath;
        $this->indexManager = new IndexManager();

        // Always create a default index on the primary key
        $this->indexManager->createIndex($this->primaryKey, true, 'pk_' . $this->primaryKey);

        $this->load();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function setPrimaryKey(string $pk): self
    {
        $this->primaryKey = $pk;
        return $this;
    }

    public function setSchema(?array $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    public function getSchema(): ?array
    {
        return $this->schema;
    }

    public function getIndexManager(): IndexManager
    {
        return $this->indexManager;
    }

    /**
     * Create an index on field(s).
     *
     * @param string|array<string> $fields
     * @param bool $unique
     * @param string|null $name
     * @return self
     */
    public function createIndex(string|array $fields, bool $unique = false, ?string $name = null): self
    {
        $this->indexManager->createIndex($fields, $unique, $name);
        $this->indexManager->rebuild($this->documents);
        $this->persistMetadata();
        return $this;
    }

    /**
     * Drop an index by name.
     */
    public function dropIndex(string $name): self
    {
        $this->indexManager->dropIndex($name);
        $this->persistMetadata();
        return $this;
    }

    /**
     * Begin a fluent query against this collection.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * Fluent proxy methods
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return $this->query()->where($column, $operator, $value);
    }

    public function find(int|string $id): ?array
    {
        return $this->documents[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->documents);
    }

    public function count(): int
    {
        return count($this->documents);
    }

    /**
     * Insert a document.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The inserted document with ID
     */
    public function insert(array $data): array
    {
        $this->notifyTransactionBeforeModification();

        // Assign Auto Increment ID if not set
        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = $this->autoIncrement++;
        } else {
            $id = $data[$this->primaryKey];
            if (isset($this->documents[$id])) {
                throw new DatabaseException("Duplicate primary key '{$id}' in collection '{$this->name}'");
            }
            if (is_int($id) && $id >= $this->autoIncrement) {
                $this->autoIncrement = $id + 1;
            }
        }

        $id = $data[$this->primaryKey];

        // Schema validation
        $this->validate($data);

        // Update indexes
        $this->indexManager->indexDocument($id, $data);

        // Store
        $this->documents[$id] = $data;
        $this->isDirty = true;

        if (!$this->db->inTransaction()) {
            $this->flush();
        }

        return $data;
    }

    /**
     * Insert multiple documents.
     *
     * @param array<array<string, mixed>> $records
     * @return array<array<string, mixed>>
     */
    public function insertMany(array $records): array
    {
        $inserted = [];
        $wasInTransaction = $this->db->inTransaction();

        if (!$wasInTransaction) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($records as $record) {
                $inserted[] = $this->insert($record);
            }

            if (!$wasInTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$wasInTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }

        return $inserted;
    }

    /**
     * Update a document by ID.
     *
     * @param int|string $id
     * @param array<string, mixed> $data
     * @return array<string, mixed> Updated document
     */
    public function update(int|string $id, array $data): array
    {
        if (!isset($this->documents[$id])) {
            throw new DatabaseException("Document with ID '{$id}' not found in '{$this->name}'");
        }

        $this->notifyTransactionBeforeModification();

        // Keep primary key immutable
        unset($data[$this->primaryKey]);

        $current = $this->documents[$id];
        $updated = array_merge($current, $data);

        // Schema validation
        $this->validate($updated);

        // Update indexes
        $this->indexManager->unindexDocument($id);
        $this->indexManager->indexDocument($id, $updated);

        $this->documents[$id] = $updated;
        $this->isDirty = true;

        if (!$this->db->inTransaction()) {
            $this->flush();
        }

        return $updated;
    }

    /**
     * Delete document by ID.
     *
     * @param int|string $id
     * @return bool
     */
    public function delete(int|string $id): bool
    {
        if (!isset($this->documents[$id])) {
            return false;
        }

        $this->notifyTransactionBeforeModification();

        $this->indexManager->unindexDocument($id);
        unset($this->documents[$id]);
        $this->isDirty = true;

        if (!$this->db->inTransaction()) {
            $this->flush();
        }

        return true;
    }

    /**
     * Truncate/clear entire collection.
     */
    public function truncate(): void
    {
        $this->notifyTransactionBeforeModification();

        $this->documents = [];
        $this->autoIncrement = 1;
        $this->indexManager->clear();
        $this->isDirty = true;

        if (!$this->db->inTransaction()) {
            $this->flush();
        }
    }

    /**
     * Get raw documents array (internal use).
     * @return array<int|string, array<string, mixed>>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    /**
     * Validate data against defined schema rules.
     */
    private function validate(array $data): void
    {
        if ($this->schema === null) {
            return;
        }

        foreach ($this->schema as $field => $rules) {
            $value = $data[$field] ?? null;

            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            foreach ($rules as $rule) {
                $rule = trim($rule);
                if ($rule === 'required' && ($value === null || $value === '')) {
                    throw new ValidationException("Field '{$field}' is required in '{$this->name}'");
                }

                if ($value !== null) {
                    if ($rule === 'int' || $rule === 'integer') {
                        if (!is_int($value) && !ctype_digit((string)$value)) {
                            throw new ValidationException("Field '{$field}' must be integer in '{$this->name}'");
                        }
                    } elseif ($rule === 'string') {
                        if (!is_string($value)) {
                            throw new ValidationException("Field '{$field}' must be string in '{$this->name}'");
                        }
                    } elseif ($rule === 'numeric' || $rule === 'float') {
                        if (!is_numeric($value)) {
                            throw new ValidationException("Field '{$field}' must be numeric in '{$this->name}'");
                        }
                    } elseif ($rule === 'bool' || $rule === 'boolean') {
                        if (!is_bool($value)) {
                            throw new ValidationException("Field '{$field}' must be boolean in '{$this->name}'");
                        }
                    } elseif ($rule === 'email') {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            throw new ValidationException("Field '{$field}' must be a valid email in '{$this->name}'");
                        }
                    }
                }
            }
        }
    }

    /**
     * Create snapshot for transactions.
     *
     * @return array{documents: array, auto_increment: int, dirty: bool}
     */
    public function createSnapshot(): array
    {
        return [
            'documents' => $this->documents,
            'auto_increment' => $this->autoIncrement,
            'dirty' => $this->isDirty
        ];
    }

    /**
     * Restore snapshot for rollback.
     */
    public function restoreSnapshot(array $snapshot): void
    {
        $this->documents = $snapshot['documents'];
        $this->autoIncrement = $snapshot['auto_increment'];
        $this->isDirty = $snapshot['dirty'];
        $this->indexManager->rebuild($this->documents);
    }

    /**
     * Persist in-memory state to disk atomically.
     */
    public function flush(): void
    {
        $payload = [
            'meta' => [
                'name' => $this->name,
                'primary_key' => $this->primaryKey,
                'auto_increment' => $this->autoIncrement,
                'schema' => $this->schema,
                'indexes' => $this->indexManager->getDefinitions(),
                'updated_at' => date('c'),
            ],
            'data' => $this->documents,
        ];

        AtomicStorage::write($this->filePath, $payload);
        $this->isDirty = false;
    }

    private function load(): void
    {
        if (!file_exists($this->filePath)) {
            $this->flush();
            return;
        }

        $content = AtomicStorage::read($this->filePath);
        if ($content && isset($content['data'])) {
            $this->documents = $content['data'];
            $meta = $content['meta'] ?? [];
            $this->autoIncrement = $meta['auto_increment'] ?? 1;
            $this->schema = $meta['schema'] ?? null;
            $this->primaryKey = $meta['primary_key'] ?? 'id';

            // Restore defined indexes
            if (isset($meta['indexes']) && is_array($meta['indexes'])) {
                foreach ($meta['indexes'] as $name => $fields) {
                    $isUnique = str_ends_with($name, '_unique');
                    $this->indexManager->createIndex($fields, $isUnique, $name);
                }
            }

            $this->indexManager->rebuild($this->documents);
        }
    }

    private function persistMetadata(): void
    {
        $this->flush();
    }

    private function notifyTransactionBeforeModification(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->getTransaction()->recordCollectionSnapshot($this->name);
        }
    }
}
