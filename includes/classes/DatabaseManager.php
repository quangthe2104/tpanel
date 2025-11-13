<?php
class DatabaseManager {
    private $connection;
    private $dbName;
    
    public function __construct($host, $dbname, $username, $password) {
        try {
            // Sanitize inputs - remove dangerous characters
            $host = preg_replace('/[^a-zA-Z0-9.\-_:]/', '', $host);
            $username = preg_replace('/[^a-zA-Z0-9_@.\-]/', '', $username);
            
            $dsn = "mysql:host=$host;charset=utf8mb4";
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Sanitize database name to prevent SQL injection
            if ($dbname) {
                $dbname = preg_replace('/[^a-zA-Z0-9_]/', '', $dbname);
                $this->dbName = $dbname;
                // Use prepared statement for USE command
                $stmt = $this->connection->prepare("USE `" . str_replace('`', '``', $dbname) . "`");
                $stmt->execute();
            } else {
                $this->dbName = null;
            }
        } catch (PDOException $e) {
            $errorCode = $e->getCode();
            $errorMsg = $e->getMessage();
            
            // Get server IP for better error message (try multiple methods)
            $serverIP = $_SERVER['SERVER_ADDR'] ?? 'unknown';
            if ($serverIP === '::1' || $serverIP === '127.0.0.1') {
                // If localhost, try to get real IP from REMOTE_ADDR or other headers
                $serverIP = $_SERVER['REMOTE_ADDR'] ?? 
                           $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
                           $_SERVER['HTTP_X_REAL_IP'] ?? 
                           $serverIP;
            }
            
            // Extract IP from MySQL error message if available
            $mysqlClientIP = 'unknown';
            if (preg_match("/@'([0-9.]+)'/", $errorMsg, $matches)) {
                $mysqlClientIP = $matches[1];
            }
            
            // Enhanced error message
            if (strpos($errorMsg, 'Access denied') !== false) {
                $errorMsg = "Database connection failed: " . $errorMsg;
                $errorMsg .= "\n\n💡 Giải pháp:";
                $errorMsg .= "\n1. Vào phpMyAdmin của hosting";
                $errorMsg .= "\n2. Tab 'User accounts' → Tìm user '{$username}'";
                $errorMsg .= "\n3. Click 'Edit privileges'";
                $errorMsg .= "\n4. Sửa 'Host' thành: '{$mysqlClientIP}' hoặc '%' (cho phép từ mọi IP)";
                $errorMsg .= "\n5. Click 'Go' để lưu";
                $errorMsg .= "\n\nIP của server Tpanel (từ MySQL): {$mysqlClientIP}";
                if ($serverIP !== $mysqlClientIP && $serverIP !== 'unknown') {
                    $errorMsg .= "\nIP của server Tpanel (từ PHP): {$serverIP}";
                }
            } else {
                $errorMsg = "Database connection failed: " . $errorMsg;
            }
            
            throw new Exception($errorMsg);
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
        // Sanitize table name to prevent SQL injection
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $stmt = $this->connection->query("DESCRIBE `" . str_replace('`', '``', $tableName) . "`");
        return $stmt->fetchAll();
    }
    
    public function getTableData($tableName, $limit = 100, $offset = 0) {
        // Sanitize table name to prevent SQL injection
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $stmt = $this->connection->prepare("SELECT * FROM `" . str_replace('`', '``', $tableName) . "` LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getTableCount($tableName) {
        // Sanitize table name to prevent SQL injection
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM `" . str_replace('`', '``', $tableName) . "`");
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }
    
    public function getTableSize($tableName) {
        // Sanitize table name to prevent SQL injection
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $this->dbName ?? '');
        $stmt = $this->connection->prepare(
            "SELECT 
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES 
            WHERE table_schema = ? AND table_name = ?"
        );
        $stmt->execute([$dbName, $tableName]);
        $result = $stmt->fetch();
        return $result['size_mb'] ?? 0;
    }
    
    public function executeQuery($query) {
        try {
            // Chỉ cho phép SELECT queries để bảo mật
            $trimmedQuery = trim($query);
            if (stripos($trimmedQuery, 'SELECT') !== 0) {
                throw new Exception("Chỉ cho phép thực thi SELECT queries");
            }
            
            $stmt = $this->connection->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll();
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
