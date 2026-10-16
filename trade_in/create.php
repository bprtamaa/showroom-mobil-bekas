<?php
// trade_in/create.php - Formulir Tukar Tambah & Appraisal Kendaraan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// AJAX Handler for Adding Model
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_add_model') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Error'];
    
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['user_id'])) {
            throw new Exception("Sesi telah berakhir, silakan login kembali.");
        }

        if (!function_exists('sanitizeInput')) {
            function sanitizeInput($s) { return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8'); }
        }
        
        $name = sanitizeInput($_POST['name'] ?? '');
        $brand_id = (int)($_POST['brand_id'] ?? 0);
        $car_type_id = (int)($_POST['car_type_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name) || empty($brand_id) || empty($car_type_id)) {
            throw new Exception("Nama model, merek, dan tipe mobil wajib diisi.");
        }
        
        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ?");
        $stmt->execute([$name, $brand_id]);
        if ($stmt->fetch()) {
            throw new Exception("Model dengan nama ini sudah ada pada merek tersebut.");
        }
        
        $stmt = $db->prepare("INSERT INTO models (name, brand_id, car_type_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        if ($stmt->execute([$name, $brand_id, $car_type_id, $status])) {
            $newId = $db->lastInsertId();
            
            // Get brand name for display
            $stmt = $db->prepare("SELECT name FROM brands WHERE id = ?");
            $stmt->execute([$brand_id]);
            $brandName = $stmt->fetchColumn() ?: '';
            
            $response = [
                'success' => true,
                'message' => 'Model baru berhasil ditambahkan.',
                'data' => [
                    'id' => $newId,
                    'full_name' => trim($brandName . ' ' . $name)
                ]
            ];
        } else {
            throw new Exception("Gagal menyimpan model ke database.");
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

include '../includes/header.php';
require_once '../includes/document_helpers.php';
requireLogin();

$error = '';
$formData = [
    'customer_id' => '',
    'desired_vehicle_id' => '',
    'trade_in_model_id' => '',
    'trade_in_year' => date('Y'),
    'trade_in_color' => '',
    'trade_in_license_plate' => '',
    'trade_in_chassis_number' => '',
    'trade_in_engine_number' => '',
    'trade_in_transmission' => 'automatic',
    'trade_in_fuel_type' => 'gasoline',
    'trade_in_mileage' => 0,
    'trade_in_condition' => '',
    'appraised_value' => '',
    'payment_method' => 'cash',
    'notes' => ''
];

// Handle Submit Trade-In Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_trade_in') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang formulir.';
    } else {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $desired_vehicle_id = (int)($_POST['desired_vehicle_id'] ?? 0);
        $desired_vehicle_price = (float)($_POST['desired_vehicle_price'] ?? 0);

        // Trade-in Old Car Data from Model selection
        $trade_in_model_id = (int)($_POST['trade_in_model_id'] ?? 0);
        $trade_in_year = (int)($_POST['trade_in_year'] ?? date('Y'));
        $trade_in_color = sanitizeInput($_POST['trade_in_color'] ?? '');
        $trade_in_license_plate = strtoupper(trim(sanitizeInput($_POST['trade_in_license_plate'] ?? '')));
        $trade_in_chassis_number = strtoupper(trim(sanitizeInput($_POST['trade_in_chassis_number'] ?? '')));
        $trade_in_engine_number = strtoupper(trim(sanitizeInput($_POST['trade_in_engine_number'] ?? '')));
        $trade_in_transmission = $_POST['trade_in_transmission'] ?? 'automatic';
        $trade_in_fuel_type = $_POST['trade_in_fuel_type'] ?? 'gasoline';
        $trade_in_mileage = (int)($_POST['trade_in_mileage'] ?? 0);
        $trade_in_condition = sanitizeInput($_POST['trade_in_condition'] ?? '');
        $appraised_value = (float)($_POST['appraised_value'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $notes = sanitizeInput($_POST['notes'] ?? '');

        $formData = [
            'customer_id' => $customer_id,
            'desired_vehicle_id' => $desired_vehicle_id,
            'trade_in_model_id' => $trade_in_model_id,
            'trade_in_year' => $trade_in_year,
            'trade_in_color' => $trade_in_color,
            'trade_in_license_plate' => $trade_in_license_plate,
            'trade_in_chassis_number' => $trade_in_chassis_number,
            'trade_in_engine_number' => $trade_in_engine_number,
            'trade_in_transmission' => $trade_in_transmission,
            'trade_in_fuel_type' => $trade_in_fuel_type,
            'trade_in_mileage' => $trade_in_mileage,
            'trade_in_condition' => $trade_in_condition,
            'appraised_value' => $appraised_value,
            'payment_method' => $payment_method,
            'notes' => $notes
        ];

        // Retrieve Brand and Model names from database
        $trade_in_brand_id = null;
        $trade_in_brand_name = '';
        $trade_in_model_name = '';
        if ($trade_in_model_id > 0) {
            $stmtM = $db->prepare("SELECT m.name as model_name, m.brand_id, b.name as brand_name FROM models m LEFT JOIN brands b ON m.brand_id = b.id WHERE m.id = ?");
            $stmtM->execute([$trade_in_model_id]);
            $mInfo = $stmtM->fetch(PDO::FETCH_ASSOC);
            if ($mInfo) {
                $trade_in_brand_id = (int)$mInfo['brand_id'];
                $trade_in_brand_name = $mInfo['brand_name'];
                $trade_in_model_name = $mInfo['model_name'];
            }
        }

        $price_difference = max(0, $desired_vehicle_price - $appraised_value);

        if (!$customer_id || !$desired_vehicle_id || !$trade_in_model_id || empty($trade_in_model_name)) {
            $error = 'Silakan pilih konsumen, unit showroom yang dipilih, serta model kendaraan lama konsumen.';
        } elseif (empty($trade_in_year) || $trade_in_year < 1900 || $trade_in_year > (int)date('Y') + 1) {
            $error = 'Tahun produksi tidak valid (1900 - ' . ((int)date('Y') + 1) . ').';
        } elseif (empty($trade_in_color)) {
            $error = 'Warna kendaraan lama konsumen wajib diisi.';
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $trade_in_color)) {
            $error = 'Warna hanya boleh berisi huruf (tanpa angka).';
        } elseif (empty($trade_in_license_plate)) {
            $error = 'Nomor polisi / plat kendaraan lama wajib diisi.';
        } elseif ($appraised_value <= 0) {
            $error = 'Nilai taksiran / appraisal mobil lama harus lebih besar dari Rp 0.';
        } else {
            try {
                $tradeInCode = 'TT-' . date('Ym') . '-' . strtoupper(substr(uniqid(), -4));
                $stmt = $db->prepare("
                    INSERT INTO trade_in_transactions (
                        trade_in_code, customer_id, desired_vehicle_id, desired_vehicle_price,
                        trade_in_model_id, trade_in_brand_id, trade_in_brand_name, trade_in_model_name,
                        trade_in_year, trade_in_license_plate, trade_in_chassis_number, trade_in_engine_number,
                        trade_in_transmission, trade_in_fuel_type, trade_in_color, trade_in_mileage,
                        trade_in_condition, appraised_value, price_difference, payment_method, status, notes,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, 'appraisal', ?,
                        NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    $tradeInCode, $customer_id, $desired_vehicle_id, $desired_vehicle_price,
                    $trade_in_model_id, $trade_in_brand_id, $trade_in_brand_name, $trade_in_model_name,
                    $trade_in_year, $trade_in_license_plate, $trade_in_chassis_number, $trade_in_engine_number,
                    $trade_in_transmission, $trade_in_fuel_type, $trade_in_color, $trade_in_mileage,
                    $trade_in_condition, $appraised_value, $price_difference, $payment_method, $notes
                ]);

                $newId = $db->lastInsertId();
                showAlert('Transaksi tukar tambah berhasil didaftarkan dengan kode ' . $tradeInCode, 'success');
                redirect('trade_in/view.php?id=' . $newId);

            } catch (PDOException $e) {
                $error = 'Gagal menyimpan transaksi: ' . $e->getMessage();
            }
        }
    }
}

// Fetch lists
try {
    $customersList = $db->query("SELECT id, name, phone FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $vehiclesList = $db->query("
        SELECT v.id, v.license_plate, v.year, v.selling_price, b.name as brand_name, m.name as model_name
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        WHERE v.status = 'available'
        ORDER BY v.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get models for dropdown (same as vehicles/create.php)
    $stmt = $db->query("
        SELECT m.id, CONCAT(b.name, ' ', m.name) as full_name
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        WHERE m.status = 'active'
        ORDER BY b.name ASC, m.name ASC
    ");
    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Brands and car types for Add Model modal
    $brands = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $carTypes = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $customersList = [];
    $vehiclesList = [];
    $models = [];
    $brands = [];
    $carTypes = [];
}
?>

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

<style>
    /* Consistent Header */
    .vehicles-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .vehicles-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .vehicles-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .btn-header-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-header-action:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
    }

    /* Form Cards */
    .form-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: visible !important;
        position: relative;
    }
    .form-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .form-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .form-card-body {
        padding: 20px;
    }

    .form-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
    }
    .form-label .label-hint {
        font-weight: 400;
        color: #94a3b8;
        font-size: 0.75rem;
        margin-left: 4px;
    }
    .form-control, .form-select {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 8px 12px;
        font-size: 0.85rem;
        color: #1e293b;
        background-color: #f8fafc;
        transition: all 0.15s;
    }
    .form-control:focus, .form-select:focus {
        background-color: #fff;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }

    /* Custom Choices.js Styling */
    .input-group-choices {
        display: flex !important;
        align-items: stretch !important;
        width: 100%;
    }
    .input-group-choices .choices {
        flex: 1 1 auto !important;
        width: 1% !important;
        margin-bottom: 0;
    }
    .input-group-choices .choices__inner {
        min-height: 40px !important;
        padding: 4px 12px !important;
        border: 1px solid #e2e8f0 !important;
        border-top-right-radius: 0 !important;
        border-bottom-right-radius: 0 !important;
        border-top-left-radius: 10px !important;
        border-bottom-left-radius: 10px !important;
        background-color: #f8fafc !important;
        font-size: 0.85rem !important;
        display: flex;
        align-items: center;
    }
    .input-group-choices .btn-add-model-trigger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: none;
        border-top-right-radius: 10px;
        border-bottom-right-radius: 10px;
        color: #475569;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .input-group-choices .btn-add-model-trigger:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .choices__list--dropdown {
        border-radius: 10px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08) !important;
        z-index: 99 !important;
    }
    .choices__list--dropdown .choices__item--selectable.is-highlighted {
        background-color: #f1f5f9 !important;
        color: #0f172a !important;
    }

    /* Modal Form Custom */
    .form-group-custom {
        margin-bottom: 16px;
    }
    .form-label-custom {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
    }
    .form-control-custom, .form-select-custom {
        width: 100%;
        height: 40px;
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #1e293b;
        background-color: #f8fafc;
        transition: all 0.15s;
    }
    .form-control-custom:focus, .form-select-custom:focus {
        background-color: #fff;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }

    /* Sticky Calculation Box */
    .diff-calc-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        position: sticky;
        top: 20px;
    }
    .diff-calc-header {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.04em;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .diff-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    .diff-label {
        font-size: 0.8rem;
        color: #64748b;
    }
    .diff-val {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }
    .diff-divider {
        border-top: 1px dashed #e2e8f0;
        margin: 14px 0;
    }
    .diff-stat-num {
        font-size: 1.6rem;
        font-weight: 800;
        color: #2563eb;
        letter-spacing: -0.02em;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .form-card,
    [data-theme="dark"] .diff-calc-box { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-title,
    [data-theme="dark"] .diff-val { color: #f1f5f9; }
    [data-theme="dark"] .form-label,
    [data-theme="dark"] .form-label-custom { color: #94a3b8; }
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select,
    [data-theme="dark"] .form-control-custom,
    [data-theme="dark"] .form-select-custom { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .form-control:focus,
    [data-theme="dark"] .form-select:focus,
    [data-theme="dark"] .form-control-custom:focus,
    [data-theme="dark"] .form-select-custom:focus { background: #162032; border-color: #475569; }
    [data-theme="dark"] .form-card-header,
    [data-theme="dark"] .diff-divider { border-bottom-color: #334155; border-top-color: #334155; }
    [data-theme="dark"] .input-group-choices .choices__inner { background-color: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
    [data-theme="dark"] .input-group-choices .choices__list--single .choices__item { color: #f1f5f9 !important; }
    [data-theme="dark"] .input-group-choices .btn-add-model-trigger { background: #0f172a !important; border-color: #334155 !important; color: #94a3b8 !important; }
    [data-theme="dark"] .input-group-choices .btn-add-model-trigger:hover { background: #1e293b !important; color: #f1f5f9 !important; }
    [data-theme="dark"] .choices__list--dropdown { background-color: #1e293b !important; border-color: #334155 !important; }
    [data-theme="dark"] .choices__list--dropdown .choices__item { color: #cbd5e1 !important; }
    [data-theme="dark"] .choices__list--dropdown .choices__item--selectable.is-highlighted { background-color: #334155 !important; color: #f1f5f9 !important; }
    [data-theme="dark"] .choices__input { background-color: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Mulai Transaksi Tukar Tambah Baru</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-primary text-decoration-underline">Tukar Tambah</a></li>
                <li class="breadcrumb-item active" aria-current="page">Pengajuan Baru</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4 border-0 shadow-sm" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;">
    <div class="d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <div><strong>Perhatian:</strong> <?php echo htmlspecialchars($error); ?></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="tradeInForm">
    <input type="hidden" name="action" value="save_trade_in">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <div class="row g-4">
        <!-- Left 8 Cols: Form Fields -->
        <div class="col-12 col-lg-8">
            <!-- 1. Consumer & Desired Vehicle -->
            <div class="form-card card">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-user-check text-primary"></i> 1. Pilih Konsumen & Mobil Showroom
                    </h5>
                </div>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="customer_id" class="form-label">Konsumen Pemohon <span class="text-danger">*</span></label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value="">-- Pilih Konsumen --</option>
                                <?php foreach ($customersList as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($formData['customer_id'] == $c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name'] . ' (' . $c['phone'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="desired_vehicle_id" class="form-label">Unit Mobil Showroom yang Dipilih <span class="text-danger">*</span></label>
                            <select class="form-select" id="desired_vehicle_id" name="desired_vehicle_id" required onchange="updateDesiredPrice(this)">
                                <option value="" data-price="0">-- Pilih Unit Showroom --</option>
                                <?php foreach ($vehiclesList as $v): ?>
                                <option value="<?php echo $v['id']; ?>" data-price="<?php echo $v['selling_price']; ?>" <?php echo ($formData['desired_vehicle_id'] == $v['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ') - ' . $v['license_plate'] . ' - Rp ' . number_format($v['selling_price'], 0, ',', '.')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="desired_vehicle_price" name="desired_vehicle_price" value="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Old Car (Trade-In) Specifications -->
            <div class="form-card card">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-car text-success"></i> 2. Spesifikasi Unit Lama Konsumen (Trade-In)
                    </h5>
                </div>
                <div class="form-card-body">
                    <!-- Model Selection matching vehicles/create.php -->
                    <div class="mb-3">
                        <label for="trade_in_model_id" class="form-label">
                            Model Kendaraan <span class="text-danger">*</span>
                        </label>
                        <div class="input-group-choices">
                            <select id="trade_in_model_id" name="trade_in_model_id" required>
                                <option value="">Pilih Model Kendaraan...</option>
                                <?php foreach ($models as $model): ?>
                                <option value="<?php echo $model['id']; ?>" 
                                        <?php echo ($formData['trade_in_model_id'] == $model['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($model['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-add-model-trigger" data-bs-toggle="modal" data-bs-target="#addModelModal" title="Tambah Model Baru">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="trade_in_year" class="form-label">
                                Tahun Produksi <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="trade_in_year" name="trade_in_year" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_year'] ?? date('Y')); ?>"
                                   min="1900" max="<?php echo date('Y') + 1; ?>"
                                   placeholder="Contoh: <?php echo date('Y'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="trade_in_color" class="form-label">
                                Warna Kendaraan <span class="text-danger">*</span>
                                <span class="label-hint">(Hanya huruf)</span>
                            </label>
                            <input type="text" class="form-control" id="trade_in_color" name="trade_in_color" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_color'] ?? ''); ?>"
                                   placeholder="Contoh: Hitam Metalik, Putih Mutiara" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_license_plate" class="form-label">
                                Nomor Polisi <span class="label-hint">(Plat)</span> <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control text-uppercase" id="trade_in_license_plate" name="trade_in_license_plate" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_license_plate'] ?? ''); ?>"
                                   placeholder="B 1234 ABC" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_chassis_number" class="form-label">
                                Nomor Rangka (VIN)
                            </label>
                            <input type="text" class="form-control text-uppercase" id="trade_in_chassis_number" name="trade_in_chassis_number" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_chassis_number'] ?? ''); ?>"
                                   placeholder="MHK123456789">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_engine_number" class="form-label">
                                Nomor Mesin
                            </label>
                            <input type="text" class="form-control text-uppercase" id="trade_in_engine_number" name="trade_in_engine_number" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_engine_number'] ?? ''); ?>"
                                   placeholder="3SZVE123456">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_transmission" class="form-label">Transmisi <span class="text-danger">*</span></label>
                            <select class="form-select" id="trade_in_transmission" name="trade_in_transmission" required>
                                <option value="automatic" <?php echo ($formData['trade_in_transmission'] === 'automatic') ? 'selected' : ''; ?>>Otomatis (AT)</option>
                                <option value="manual" <?php echo ($formData['trade_in_transmission'] === 'manual') ? 'selected' : ''; ?>>Manual (MT)</option>
                                <option value="cvt" <?php echo ($formData['trade_in_transmission'] === 'cvt') ? 'selected' : ''; ?>>CVT</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_fuel_type" class="form-label">Bahan Bakar <span class="text-danger">*</span></label>
                            <select class="form-select" id="trade_in_fuel_type" name="trade_in_fuel_type" required>
                                <option value="gasoline" <?php echo ($formData['trade_in_fuel_type'] === 'gasoline') ? 'selected' : ''; ?>>Bensin (Gasoline)</option>
                                <option value="diesel" <?php echo ($formData['trade_in_fuel_type'] === 'diesel') ? 'selected' : ''; ?>>Diesel</option>
                                <option value="hybrid" <?php echo ($formData['trade_in_fuel_type'] === 'hybrid') ? 'selected' : ''; ?>>Hybrid</option>
                                <option value="electric" <?php echo ($formData['trade_in_fuel_type'] === 'electric') ? 'selected' : ''; ?>>Listrik (EV)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="trade_in_mileage" class="form-label">Odometer (Kilometer)</label>
                            <input type="number" class="form-control" id="trade_in_mileage" name="trade_in_mileage" 
                                   value="<?php echo htmlspecialchars($formData['trade_in_mileage'] ?? 0); ?>" 
                                   placeholder="0" min="0">
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="trade_in_condition" class="form-label">Catatan Hasil Taksiran & Kondisi Fisik</label>
                        <textarea class="form-control" id="trade_in_condition" name="trade_in_condition" rows="3" placeholder="Catatan kondisi mesin, interior, bodi, riwayat servis, dll..."><?php echo htmlspecialchars($formData['trade_in_condition'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- 3. Appraisal Value & Payment Method -->
            <div class="form-card card">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-calculator text-primary"></i> 3. Nilai Taksiran & Pembayaran
                    </h5>
                </div>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="appraised_value" class="form-label">Nilai Taksiran / Appraisal Mobil Lama (Rp) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="appraised_value" name="appraised_value" 
                                   value="<?php echo htmlspecialchars($formData['appraised_value'] ?? ''); ?>"
                                   placeholder="0" min="1000000" step="500000" required oninput="calcDifference()">
                            <div class="text-muted small mt-1">Nilai harga yang disepakati untuk membeli mobil lama konsumen.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Metode Pembayaran Selisih</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="cash" <?php echo ($formData['payment_method'] === 'cash') ? 'selected' : ''; ?>>Tunai / Transfer Bank Langsung</option>
                                <option value="leasing" <?php echo ($formData['payment_method'] === 'leasing') ? 'selected' : ''; ?>>Kredit / Pembiayaan Leasing</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="notes" class="form-label">Catatan Tambahan Transaksi</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Catatan opsional..."><?php echo htmlspecialchars($formData['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right 4 Cols: Real-time Calculation Sticky Sidebar -->
        <div class="col-12 col-lg-4">
            <div class="diff-calc-box">
                <div class="diff-calc-header">
                    <i class="fas fa-receipt text-primary"></i> Rincian Selisih Pembayaran
                </div>

                <div class="diff-row">
                    <span class="diff-label">Harga Unit Showroom:</span>
                    <span class="diff-val" id="dispDesiredPrice">Rp 0</span>
                </div>

                <div class="diff-row">
                    <span class="diff-label">Nilai Taksiran Mobil Lama:</span>
                    <span class="diff-val text-success" id="dispAppraisedValue">Rp 0</span>
                </div>

                <div class="diff-divider"></div>

                <div class="mb-3">
                    <span class="diff-label d-block mb-1">Selisih yang Harus Dibayar Konsumen:</span>
                    <div class="diff-stat-num" id="dispDiffPrice">Rp 0</div>
                </div>

                <div class="text-muted small mb-4" style="line-height: 1.45;">
                    Setelah formulir ini disimpan, Anda dapat meninjau data sebelum mengklik <strong>Selesaikan Transaksi</strong> untuk otomatis memasukkan unit lama ke stok showroom dan menandai unit showroom terjual.
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" id="btnSubmitTradeIn" class="btn btn-primary" style="background:#2d3436;border:none;border-radius:10px;padding:10px 16px;font-weight:600;">
                        <i class="fas fa-save me-1"></i> Simpan Transaksi Tukar Tambah
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;padding:9px 16px;">
                        Batal
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Modal Add Model (Same as vehicles/create.php) -->
<div class="modal fade" id="addModelModal" tabindex="-1" aria-labelledby="addModelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 10px 30px rgba(0,0,0,0.1);">
            <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:16px 20px;">
                <h5 class="modal-title fs-6 fw-bold" id="addModelModalLabel">
                    <i class="fas fa-car-side text-primary me-2"></i> Tambah Model Mobil Baru
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding:20px;">
                <form id="addModelForm" onsubmit="submitAddModel(event)">
                    <input type="hidden" name="action" value="ajax_add_model">
                    
                    <div class="form-group-custom">
                        <label class="form-label-custom">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select-custom" name="brand_id" required>
                             <option value="">Pilih Merek Mobil...</option>
                             <?php foreach ($brands as $b): ?>
                             <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-custom">
                        <label class="form-label-custom">Nama Model / Varian <span class="text-danger">*</span></label>
                        <input type="text" class="form-control-custom" name="name" placeholder="Contoh: Avanza 1.3 G, Civic Turbo" required>
                    </div>

                    <div class="form-group-custom">
                        <label class="form-label-custom">Tipe Bodi Kendaraan <span class="text-danger">*</span></label>
                        <select class="form-select-custom" name="car_type_id" required>
                             <option value="">Pilih Jenis Bodi...</option>
                             <?php foreach ($carTypes as $t): ?>
                             <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group-custom">
                        <label class="form-label-custom">Status</label>
                        <select class="form-select-custom" name="status">
                            <option value="active" selected>Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>

                    <div id="modelMsg" class="mb-3"></div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary flex-fill" style="border-radius:10px;padding:9px;" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn btn-primary flex-fill" id="btnSubmitModal" style="background:#2d3436;border:none;border-radius:10px;padding:9px;font-weight:600;">
                            <i class="fas fa-save me-1"></i> Simpan Model
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Choices.js JS -->
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
let modelChoices = null;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Choices.js on Model dropdown
    const modelSelectEl = document.getElementById('trade_in_model_id');
    if (modelSelectEl) {
        modelChoices = new Choices(modelSelectEl, {
            searchEnabled: true,
            searchPlaceholderValue: 'Cari nama model...',
            noResultsText: 'Model tidak ditemukan',
            itemSelectText: '',
            shouldSort: false,
            removeItemButton: false
        });
    }

    // Auto uppercase fields
    ['trade_in_license_plate', 'trade_in_chassis_number', 'trade_in_engine_number'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
            });
        }
    });

    // Modal Dynamic Add Handler
    window.reinitializeModelChoices = function(newId, newFullName) {
        if (modelChoices) {
            modelChoices.destroy();
        }
        
        const option = new Option(newFullName, newId, true, true);
        modelSelectEl.add(option);
        
        modelChoices = new Choices(modelSelectEl, {
            searchEnabled: true,
            searchPlaceholderValue: 'Cari nama model...',
            noResultsText: 'Model tidak ditemukan',
            itemSelectText: '',
            shouldSort: false,
            removeItemButton: false
        });
        
        modelChoices.setChoiceByValue(newId.toString());
    };

    // Trigger initial calculation if desired vehicle is preselected
    const desiredEl = document.getElementById('desired_vehicle_id');
    if (desiredEl && desiredEl.value) {
        updateDesiredPrice(desiredEl);
    }
});

function formatRupiah(num) {
    return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
}

function updateDesiredPrice(selectEl) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    const price = parseFloat(selectedOpt.getAttribute('data-price') || 0);
    document.getElementById('desired_vehicle_price').value = price;
    document.getElementById('dispDesiredPrice').textContent = formatRupiah(price);
    calcDifference();
}

function calcDifference() {
    const desired = parseFloat(document.getElementById('desired_vehicle_price').value || 0);
    const appraised = parseFloat(document.getElementById('appraised_value').value || 0);
    
    document.getElementById('dispAppraisedValue').textContent = formatRupiah(appraised);
    
    const diff = Math.max(0, desired - appraised);
    document.getElementById('dispDiffPrice').textContent = formatRupiah(diff);
}

// Submit Add Model via AJAX
function submitAddModel(e) {
    e.preventDefault();
    const form = document.getElementById('addModelForm');
    const formData = new FormData(form);
    const msgDiv = document.getElementById('modelMsg');
    const btnSubmit = document.getElementById('btnSubmitModal');
    
    msgDiv.innerHTML = '<div class="alert alert-info py-2" style="font-size:0.8rem;border-radius:8px;"><i class="fas fa-spinner fa-spin me-1"></i> Menyimpan model baru...</div>';
    if (btnSubmit) btnSubmit.disabled = true;
    
    fetch('create.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            msgDiv.innerHTML = '<div class="alert alert-success py-2" style="font-size:0.8rem;border-radius:8px;"><i class="fas fa-check-circle me-1"></i> ' + data.message + '</div>';
            
            if (typeof window.reinitializeModelChoices === 'function') {
                window.reinitializeModelChoices(data.data.id, data.data.full_name);
            }
            
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('addModelModal'));
                if (modal) modal.hide();
                form.reset();
                msgDiv.innerHTML = '';
                if (btnSubmit) btnSubmit.disabled = false;
            }, 800);
            
        } else {
            msgDiv.innerHTML = '<div class="alert alert-danger py-2" style="font-size:0.8rem;border-radius:8px;"><i class="fas fa-exclamation-circle me-1"></i> ' + data.message + '</div>';
            if (btnSubmit) btnSubmit.disabled = false;
        }
    })
    .catch(error => {
        msgDiv.innerHTML = '<div class="alert alert-danger py-2" style="font-size:0.8rem;border-radius:8px;"><i class="fas fa-exclamation-circle me-1"></i> Terjadi kesalahan sistem saat menyimpan.</div>';
        if (btnSubmit) btnSubmit.disabled = false;
    });
}
</script>

<?php include '../includes/footer.php'; ?>
