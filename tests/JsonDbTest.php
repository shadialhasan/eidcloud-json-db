<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Tests;

use EidCloud\JsonDb\Database;
use EidCloud\JsonDb\Exception\DatabaseException;
use EidCloud\JsonDb\Exception\ValidationException;

class JsonDbTest
{
    private string $testDir;
    private int $passes = 0;
    private int $fails = 0;

    public function __construct(string $testDir)
    {
        $this->testDir = $testDir;
    }

    public function runAll(): bool
    {
        echo "\n🧪 Running EidCloud JSON DB Test Suite...\n";
        echo "========================================\n";

        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (str_starts_with($method, 'test')) {
                $this->setUp();
                try {
                    $this->$method();
                    echo "  ✅ {$method}\n";
                    $this->passes++;
                } catch (\Throwable $e) {
                    echo "  ❌ {$method}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
                    $this->fails++;
                } finally {
                    $this->tearDown();
                }
            }
        }

        echo "========================================\n";
        echo "Summary: {$this->passes} Passed, {$this->fails} Failed\n";
        return $this->fails === 0;
    }

    private function setUp(): void
    {
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    private function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->testDir);
        }
    }

    private function assert(bool $condition, string $msg = 'Assertion failed'): void
    {
        if (!$condition) {
            throw new \RuntimeException($msg);
        }
    }

    private function assertFalse(bool $condition, string $msg = 'Assertion failed'): void
    {
        if ($condition) {
            throw new \RuntimeException($msg);
        }
    }

    private function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            $eStr = var_export($expected, true);
            $aStr = var_export($actual, true);
            throw new \RuntimeException("Failed asserting that {$aStr} is identical to {$eStr}. " . $msg);
        }
    }

    private function assertCount(int $expected, countable|array $array, string $msg = ''): void
    {
        $actual = count($array);
        if ($actual !== $expected) {
            throw new \RuntimeException("Failed asserting that count {$actual} matches expected {$expected}. " . $msg);
        }
    }

    public function testDatabaseInitializationAndCollectionCreation(): void
    {
        $db = new Database($this->testDir);
        $users = $db->table('users');
        $this->assertSame('users', $users->getName());
        $this->assert(in_array('users', $db->listCollections(), true));
    }

    public function testCrudOperations(): void
    {
        $db = new Database($this->testDir);
        $posts = $db->table('posts');

        // Create
        $inserted = $posts->insert(['title' => 'First Post', 'views' => 10]);
        $this->assertSame(1, $inserted['id']);
        $this->assertSame('First Post', $inserted['title']);

        // Read
        $found = $posts->find(1);
        $this->assert($found !== null);
        $this->assertSame('First Post', $found['title']);

        // Update
        $updated = $posts->update(1, ['views' => 25]);
        $this->assertSame(25, $updated['views']);
        $this->assertSame('First Post', $updated['title']);

        // Re-read
        $foundUpdated = $posts->find(1);
        $this->assertSame(25, $foundUpdated['views']);

        // Delete
        $deleted = $posts->delete(1);
        $this->assert($deleted);
        $this->assert($posts->find(1) === null);
        $this->assertSame(0, $posts->count());
    }

    public function testAutoIncrementPrimaryKeys(): void
    {
        $db = new Database($this->testDir);
        $items = $db->table('items');

        $i1 = $items->insert(['name' => 'Item A']);
        $i2 = $items->insert(['name' => 'Item B']);
        $i3 = $items->insert(['name' => 'Item C']);

        $this->assertSame(1, $i1['id']);
        $this->assertSame(2, $i2['id']);
        $this->assertSame(3, $i3['id']);
        $this->assertCount(3, $items->all());
    }

    public function testSchemaValidation(): void
    {
        $db = new Database($this->testDir);
        $users = $db->table('strict_users');
        $users->setSchema([
            'email' => 'required|email',
            'age' => 'required|int',
            'active' => 'bool'
        ]);

        // Valid insert
        $valid = $users->insert(['email' => 'test@example.com', 'age' => 30, 'active' => true]);
        $this->assertSame('test@example.com', $valid['email']);

        // Invalid email
        $emailFailed = false;
        try {
            $users->insert(['email' => 'not-an-email', 'age' => 20]);
        } catch (ValidationException $e) {
            $emailFailed = true;
        }
        $this->assert($emailFailed, "Expected ValidationException for invalid email");

        // Missing required field
        $missingFailed = false;
        try {
            $users->insert(['email' => 'foo@bar.com']);
        } catch (ValidationException $e) {
            $missingFailed = true;
        }
        $this->assert($missingFailed, "Expected ValidationException for missing age");
    }

    public function testSingleIndexQueryAcceleration(): void
    {
        $db = new Database($this->testDir);
        $users = $db->table('indexed_users');
        $users->createIndex('status');
        $users->createIndex('age');

        $users->insert(['name' => 'Alice', 'status' => 'active', 'age' => 25]);
        $users->insert(['name' => 'Bob', 'status' => 'inactive', 'age' => 35]);
        $users->insert(['name' => 'Charlie', 'status' => 'active', 'age' => 45]);
        $users->insert(['name' => 'David', 'status' => 'pending', 'age' => 18]);

        // Query using status index
        $activeUsers = $users->where('status', '=', 'active')->get();
        $this->assertCount(2, $activeUsers);
        $names = array_column($activeUsers, 'name');
        $this->assert(in_array('Alice', $names, true));
        $this->assert(in_array('Charlie', $names, true));

        // Range query on age index
        $olderUsers = $users->where('age', '>=', 35)->get();
        $this->assertCount(2, $olderUsers);
    }

    public function testCompoundIndex(): void
    {
        $db = new Database($this->testDir);
        $orders = $db->table('orders');
        $orders->createIndex(['status', 'currency']);

        $orders->insert(['status' => 'paid', 'currency' => 'USD', 'amount' => 100]);
        $orders->insert(['status' => 'paid', 'currency' => 'EUR', 'amount' => 150]);
        $orders->insert(['status' => 'pending', 'currency' => 'USD', 'amount' => 200]);
        $orders->insert(['status' => 'paid', 'currency' => 'USD', 'amount' => 300]);

        $paidUsd = $orders->where('status', '=', 'paid')->where('currency', '=', 'USD')->get();
        $this->assertCount(2, $paidUsd);
        $this->assertSame(100, $paidUsd[0]['amount']);
        $this->assertSame(300, $paidUsd[1]['amount']);
    }

    public function testFluentQueryBuilderChaining(): void
    {
        $db = new Database($this->testDir);
        $products = $db->table('products');

        $products->insertMany([
            ['name' => 'Laptop', 'category' => 'tech', 'price' => 1200, 'stock' => 10],
            ['name' => 'Phone', 'category' => 'tech', 'price' => 800, 'stock' => 5],
            ['name' => 'Headphones', 'category' => 'audio', 'price' => 200, 'stock' => 0],
            ['name' => 'Monitor', 'category' => 'tech', 'price' => 400, 'stock' => 15],
            ['name' => 'Mouse', 'category' => 'tech', 'price' => 50, 'stock' => 50],
        ]);

        $results = $products->query()
            ->where('category', '=', 'tech')
            ->where('price', '>=', 200)
            ->where('stock', '>', 0)
            ->orderBy('price', 'desc')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('Laptop', $results[0]['name']);
        $this->assertSame('Phone', $results[1]['name']);

        // First
        $first = $products->query()->where('category', 'audio')->first();
        $this->assert($first !== null);
        $this->assertSame('Headphones', $first['name']);

        // Exists
        $this->assert($products->query()->where('name', 'Laptop')->exists());
        $this->assert(!$products->query()->where('name', 'Rocket')->exists());
    }

    public function testAcidTransactionCommit(): void
    {
        $db = new Database($this->testDir);
        $accounts = $db->table('accounts');

        $accounts->insert(['name' => 'User A', 'balance' => 500]);
        $accounts->insert(['name' => 'User B', 'balance' => 200]);

        $db->beginTransaction();
        $accounts->update(1, ['balance' => 400]);
        $accounts->update(2, ['balance' => 300]);
        $db->commit();

        $this->assertSame(400, $accounts->find(1)['balance']);
        $this->assertSame(300, $accounts->find(2)['balance']);
    }

    public function testAcidTransactionRollback(): void
    {
        $db = new Database($this->testDir);
        $accounts = $db->table('rollback_accounts');

        $accounts->insert(['name' => 'User A', 'balance' => 500]);
        $accounts->insert(['name' => 'User B', 'balance' => 200]);

        $db->beginTransaction();
        $accounts->update(1, ['balance' => 100]);
        $accounts->insert(['name' => 'User C', 'balance' => 999]);
        $this->assertSame(100, $accounts->find(1)['balance']);
        $this->assertSame(3, $accounts->count());

        $db->rollback();

        // Must be restored exactly as before
        $this->assertSame(500, $accounts->find(1)['balance']);
        $this->assertSame(200, $accounts->find(2)['balance']);
        $this->assert($accounts->find(3) === null);
        $this->assertSame(2, $accounts->count());
    }

    public function testTransactionCallbackExceptionRollback(): void
    {
        $db = new Database($this->testDir);
        $vault = $db->table('vault');
        $vault->insert(['val' => 'initial']);

        try {
            $db->transaction(function ($database) {
                $v = $database->table('vault');
                $v->insert(['val' => 'during_tx']);
                throw new \Exception("Simulated failure inside transaction");
            });
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertFalse($db->inTransaction());
        $this->assertSame(1, $vault->count());
        $this->assertSame('initial', $vault->find(1)['val']);
    }

    public function testAtomicDiskPersistence(): void
    {
        $db = new Database($this->testDir);
        $col = $db->table('persistence_test');
        $col->insert(['key' => 'secret_val']);

        // Instantiate new DB instance referencing same directory
        $newDbInstance = new Database($this->testDir);
        $persistedCol = $newDbInstance->table('persistence_test');
        $this->assertSame(1, $persistedCol->count());
        $this->assertSame('secret_val', $persistedCol->find(1)['key']);
    }
}
