<?php

declare(strict_types=1);

// Zero-dependency test runner
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\JsonDb\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/JsonDbTest.php';

$testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_test_' . bin2hex(random_bytes(4));
$suite = new EidCloud\JsonDb\Tests\JsonDbTest($testDir);

$success = $suite->runAll();

exit($success ? 0 : 1);
