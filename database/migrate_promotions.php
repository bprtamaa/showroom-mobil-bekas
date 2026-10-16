<?php
// database/migrate_promotions.php
require_once __DIR__ . '/../config/database.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS promotions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        promo_code VARCHAR(50) NULL,
        promo_badge VARCHAR(50) NOT NULL DEFAULT 'DISKON SPESIAL',
        discount_type ENUM('fixed', 'percentage') NOT NULL DEFAULT 'fixed',
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        target_scope ENUM('all_vehicles', 'selected_vehicles', 'selected_brands') NOT NULL DEFAULT 'all_vehicles',
        target_ids TEXT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        banner_text VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_promo_status (status),
        INDEX idx_promo_dates (start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $db->exec($sql);
    echo "Table 'promotions' successfully created/verified.\n";

} catch (PDOException $e) {
    echo "Error migrating promotions: " . $e->getMessage() . "\n";
}
