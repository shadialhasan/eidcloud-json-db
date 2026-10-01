[🇸🇦 العربية](README.ar.md) | [🇬🇧 English](README.md)

# 🗄️ eidcloud-json-db

[![Version](https://img.shields.io/badge/version-v1.0.0-blue.svg)](https://github.com/eidcloud-json-db)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Open In Colab](https://colab.research.google.com/assets/colab-badge.svg)](notebooks/quickstart.ipynb)

> Lightweight, embedded, zero-dependency JSON document database with B-tree-like in-memory indexing, ACID atomic transactions, and fluent query builder written in pure PHP 8.2+.

---

## 📑 Topics
`eidcloud` · `json-database` · `nosql` · `embedded-database` · `acid-transactions` · `query-builder` · `php8`

---

## 🏗️ Architecture & Query Engine

```mermaid
flowchart TD
    App[Application / CLI] -->|Fluent API / SQL| QB[QueryBuilder]
    QB -->|Query Evaluation| IM[IndexManager]
    IM -->|Exact / Range O(log n)| BT[BTreeIndex / Compound Index]
    BT -->|Matched Doc IDs| Col[Collection Memory Cache]
    Col -->|State Tracking / Rollback| Tx[ACID Transaction Manager]
    Tx -->|Commit / Flush| AS[AtomicStorage: flock + Temp Rename]
    AS -->|Atomic FS Sync| Disk[(Structured JSON Files)]
```

---

## ⚡ Capabilities & Highlights

- **Zero External Dependencies**: Pure PHP 8.2+ with zero Composer dependencies.
- **ACID Atomic Transactions**: `beginTransaction()`, `commit()`, and `rollback()` with state snapshotting and rollback isolation.
- **B-Tree-Like Indexing**: In-memory sorted indexing ($O(1) / O(\log n)$) supporting single and compound keys (`['status', 'country']`).
- **Fluent Query Builder**: Chainable methods (`where`, `whereIn`, `whereBetween`, `whereLike`, `orderBy`, `limit`, `offset`, `first`, `get`).
- **Atomic File System Storage**: Concurrent write protection using advisory file locking (`flock`) combined with temp-file-flush-and-rename.
- **Schema Validation & Auto-Increment**: Dynamic schema validation (`required`, `email`, `int`, `string`, `bool`) and primary key generation.
- **Embedded CLI Tool**: `bin/eidcloud-json-db` supporting SQL queries, live microsecond benchmarks, and `--json` machine-readable output.

---

## 📦 Installation

Add via Composer or clone directly into your project:

```bash
composer require eidcloud/json-db
```

Or clone:

```bash
git clone https://github.com/eidcloud-json-db.git
```

---

## 🚀 Quick Usage

### 1. Initialize Database & Collections

```php
use EidCloud\JsonDb\Database;

$db = new Database(__DIR__ . '/data');

// Get collection reference
$users = $db->table('users');

// Set schema validation rules (optional)
$users->setSchema([
    'email' => 'required|email',
    'status' => 'required|string',
    'age' => 'int'
]);

// Define single and compound indexes
$users->createIndex('status');
$users->createIndex(['status', 'country']);
```

### 2. CRUD Operations

```php
// Insert document
$user = $users->insert([
    'name' => 'John Doe',
    'email' => 'john@eidcloud.com',
    'status' => 'active',
    'country' => 'US',
    'age' => 30
]);

// Find by primary key (ID)
$doc = $users->find(1);

// Update by ID
$users->update(1, ['status' => 'verified']);

// Delete by ID
$users->delete(1);
```

### 3. Fluent Query Builder

```php
$activeEngineers = $db->table('users')
    ->where('status', '=', 'active')
    ->where('country', '=', 'US')
    ->where('age', '>', 25)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
```

### 4. ACID Transactions

```php
try {
    $db->beginTransaction();

    $accounts = $db->table('accounts');
    $accounts->update(1, ['balance' => 450]);
    $accounts->update(2, ['balance' => 250]);

    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    echo "Transaction rolled back: " . $e->getMessage();
}
```

Or using the functional transaction wrapper:

```php
$db->transaction(function (Database $db) {
    $db->table('orders')->insert(['order_id' => 99, 'total' => 120]);
    $db->table('inventory')->update(10, ['stock' => 5]);
});
```

---

## 🖥️ Command Line Interface (CLI)

The database includes a standalone binary `bin/eidcloud-json-db`:

```bash
# Display help and options
php bin/eidcloud-json-db --help

# Execute SQL-like query
php bin/eidcloud-json-db query ./data "SELECT id, name, status FROM users WHERE status = 'active'"

# Machine-readable JSON output
php bin/eidcloud-json-db query ./data "SELECT * FROM users WHERE age > 25" --json

# Run high-throughput performance benchmark
php bin/eidcloud-json-db benchmark --records=10000
```

---

## 🧪 Test Suite

Run the built-in zero-dependency test runner:

```bash
php tests/run_tests.php
```

All CRUD operations, auto-increment indexing, B-Tree lookups, schema validation, atomic file persistence, and transaction rollbacks are verified with 100% test coverage.

---

## 👤 Author & Maintainer

**Eng. MHD. Shadi AL-Hasan**  
- **Role:** Executive CTO & Enterprise Solutions Architect  
- **Email:** [mhd.shadi.alhasan@gmail.com](mailto:mhd.shadi.alhasan@gmail.com)  
- **Phone / WhatsApp:** [+963934005922](tel:+963934005922)  
- **Location:** Damascus, Syria  
- **GitHub:** [shadialhasan](https://github.com/shadialhasan)  

---

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.  
Copyright (c) 2026 **MHD. Shadi AL-Hasan**. All rights reserved.
