<?php
// database/migrate_consignments.php
require_once __DIR__ . '/../config/database.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS consignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consignment_code VARCHAR(50) NOT NULL UNIQUE,
        owner_name VARCHAR(100) NOT NULL,
        owner_phone VARCHAR(30) NOT NULL,
        owner_id_card VARCHAR(50) NULL,
        owner_address TEXT NULL,
        owner_bank_name VARCHAR(50) NULL,
        owner_bank_account VARCHAR(50) NULL,
        owner_bank_holder VARCHAR(100) NULL,
        brand_id INT NULL,
        brand_name VARCHAR(100) NOT NULL,
        model_name VARCHAR(100) NOT NULL,
        year INT(4) NOT NULL,
        color VARCHAR(50) NOT NULL,
        license_plate VARCHAR(20) NOT NULL,
        chassis_number VARCHAR(50) NULL,
        engine_number VARCHAR(50) NULL,
        transmission ENUM('manual', 'automatic', 'cvt') NOT NULL DEFAULT 'automatic',
        fuel_type VARCHAR(30) NOT NULL DEFAULT 'Bensin',
        mileage INT NOT NULL DEFAULT 0,
        net_price_to_owner DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        target_selling_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        commission_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('active', 'sold', 'returned', 'cancelled') NOT NULL DEFAULT 'active',
        settlement_status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
        settlement_date DATE NULL,
        agreement_notes TEXT NULL,
        photos TEXT NULL,
        vehicle_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_csg_status (status),
        INDEX idx_csg_end_date (end_date),
        INDEX idx_csg_plate (license_plate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $db->exec($sql);
    echo "Table 'consignments' successfully created/verified.\n";

    // 1. Add vehicle_id to consignments if not exists
    $colsC = $db->query("DESCRIBE consignments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vehicle_id', $colsC)) {
        $db->exec("ALTER TABLE consignments ADD COLUMN vehicle_id INT NULL AFTER photos");
        echo "Column 'vehicle_id' added to 'consignments'.\n";
    }

    // 2. Add is_consignment and consignment_id to vehicles if not exists
    $colsV = $db->query("DESCRIBE vehicles")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_consignment', $colsV)) {
        $db->exec("ALTER TABLE vehicles ADD COLUMN is_consignment TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        echo "Column 'is_consignment' added to 'vehicles'.\n";
    }
    if (!in_array('consignment_id', $colsV)) {
        $db->exec("ALTER TABLE vehicles ADD COLUMN consignment_id INT NULL AFTER is_consignment");
        echo "Column 'consignment_id' added to 'vehicles'.\n";
    }

    // 3. Sync existing consignments to vehicles if not already synced
    $stmtCsg = $db->query("SELECT * FROM consignments WHERE vehicle_id IS NULL OR vehicle_id = 0");
    $unsynced = $stmtCsg->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unsynced as $c) {
        // Find or create model
        $modelId = null;
        if (!empty($c['brand_id'])) {
            $stmtM = $db->prepare("SELECT id FROM models WHERE brand_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmtM->execute([$c['brand_id'], $c['model_name']]);
            $modelId = $stmtM->fetchColumn();
        }

        if (!$modelId) {
            // Find default car_type or 1
            $stmtCT = $db->query("SELECT id FROM car_types LIMIT 1");
            $defaultCarType = $stmtCT->fetchColumn() ?: 1;
            $stmtInsM = $db->prepare("INSERT INTO models (brand_id, name, car_type_id, status, created_at, updated_at) VALUES (?, ?, ?, 'active', NOW(), NOW())");
            $stmtInsM->execute([$c['brand_id'] ?: 1, $c['model_name'], $defaultCarType]);
            $modelId = $db->lastInsertId();
        }

        $chassis = !empty($c['chassis_number']) ? $c['chassis_number'] : ('CSG-VIN-' . strtoupper(substr(uniqid(), -6)));
        $engine = !empty($c['engine_number']) ? $c['engine_number'] : ('CSG-ENG-' . strtoupper(substr(uniqid(), -6)));

        // Map fuel type to enum: gasoline, petrol, diesel, hybrid, electric
        $fuelMap = 'gasoline';
        $fLower = strtolower($c['fuel_type']);
        if (strpos($fLower, 'diesel') !== false || strpos($fLower, 'solar') !== false) {
            $fuelMap = 'diesel';
        } elseif (strpos($fLower, 'hybrid') !== false) {
            $fuelMap = 'hybrid';
        } elseif (strpos($fLower, 'electric') !== false || strpos($fLower, 'listrik') !== false) {
            $fuelMap = 'electric';
        }

        $transMap = in_array($c['transmission'], ['manual', 'automatic', 'cvt']) ? $c['transmission'] : 'automatic';
        $vStatus = ($c['status'] === 'active') ? 'available' : ($c['status'] === 'sold' ? 'sold' : 'maintenance');

        // Check if vehicle with plate already exists
        $stmtVCheck = $db->prepare("SELECT id FROM vehicles WHERE license_plate = ? LIMIT 1");
        $stmtVCheck->execute([$c['license_plate']]);
        $existingVehId = $stmtVCheck->fetchColumn();

        if ($existingVehId) {
            $db->prepare("UPDATE vehicles SET is_consignment = 1, consignment_id = ? WHERE id = ?")->execute([$c['id'], $existingVehId]);
            $db->prepare("UPDATE consignments SET vehicle_id = ? WHERE id = ?")->execute([$existingVehId, $c['id']]);
            echo "Linked existing vehicle #{$existingVehId} to consignment #{$c['id']}.\n";
        } else {
            $stmtInsV = $db->prepare("
                INSERT INTO vehicles (
                    model_id, year, chassis_number, engine_number, license_plate, color,
                    mileage, fuel_type, transmission, purchase_price, selling_price,
                    purchase_date, condition_rating, notes, images, status,
                    is_consignment, consignment_id, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, 'good', ?, ?, ?,
                    1, ?, NOW(), NOW()
                )
            ");
            $notes = 'Mobil Titip Jual dari ' . $c['owner_name'] . ' (' . $c['owner_phone'] . '). ' . $c['agreement_notes'];
            $stmtInsV->execute([
                $modelId,
                $c['year'],
                $chassis,
                $engine,
                $c['license_plate'],
                $c['color'],
                $c['mileage'],
                $fuelMap,
                $transMap,
                $c['net_price_to_owner'],
                $c['target_selling_price'],
                $c['start_date'],
                $notes,
                $c['photos'],
                $vStatus,
                $c['id']
            ]);
            $newVehId = $db->lastInsertId();
            $db->prepare("UPDATE consignments SET vehicle_id = ? WHERE id = ?")->execute([$newVehId, $c['id']]);
            echo "Created new vehicle stock #{$newVehId} for consignment #{$c['id']} ({$c['consignment_code']}).\n";
        }
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    echo "Error migrating consignments: " . $e->getMessage() . "\n";
}
