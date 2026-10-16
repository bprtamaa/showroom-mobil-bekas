<?php
// database/migrate_v2_features.php
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Vehicle Documents Table
    $sql1 = "
    CREATE TABLE IF NOT EXISTS vehicle_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        stnk_status ENUM('available', 'expired', 'renewal_process', 'released_to_buyer') NOT NULL DEFAULT 'available',
        stnk_expiry_date DATE NULL,
        stnk_tax_date DATE NULL,
        bpkb_status ENUM('in_showroom', 'in_leasing', 'mutasi_process', 'cabut_berkas', 'released_to_buyer') NOT NULL DEFAULT 'in_showroom',
        bpkb_number VARCHAR(50) NULL,
        bpkb_location_notes TEXT NULL,
        faktur_status ENUM('available', 'missing', 'in_leasing', 'released_to_buyer') NOT NULL DEFAULT 'available',
        nik_copy_available TINYINT(1) NOT NULL DEFAULT 1,
        estimated_completion_date DATE NULL,
        agent_service_name VARCHAR(100) NULL,
        tracking_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vehicle_doc (vehicle_id),
        CONSTRAINT fk_doc_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->exec($sql1);
    echo "Table 'vehicle_documents' ready.\n";

    // 2. Leasing Applications Table
    $sql2 = "
    CREATE TABLE IF NOT EXISTS leasing_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        application_code VARCHAR(50) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        leasing_id INT NOT NULL,
        down_payment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        loan_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        tenor_months INT NOT NULL DEFAULT 36,
        monthly_installment DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        application_date DATE NOT NULL,
        survey_date DATE NULL,
        approval_date DATE NULL,
        disbursement_date DATE NULL,
        status ENUM('submitted', 'survey_in_progress', 'approved', 'rejected', 'po_disbursed', 'cancelled') NOT NULL DEFAULT 'submitted',
        surveyor_name VARCHAR(100) NULL,
        surveyor_notes TEXT NULL,
        po_number VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_lease_cust (customer_id),
        INDEX idx_lease_veh (vehicle_id),
        INDEX idx_lease_status (status),
        CONSTRAINT fk_lease_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        CONSTRAINT fk_lease_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        CONSTRAINT fk_lease_inst FOREIGN KEY (leasing_id) REFERENCES leasing(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->exec($sql2);
    echo "Table 'leasing_applications' ready.\n";

    // 3. Trade In Transactions Table
    $sql3 = "
    CREATE TABLE IF NOT EXISTS trade_in_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trade_in_code VARCHAR(50) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        desired_vehicle_id INT NOT NULL,
        desired_vehicle_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        trade_in_model_id INT NULL,
        trade_in_brand_id INT NULL,
        trade_in_brand_name VARCHAR(100) NOT NULL,
        trade_in_model_name VARCHAR(100) NOT NULL,
        trade_in_year INT(4) NOT NULL,
        trade_in_license_plate VARCHAR(20) NOT NULL,
        trade_in_chassis_number VARCHAR(50) NULL,
        trade_in_engine_number VARCHAR(50) NULL,
        trade_in_transmission ENUM('manual', 'automatic', 'cvt') NOT NULL DEFAULT 'automatic',
        trade_in_fuel_type VARCHAR(30) NOT NULL DEFAULT 'gasoline',
        trade_in_color VARCHAR(50) NOT NULL,
        trade_in_mileage INT NOT NULL DEFAULT 0,
        trade_in_condition TEXT NULL,
        appraised_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        price_difference DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        payment_method ENUM('cash', 'bank_transfer', 'leasing') NOT NULL DEFAULT 'cash',
        status ENUM('appraisal', 'approved', 'completed', 'cancelled') NOT NULL DEFAULT 'appraisal',
        converted_vehicle_id INT NULL,
        converted_transaction_id INT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tt_cust (customer_id),
        INDEX idx_tt_veh (desired_vehicle_id),
        INDEX idx_tt_status (status),
        CONSTRAINT fk_tt_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        CONSTRAINT fk_tt_veh FOREIGN KEY (desired_vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->exec($sql3);
    echo "Table 'trade_in_transactions' ready.\n";

} catch (PDOException $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
