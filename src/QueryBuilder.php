<?php

declare(strict_types=1);

namespace EidCloud\JsonDb;

use Closure;

/**
 * Fluent query builder for Collections with index acceleration.
 */
class QueryBuilder
{
    private Collection $collection;

    /**
     * Where conditions: [['column' => ..., 'operator' => ..., 'value' => ..., 'boolean' => 'AND'|'OR']]
     * @var array<int, array{column: string|array, operator: string, value: mixed, boolean: string}>
     */
    private array $wheres = [];

    /**
     * Order by definitions: [['column' => ..., 'direction' => 'asc'|'desc']]
     * @var array<int, array{column: string, direction: string}>
     */
    private array $orders = [];

    private ?int $limitCount = null;
    private int $offsetCount = 0;

    /**
     * Selected fields/columns (empty array means all fields)
     * @var array<string>
     */
    private array $selectedColumns = [];

    public function __construct(Collection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Select specific columns.
     *
     * @param string ...$columns
     * @return self
     */
    public function select(string ...$columns): self
    {
        $this->selectedColumns = $columns;
        return $this;
    }

    /**
     * Add a WHERE condition.
     *
     * Examples:
     * $q->where('status', '=', 'active');
     * $q->where('age', '>', 25);
     * $q->where('status', 'active'); // shorthand for '='
     * $q->where(['status' => 'active', 'role' => 'admin']); // compound or multiple
     *
     * @param string|array<string, mixed> $column
     * @param mixed $operator
     * @param mixed $value
     * @return self
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, '=', $val);
            }
            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => (string)$operator,
            'value' => $value,
            'boolean' => 'AND'
        ];

        return $this;
    }

    /**
     * Add an OR WHERE condition.
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => (string)$operator,
            'value' => $value,
            'boolean' => 'OR'
        ];

        return $this;
    }

    /**
     * Add WHERE IN condition.
     *
     * @param string $column
     * @param array $values
     * @return self
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->where($column, 'IN', $values);
    }

    /**
     * Add WHERE NOT IN condition.
     *
     * @param string $column
     * @param array $values
     * @return self
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->where($column, 'NOT IN', $values);
    }

    /**
     * Add WHERE BETWEEN condition.
     *
     * @param string $column
     * @param array{0: mixed, 1: mixed} $range
     * @return self
     */
    public function whereBetween(string $column, array $range): self
    {
        $this->where($column, '>=', $range[0]);
        return $this->where($column, '<=', $range[1]);
    }

    /**
     * Add WHERE LIKE condition (SQL like '%' wildcard).
     */
    public function whereLike(string $column, string $pattern): self
    {
        return $this->where($column, 'LIKE', $pattern);
    }

    /**
     * Order by field and direction.
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->orders[] = [
            'column' => $column,
            'direction' => $dir
        ];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitCount = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetCount = max(0, $offset);
        return $this;
    }

    /**
     * Execute query and get result set as array of documents.
     *
     * @return array<array<string, mixed>>
     */
    public function get(): array
    {
        $allDocs = $this->collection->getDocuments();
        $indexManager = $this->collection->getIndexManager();

        // 1. Candidate ID evaluation: Check if any indexed condition can narrow down the candidates
        $candidateIds = null;

        // Try compound index matching if multiple AND wheres exist
        $andWheres = array_filter($this->wheres, fn($w) => $w['boolean'] === 'AND' && in_array(strtoupper($w['operator']), ['=', '==']));
        if (count($andWheres) > 1) {
            $cols = array_map(fn($w) => $w['column'], $andWheres);
            $idxName = $indexManager->findIndexForFields($cols);
            if ($idxName !== null) {
                $vals = array_map(fn($w) => $w['value'], $andWheres);
                $ids = $indexManager->search($cols, '=', $vals);
                if ($ids !== null) {
                    $candidateIds = $ids;
                }
            }
        }

        // Single index candidate search
        if ($candidateIds === null) {
            foreach ($this->wheres as $w) {
                if ($w['boolean'] === 'AND' && is_string($w['column'])) {
                    if ($indexManager->hasIndex($w['column'])) {
                        $matchedIds = $indexManager->search($w['column'], $w['operator'], $w['value']);
                        if ($matchedIds !== null) {
                            if ($candidateIds === null) {
                                $candidateIds = $matchedIds;
                            } else {
                                $candidateIds = array_values(array_intersect($candidateIds, $matchedIds));
                            }
                        }
                    }
                }
            }
        }

        // 2. Filter records
        $results = [];

        if ($candidateIds !== null) {
            // Evaluated via index!
            foreach ($candidateIds as $id) {
                if (isset($allDocs[$id])) {
                    $doc = $allDocs[$id];
                    if ($this->matchesAllWheres($doc)) {
                        $results[] = $doc;
                    }
                }
            }
        } else {
            // Full collection scan
            foreach ($allDocs as $doc) {
                if ($this->matchesAllWheres($doc)) {
                    $results[] = $doc;
                }
            }
        }

        // 3. Sorting
        if (!empty($this->orders)) {
            usort($results, function ($a, $b) {
                foreach ($this->orders as $order) {
                    $col = $order['column'];
                    $dir = $order['direction'];
                    $valA = $a[$col] ?? null;
                    $valB = $b[$col] ?? null;

                    $cmp = $this->compareValues($valA, $valB);
                    if ($cmp !== 0) {
                        return $dir === 'desc' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }

        // 4. Offset & Limit
        if ($this->offsetCount > 0 || $this->limitCount !== null) {
            $results = array_slice($results, $this->offsetCount, $this->limitCount);
        }

        // 5. Select projected columns if requested
        if (!empty($this->selectedColumns)) {
            $results = array_map(function ($doc) {
                $projected = [];
                foreach ($this->selectedColumns as $col) {
                    $projected[$col] = $doc[$col] ?? null;
                }
                return $projected;
            }, $results);
        }

        return $results;
    }

    /**
     * Get first matching document or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);
        $results = $clone->get();
        return $results[0] ?? null;
    }

    /**
     * Get count of matching documents.
     */
    public function count(): int
    {
        return count($this->get());
    }

    /**
     * Check if any matching document exists.
     */
    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * Bulk update matching documents.
     *
     * @param array<string, mixed> $values
     * @return int Count of updated records
     */
    public function update(array $values): int
    {
        $records = $this->get();
        $pk = $this->collection->getPrimaryKey();
        $count = 0;

        foreach ($records as $record) {
            if (isset($record[$pk])) {
                $this->collection->update($record[$pk], $values);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk delete matching documents.
     *
     * @return int Count of deleted records
     */
    public function delete(): int
    {
        $records = $this->get();
        $pk = $this->collection->getPrimaryKey();
        $count = 0;

        foreach ($records as $record) {
            if (isset($record[$pk])) {
                $this->collection->delete($record[$pk]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Determine if a document matches all where criteria.
     */
    private function matchesAllWheres(array $doc): bool
    {
        if (empty($this->wheres)) {
            return true;
        }

        $match = true;

        foreach ($this->wheres as $w) {
            $col = $w['column'];
            $op = strtoupper(trim($w['operator']));
            $expected = $w['value'];
            $actual = is_string($col) ? ($doc[$col] ?? null) : null;

            $condMatch = $this->evalCondition($actual, $op, $expected);

            if ($w['boolean'] === 'OR') {
                $match = $match || $condMatch;
            } else {
                $match = $match && $condMatch;
            }
        }

        return $match;
    }

    private function evalCondition(mixed $actual, string $op, mixed $expected): bool
    {
        switch ($op) {
            case '=':
            case '==':
                return $this->compareValues($actual, $expected) === 0;

            case '!=':
            case '<>':
                return $this->compareValues($actual, $expected) !== 0;

            case '>':
                return $this->compareValues($actual, $expected) > 0;

            case '>=':
                return $this->compareValues($actual, $expected) >= 0;

            case '<':
                return $this->compareValues($actual, $expected) < 0;

            case '<=':
                return $this->compareValues($actual, $expected) <= 0;

            case 'IN':
                if (!is_array($expected)) {
                    return false;
                }
                foreach ($expected as $item) {
                    if ($this->compareValues($actual, $item) === 0) {
                        return true;
                    }
                }
                return false;

            case 'NOT IN':
                if (!is_array($expected)) {
                    return true;
                }
                foreach ($expected as $item) {
                    if ($this->compareValues($actual, $item) === 0) {
                        return false;
                    }
                }
                return true;

            case 'LIKE':
                if (!is_string($actual) || !is_string($expected)) {
                    return false;
                }
                $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($expected, '/')) . '$/i';
                return (bool)preg_match($regex, $actual);

            default:
                return false;
        }
    }

    private function compareValues(mixed $a, mixed $b): int
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
            if ($diff < 0) return -1;
            if ($diff > 0) return 1;
            return 0;
        }

        if (is_string($a) || is_string($b)) {
            return strcmp((string)$a, (string)$b);
        }

        return $a <=> $b;
    }
}
