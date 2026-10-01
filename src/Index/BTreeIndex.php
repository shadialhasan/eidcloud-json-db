<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Index;

/**
 * In-memory B-tree-like ordered map for range and exact match indexing.
 * Uses a self-balancing sorted array with binary search (bisect) for O(log n)
 * search, insertion, and prefix/range scans.
 */
class BTreeIndex
{
    /**
     * Sorted entries: each entry is ['key' => mixed, 'ids' => int[]|string[]]
     * @var array<int, array{key: mixed, ids: array<int|string>}>
     */
    private array $entries = [];

    /**
     * Map from document ID to key for quick reverse lookup during update/delete.
     * @var array<int|string, mixed>
     */
    private array $idToKey = [];

    /**
     * Whether this index enforces uniqueness.
     */
    private bool $unique;

    public function __construct(bool $unique = false)
    {
        $this->unique = $unique;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Clear index data.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->idToKey = [];
    }

    /**
     * Insert or update an ID with a given key.
     *
     * @param mixed $key
     * @param int|string $id
     * @return bool
     */
    public function insert(mixed $key, int|string $id): bool
    {
        if ($key === null) {
            // Null keys are still recorded for existence or skipped depending on design.
            // We index null as special representation.
        }

        // If ID previously indexed under different key, remove old mapping
        if (array_key_exists($id, $this->idToKey)) {
            $this->remove($this->idToKey[$id], $id);
        }

        $pos = $this->binarySearch($key);

        if ($pos >= 0) {
            // Key already exists
            if ($this->unique && !empty($this->entries[$pos]['ids']) && !in_array($id, $this->entries[$pos]['ids'], true)) {
                throw new \RuntimeException("Unique constraint violation for key: " . json_encode($key));
            }

            if (!in_array($id, $this->entries[$pos]['ids'], true)) {
                $this->entries[$pos]['ids'][] = $id;
            }
        } else {
            // Key does not exist; insert at insertion point
            $insertIdx = -$pos - 1;
            array_splice($this->entries, $insertIdx, 0, [[
                'key' => $key,
                'ids' => [$id]
            ]]);
        }

        $this->idToKey[$id] = $key;
        return true;
    }

    /**
     * Remove an ID from the index.
     */
    public function remove(mixed $key, int|string $id): bool
    {
        $pos = $this->binarySearch($key);
        if ($pos < 0) {
            return false;
        }

        $ids = &$this->entries[$pos]['ids'];
        $foundKey = array_search($id, $ids, true);
        if ($foundKey !== false) {
            array_splice($ids, $foundKey, 1);
            unset($this->idToKey[$id]);

            if (empty($ids)) {
                array_splice($this->entries, $pos, 1);
            }
            return true;
        }

        return false;
    }

    /**
     * Remove by ID only.
     */
    public function removeById(int|string $id): bool
    {
        if (!array_key_exists($id, $this->idToKey)) {
            return false;
        }
        $key = $this->idToKey[$id];
        return $this->remove($key, $id);
    }

    /**
     * Search IDs matching exact key. O(log n)
     *
     * @param mixed $key
     * @return array<int|string>
     */
    public function find(mixed $key): array
    {
        $pos = $this->binarySearch($key);
        if ($pos >= 0) {
            return $this->entries[$pos]['ids'];
        }
        return [];
    }

