<?php
require_once __DIR__ . '/../includes/helpers/functions.php';

$db = Database::getInstance();

try {
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/migration_add_url_field.sql');
    
    // Split by semicolon to execute multiple statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        echo "Executing: " . substr($statement, 0, 100) . "...\n";
        $db->query($statement);
    }
    
    echo "\n✓ Migration completed successfully!\n";
    echo "Column 'url' has been added to 'websites' table.\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

