<?php
// config/database.php
class Database {
    private $host = "localhost";
    private $db_name = "showroom_mobil_bekas";
    private $username = "root";
    private $password = "";
    private $conn;

    public function connect() {
        $this->conn = null;
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $e) {
            echo "Connection error: " . $e->getMessage();
            die();
        }
        
        return $this->conn;
    }

    public function getConnection() {
        if ($this->conn === null) {
            $this->connect();
        }
        return $this->conn;
    }
}

// Database constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'showroom_mobil_bekas');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create global database instance
$database = new Database();
$db = $database->getConnection();
?>