    /**
     * Range search based on operator.
     * Supports: '=', '==', '!=', '<>', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'BETWEEN'
     *
     * @param string $operator
     * @param mixed $value
     * @return array<int|string>
     */
    public function search(string $operator, mixed $value): array
    {
        $op = strtoupper(trim($operator));
        $result = [];

        switch ($op) {
            case '=':
            case '==':
                return $this->find($value);

            case '!=':
            case '<>':
                foreach ($this->entries as $entry) {
                    if ($this->compare($entry['key'], $value) !== 0) {
                        foreach ($entry['ids'] as $id) {
                            $result[] = $id;
                        }
                    }
                }
                return $result;

            case '>':
                $startIdx = $this->findFirstGreaterThan($value);
                for ($i = $startIdx; $i < count($this->entries); $i++) {
                    foreach ($this->entries[$i]['ids'] as $id) {
                        $result[] = $id;
                    }
                }
                return $result;

            case '>=':
                $startIdx = $this->findFirstGreaterOrEqual($value);
                for ($i = $startIdx; $i < count($this->entries); $i++) {
                    foreach ($this->entries[$i]['ids'] as $id) {
                        $result[] = $id;
                    }
                }
                return $result;

            case '<':
                $endIdx = $this->findFirstGreaterOrEqual($value);
                for ($i = 0; $i < $endIdx; $i++) {
                    foreach ($this->entries[$i]['ids'] as $id) {
                        $result[] = $id;
                    }
                }
                return $result;

            case '<=':
                $endIdx = $this->findFirstGreaterThan($value);
                for ($i = 0; $i < $endIdx; $i++) {
                    foreach ($this->entries[$i]['ids'] as $id) {
                        $result[] = $id;
                    }
                }
                return $result;

            case 'IN':
                if (is_array($value)) {
                    foreach ($value as $val) {
                        foreach ($this->find($val) as $id) {
                            $result[] = $id;
                        }
                    }
                }
                return array_values(array_unique($result));

            case 'NOT IN':
                if (is_array($value)) {
                    $valSet = array_flip(array_map('strval', $value));
                    foreach ($this->entries as $entry) {
                        if (!isset($valSet[(string)$entry['key']])) {
                            foreach ($entry['ids'] as $id) {
                                $result[] = $id;
                            }
                        }
                    }
                }
                return $result;

            default:
                throw new \InvalidArgumentException("Unsupported index search operator: {$operator}");
        }
    }

    /**
     * Range search between min and max inclusive.
     *
     * @param mixed $min
     * @param mixed $max
     * @return array<int|string>
     */
    public function findBetween(mixed $min, mixed $max): array
    {
        $startIdx = $this->findFirstGreaterOrEqual($min);
        $endIdx = $this->findFirstGreaterThan($max);
        $result = [];

        for ($i = $startIdx; $i < $endIdx; $i++) {
            foreach ($this->entries[$i]['ids'] as $id) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * Binary search for exact key.
     * Returns >= 0 (index of matching element) or negative number (-insertionPoint - 1).
     */
    private function binarySearch(mixed $key): int
    {
        $low = 0;
        $high = count($this->entries) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $cmp = $this->compare($this->entries[$mid]['key'], $key);

            if ($cmp < 0) {
                $low = $mid + 1;
            } elseif ($cmp > 0) {
                $high = $mid - 1;
            } else {
                return $mid;
            }
        }

        return -$low - 1;
    }

    private function findFirstGreaterOrEqual(mixed $key): int
    {
        $low = 0;
        $high = count($this->entries) - 1;
        $ans = count($this->entries);

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $cmp = $this->compare($this->entries[$mid]['key'], $key);

            if ($cmp >= 0) {
                $ans = $mid;
                $high = $mid - 1;
            } else {
                $low = $mid + 1;
            }
        }

        return $ans;
    }

    private function findFirstGreaterThan(mixed $key): int
    {
        $low = 0;
        $high = count($this->entries) - 1;
        $ans = count($this->entries);

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $cmp = $this->compare($this->entries[$mid]['key'], $key);

            if ($cmp > 0) {
                $ans = $mid;
                $high = $mid - 1;
            } else {
                $low = $mid + 1;
            }
        }

        return $ans;
    }

    /**
     * Consistent comparator for numbers, strings, and mixed types.
     */
    private function compare(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return -1;
        }
        if ($b === null) {
            return 1;
        }

        if (is_numeric($a) && is_numeric($b)) {
            $diff = (float)$a - (float)$b;
            if ($diff < 0) {
                return -1;
            }
            if ($diff > 0) {
                return 1;
            }
            return 0;
        }

        if (is_string($a) || is_string($b)) {
            return strcmp((string)$a, (string)$b);
        }

        return $a <=> $b;
    }

    /**
     * Get count of unique keys.
     */
    public function count(): int
    {
        return count($this->entries);
    }
}
