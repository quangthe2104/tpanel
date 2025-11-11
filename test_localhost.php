<?php
/**
 * Test file for localhost debugging
 * Truy cập: http://tpanel.test/test_localhost.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Test Localhost Configuration</h1>";

// Test 1: PHP Version
echo "<h2>1. PHP Version</h2>";
echo "Version: " . phpversion() . "<br>";

// Test 2: Config file
echo "<h2>2. Config Files</h2>";
if (file_exists(__DIR__ . '/config/config.php')) {
    echo "✓ config/config.php exists<br>";
    require_once __DIR__ . '/config/config.php';
    echo "BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "<br>";
    echo "ROOT_PATH: " . (defined('ROOT_PATH') ? ROOT_PATH : 'NOT DEFINED') . "<br>";
} else {
    echo "✗ config/config.php NOT FOUND<br>";
}

if (file_exists(__DIR__ . '/config/database.php')) {
    echo "✓ config/database.php exists<br>";
    try {
        $dbConfig = require __DIR__ . '/config/database.php';
        echo "Database config loaded: " . ($dbConfig['dbname'] ?? 'N/A') . "<br>";
    } catch (Exception $e) {
        echo "✗ Error loading database.php: " . $e->getMessage() . "<br>";
    }
} else {
    echo "✗ config/database.php NOT FOUND<br>";
}

// Test 3: Includes
echo "<h2>3. Include Files</h2>";
$includes = [
    'includes/functions.php',
    'includes/Database.php',
    'includes/Auth.php'
];

foreach ($includes as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✓ $file exists<br>";
        try {
            require_once __DIR__ . '/' . $file;
            echo "&nbsp;&nbsp;→ Loaded successfully<br>";
        } catch (Exception $e) {
            echo "&nbsp;&nbsp;✗ Error: " . $e->getMessage() . "<br>";
        } catch (Error $e) {
            echo "&nbsp;&nbsp;✗ Fatal Error: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "✗ $file NOT FOUND<br>";
    }
}

// Test 4: Database Connection
echo "<h2>4. Database Connection</h2>";
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        echo "✓ Database class loaded<br>";
        echo "✓ Database connection successful<br>";
    } else {
        echo "✗ Database class not found<br>";
    }
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "<br>";
} catch (Error $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "<br>";
}

// Test 5: Extensions
echo "<h2>5. PHP Extensions</h2>";
$required = ['pdo', 'pdo_mysql', 'mbstring'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        echo "✓ $ext loaded<br>";
    } else {
        echo "✗ $ext NOT loaded<br>";
    }
}

echo "<hr>";
echo "<p><strong>Nếu thấy lỗi ở đây, đó là nguyên nhân gây Internal Server Error.</strong></p>";
?>
