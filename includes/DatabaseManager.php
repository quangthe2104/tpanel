<?php
class DatabaseManager {
    private $connection;
    private $dbName;
    
    public function __construct($host, $dbname, $username, $password) {
        try {
            $dsn = "mysql:host=$host;charset=utf8mb4";
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->dbName = $dbname;
            if ($dbname) {
                $this->connection->exec("USE `$dbname`");
            }
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getTables() {
        if (!$this->dbName) {
            return [];
        }
        
        $stmt = $this->connection->query("SHOW TABLES");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return $tables;
    }
    
    public function getTableStructure($tableName) {
        $stmt = $this->connection->query("DESCRIBE `$tableName`");
        return $stmt->fetchAll();
    }
    
    public function getTableData($tableName, $limit = 100, $offset = 0) {
        $stmt = $this->connection->prepare("SELECT * FROM `$tableName` LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getTableCount($tableName) {
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `$tableName`");
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    public function getTableSize($tableName) {
        $stmt = $this->connection->prepare(
            "SELECT 
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = ? AND table_name = ?"
        );
        $stmt->execute([$this->dbName, $tableName]);
        $result = $stmt->fetch();
        return $result['size_mb'] ?? 0;
    }
    
    public function executeQuery($query) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute();
            
            if (stripos($query, 'SELECT') === 0) {
                return $stmt->fetchAll();
            } else {
                return ['affected_rows' => $stmt->rowCount()];
            }
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }
    
    public function exportDatabase() {
        if (!$this->dbName) {
            throw new Exception("No database selected");
        }
        
        $output = "-- Database Export: {$this->dbName}\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        // Get all tables
        $tables = $this->getTables();
        
        foreach ($tables as $table) {
            // Export table structure
            $stmt = $this->connection->query("SHOW CREATE TABLE `$table`");
            $createTable = $stmt->fetch();
            $output .= "\n-- Table structure for `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $createTable['Create Table'] . ";\n\n";
            
            // Export table data
            $stmt = $this->connection->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll();
            
            if (count($rows) > 0) {
                $output .= "-- Data for table `$table`\n";
                $output .= "INSERT INTO `$table` VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = $this->connection->quote($value);
                        }
                    }
                    $values[] = '(' . implode(',', $rowValues) . ')';
                }
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        return $output;
    }
    
    public function getDatabaseSize() {
        $stmt = $this->connection->prepare(
            "SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables 
            WHERE table_schema = ?"
        );
        $stmt->execute([$this->dbName]);
        $result = $stmt->fetch();
        return $result['size_mb'] ?? 0;
    }
}
