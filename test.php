<?php
/**
 * Test file to check for errors
 * Access this file directly to see if there are any PHP errors
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "PHP Version: " . phpversion() . "<br>";
echo "Current directory: " . __DIR__ . "<br>";
echo "ROOT_PATH: " . (defined('ROOT_PATH') ? ROOT_PATH : 'NOT DEFINED') . "<br>";

// Test require config
try {
    require_once __DIR__ . '/config/config.php';
    echo "✓ Config loaded successfully<br>";
    echo "BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "<br>";
} catch (Exception $e) {
    echo "✗ Error loading config: " . $e->getMessage() . "<br>";
}

// Test require functions
try {
    require_once __DIR__ . '/includes/helpers/functions.php';
    echo "✓ Functions loaded successfully<br>";
} catch (Exception $e) {
    echo "✗ Error loading functions: " . $e->getMessage() . "<br>";
}

// Test Database class
try {
    $db = Database::getInstance();
    echo "✓ Database connection successful<br>";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
}

echo "<br>All tests completed!";

