<?php
// database/migrate_inspections.php
require_once __DIR__ . '/../config/database.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS vehicle_inspections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        inspector_id INT NULL,
        inspector_name VARCHAR(100) NOT NULL,
        inspection_date DATE NOT NULL,
        overall_grade ENUM('A+', 'A', 'B', 'C', 'D') NOT NULL DEFAULT 'B',
        overall_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        flood_free TINYINT(1) NOT NULL DEFAULT 1,
        accident_free TINYINT(1) NOT NULL DEFAULT 1,
        odometer_tampered TINYINT(1) NOT NULL DEFAULT 0,
        fire_free TINYINT(1) NOT NULL DEFAULT 1,
        frame_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        engine_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        exterior_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        interior_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        road_test_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        summary_notes TEXT NULL,
        checklist_data LONGTEXT NULL,
        status ENUM('draft', 'completed') NOT NULL DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vehicle (vehicle_id),
        INDEX idx_grade (overall_grade),
        CONSTRAINT fk_inspection_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $db->exec($sql);
    echo "Table 'vehicle_inspections' successfully created or already exists.\n";
} catch (PDOException $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
