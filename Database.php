<?php
/**
 * Database Connection Class
 * Handles MariaDB connections using PDO with connection pooling
 * 
 * Usage:
 * $db = Database::getInstance();
 * $conn = $db->getConnection();
 */

class Database {
    // Singleton instance
    private static $instance = null;
    private $connection = null;
    
    // Database credentials - UPDATE THESE!
    private $host = '127.0.0.1';  // Use 127.0.0.1 instead of localhost for Plesk/Windows
    private $database = 'employee_scheduling2';
    private $username = 'schedule_app';
    private $password = 'Abc1165de@#';
    private $charset = 'utf8mb4';
    
    // PDO options
    private $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,  // Connection pooling
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
    ];
    
    /**
     * Private constructor (Singleton pattern)
     */
    private function __construct() {
        // Load config from db_config.php if it exists
        $configFile = __DIR__ . '/db_config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->host = $config['host'] ?? $this->host;
            $this->database = $config['database'] ?? $this->database;
            $this->username = $config['username'] ?? $this->username;
            $this->password = $config['password'] ?? $this->password;
            $this->charset = $config['charset'] ?? $this->charset;
        }
        
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
            $this->connection = new PDO($dsn, $this->username, $this->password, $this->options);
            
            // Set timezone
            $this->connection->exec("SET time_zone = '+00:00'");
            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check your configuration.");
        }
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        // Check if connection is still alive
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }
    
    /**
     * Execute a query and return results
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Execute INSERT and return last insert ID
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->connection->lastInsertId();
    }
    
    /**
     * Execute UPDATE/DELETE and return affected rows
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Check if connection is alive
     */
    public function isConnected() {
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Close connection
     */
    public function close() {
        $this->connection = null;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Database configuration loader
 * Loads credentials from environment or config file
 */
class DatabaseConfig {
    
    /**
     * Load configuration from file or environment
     */
    public static function loadConfig() {
        // Try to load from config file
        $configFile = __DIR__ . '/db_config.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        
        // Fallback to environment variables
        return [
            'host'     => getenv('DB_HOST') ?: 'localhost',
            'database' => getenv('DB_NAME') ?: 'employee_scheduling',
            'username' => getenv('DB_USER') ?: 'schedule_app',
            'password' => getenv('DB_PASS') ?: '',
            'charset'  => 'utf8mb4'
        ];
    }
    
    /**
     * Test database connection
     */
    public static function testConnection() {
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();
            
            // Test query
            $result = $conn->query("SELECT VERSION() as version")->fetch();
            
            return [
                'success' => true,
                'version' => $result['version'],
                'message' => 'Database connection successful!'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

// Example db_config.php file (create this separately):
/*
<?php
return [
    'host'     => 'localhost',
    'database' => 'employee_scheduling',
    'username' => 'schedule_app',
    'password' => 'your_secure_password_here',
    'charset'  => 'utf8mb4'
];
*/
