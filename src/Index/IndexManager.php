<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Index;

/**
 * Manages single and compound BTree indexes for a collection.
 */
class IndexManager
{
    /**
     * Map of index name => BTreeIndex
     * @var array<string, BTreeIndex>
     */
    private array $indexes = [];

    /**
     * Map of index name => fields array
     * @var array<string, array<string>>
     */
    private array $indexDefinitions = [];

    /**
     * Define an index on one or multiple fields.
     *
     * @param string|array<string> $fields
     * @param bool $unique
     * @param string|null $name
     * @return string
     */
    public function createIndex(string|array $fields, bool $unique = false, ?string $name = null): string
    {
        $fieldArray = is_array($fields) ? array_values($fields) : [$fields];
        if (empty($fieldArray)) {
            throw new \InvalidArgumentException("Cannot create index without fields.");
        }

        $indexName = $name ?? implode('_', $fieldArray) . ($unique ? '_unique' : '_idx');

        $this->indexDefinitions[$indexName] = $fieldArray;
        $this->indexes[$indexName] = new BTreeIndex($unique);

        return $indexName;
    }

    /**
     * Drop an index by name.
     */
    public function dropIndex(string $name): bool
    {
        if (isset($this->indexes[$name])) {
            unset($this->indexes[$name]);
            unset($this->indexDefinitions[$name]);
            return true;
        }
        return false;
    }

    /**
     * Clear all indexes.
     */
    public function clear(): void
    {
        foreach ($this->indexes as $index) {
            $index->clear();
        }
    }

    /**
     * Check if an index exists for a given field or set of fields.
     *
     * @param string|array<string> $fields
     * @return string|null The name of the matching index, if any
     */
    public function findIndexForFields(string|array $fields): ?string
    {
        $fieldArray = is_array($fields) ? array_values($fields) : [$fields];
        foreach ($this->indexDefinitions as $name => $definedFields) {
            if ($definedFields === $fieldArray) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Get all index definitions.
     *
     * @return array<string, array<string>>
     */
    public function getDefinitions(): array
    {
        return $this->indexDefinitions;
    }

    /**
     * Index a single document.
     *
     * @param int|string $id
     * @param array<string, mixed> $doc
     */
    public function indexDocument(int|string $id, array $doc): void
    {
        foreach ($this->indexes as $name => $index) {
            $fields = $this->indexDefinitions[$name];
            $key = $this->extractKey($doc, $fields);
            $index->insert($key, $id);
        }
    }

    /**
     * Remove a single document from all indexes.
     */
    public function unindexDocument(int|string $id): void
    {
        foreach ($this->indexes as $index) {
            $index->removeById($id);
        }
    }

    /**
     * Rebuild all indexes from scratch given all documents in collection.
     *
     * @param array<int|string, array<string, mixed>> $documents
     */
    public function rebuild(array $documents): void
    {
        foreach ($this->indexes as $index) {
            $index->clear();
        }

        foreach ($documents as $id => $doc) {
            $this->indexDocument($id, $doc);
        }
    }

    /**
     * Query an index by field and operator.
     *
     * @param string|array<string> $fields
     * @param string $operator
     * @param mixed $value
     * @return array<int|string>|null Matching IDs, or null if no index is available
     */
    public function search(string|array $fields, string $operator, mixed $value): ?array
    {
        $indexName = $this->findIndexForFields($fields);
        if ($indexName === null) {
            return null;
        }

        $index = $this->indexes[$indexName];

        if (is_array($fields) && count($fields) > 1 && is_array($value)) {
            // Compound key value
            $value = $this->normalizeCompoundValue($value);
        }

        return $index->search($operator, $value);
    }

    /**
     * Check if a field or compound fields have an active index.
     */
    public function hasIndex(string|array $fields): bool
    {
        return $this->findIndexForFields($fields) !== null;
    }

    /**
     * Extract composite or single key from document.
     */
    private function extractKey(array $doc, array $fields): mixed
    {
        if (count($fields) === 1) {
            return $doc[$fields[0]] ?? null;
        }

        $parts = [];
        foreach ($fields as $f) {
            $parts[] = $doc[$f] ?? null;
        }
        return json_encode($parts);
    }

    private function normalizeCompoundValue(array $values): string
    {
        return json_encode(array_values($values));
    }
}
