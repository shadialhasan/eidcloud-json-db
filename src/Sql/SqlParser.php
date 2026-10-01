<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Sql;

use EidCloud\JsonDb\Database;
use EidCloud\JsonDb\Exception\DatabaseException;

/**
 * Lightweight SQL query parser for basic SQL operations:
 * SELECT columns FROM table [WHERE conditions] [ORDER BY column [ASC|DESC]] [LIMIT n] [OFFSET n]
 */
class SqlParser
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Execute SQL string.
     *
     * @param string $sql
     * @return array<array<string, mixed>>
     */
    public function query(string $sql): array
    {
        $sql = trim($sql);
        $upper = strtoupper($sql);

        if (str_starts_with($upper, 'SELECT')) {
            return $this->executeSelect($sql);
        }

        throw new DatabaseException("Unsupported SQL statement. Only SELECT is supported via SQL parser.");
    }

    private function executeSelect(string $sql): array
    {
        // Match regex: SELECT (cols) FROM (table) [WHERE (cond)] [ORDER BY (order)] [LIMIT (limit)] [OFFSET (offset)]
        $pattern = '/^SELECT\s+(.+?)\s+FROM\s+([a-zA-Z0-9_\-]+)(?:\s+WHERE\s+(.+?))?(?:\s+ORDER\s+BY\s+(.+?))?(?:\s+LIMIT\s+(\d+))?(?:\s+OFFSET\s+(\d+))?$/i';

        if (!preg_match($pattern, $sql, $matches)) {
            throw new DatabaseException("Malformed SQL query: {$sql}");
        }

        $colsPart = trim($matches[1]);
        $tableName = trim($matches[2]);
        $wherePart = isset($matches[3]) && $matches[3] !== '' ? trim($matches[3]) : null;
        $orderPart = isset($matches[4]) && $matches[4] !== '' ? trim($matches[4]) : null;
        $limitPart = isset($matches[5]) && $matches[5] !== '' ? (int)$matches[5] : null;
        $offsetPart = isset($matches[6]) && $matches[6] !== '' ? (int)$matches[6] : null;

        $table = $this->db->table($tableName);
        $builder = $table->query();

        // Columns
        if ($colsPart !== '*' && $colsPart !== '') {
            $cols = array_map(fn($c) => trim(trim($c), '`"'), explode(',', $colsPart));
            $builder->select(...$cols);
        }

        // Where conditions
        if ($wherePart !== null) {
            $this->parseWhereClause($builder, $wherePart);
        }

        // Order by
        if ($orderPart !== null) {
            $orderSegments = explode(',', $orderPart);
            foreach ($orderSegments as $seg) {
                $seg = trim($seg);
                $parts = preg_split('/\s+/', $seg);
                $col = trim($parts[0], '`"');
                $dir = isset($parts[1]) && strtoupper($parts[1]) === 'DESC' ? 'desc' : 'asc';
                $builder->orderBy($col, $dir);
            }
        }

        // Limit & Offset
        if ($limitPart !== null) {
            $builder->limit($limitPart);
        }
        if ($offsetPart !== null) {
            $builder->offset($offsetPart);
        }

        return $builder->get();
    }

    private function parseWhereClause(object $builder, string $whereStr): void
    {
        // Split by AND / OR (preserving condition tokens)
        // Basic parser supporting 'AND' conditions
        $conditions = preg_split('/\s+AND\s+/i', $whereStr);

        foreach ($conditions as $cond) {
            $cond = trim($cond);
            if (preg_match('/^([a-zA-Z0-9_\-\.]+)\s*(=|!=|<>|>=|<=|>|<|LIKE|IN)\s*(.+)$/i', $cond, $m)) {
                $col = trim($m[1], '`"');
                $op = strtoupper(trim($m[2]));
                $valRaw = trim($m[3]);

                $val = $this->parseValue($op, $valRaw);
                $builder->where($col, $op, $val);
            }
        }
    }

    private function parseValue(string $op, string $raw): mixed
    {
        $raw = trim($raw);

        if ($op === 'IN') {
            $trimmed = trim($raw, '()');
            $parts = explode(',', $trimmed);
            return array_map(fn($p) => $this->parseScalar(trim($p)), $parts);
        }

        return $this->parseScalar($raw);
    }

    private function parseScalar(string $val): mixed
    {
        if (preg_match('/^[\'"](.*)[\'"]$/', $val, $m)) {
            return $m[1];
        }

        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float)$val : (int)$val;
        }

        if (strtoupper($val) === 'TRUE') return true;
        if (strtoupper($val) === 'FALSE') return false;
        if (strtoupper($val) === 'NULL') return null;

        return $val;
    }
}
