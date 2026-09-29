<?php
// Tiny test runner — no PHPUnit on the router or in CI for this project.
//
// Loads every tests/php/*_test.php, then calls every function named
// test_* that those files defined (in file order, then definition order).
// Each test uses eq() to assert; a thrown exception fails that test only.
//
// Usage: php tests/php/run.php

declare(strict_types=1);

/**
 * Assert $actual === $expected, otherwise fail the currently running test.
 */
function eq($actual, $expected, string $msg): void {
    if ($actual !== $expected) {
        throw new RuntimeException(
            $msg . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$before = get_defined_functions()['user'];

$files = glob(__DIR__ . '/*_test.php');
sort($files);
foreach ($files as $file) {
    require $file;
}

$after = get_defined_functions()['user'];
$test_functions = array_values(array_filter(
    array_diff($after, $before),
    fn($fn) => strpos($fn, 'test_') === 0
));
sort($test_functions);

$failures = 0;
foreach ($test_functions as $fn) {
    try {
        $fn();
        echo "PASS $fn\n";
    } catch (Throwable $e) {
        echo "FAIL $fn: " . $e->getMessage() . "\n";
        $failures++;
    }
}

printf("\n%d passed, %d failed\n", count($test_functions) - $failures, $failures);

exit($failures > 0 ? 1 : 0);
