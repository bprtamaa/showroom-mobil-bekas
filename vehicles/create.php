<?php
// vehicles/create.php - Tambah Stok Mobil
require_once '../config/config.php';

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

$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();

// Allow staff and admin to create vehicles
requireStaffOrAdmin();

$error = '';
$formData = [
    'model_id' => $_GET['model_id'] ?? '',
    'year' => date('Y'),
    'fuel_type' => 'gasoline',
    'transmission' => 'automatic',
    'condition_rating' => 'good',
    'status' => 'available',
    'warranty_type' => 'none',
    'purchase_date' => date('Y-m-d')
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'ajax_add_model')) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF). Silakan muat ulang halaman.';
    } else {
        $formData = [
            'model_id' => (int)($_POST['model_id'] ?? 0),
            'year' => (int)($_POST['year'] ?? 0),
            'chassis_number' => strtoupper(sanitizeInput($_POST['chassis_number'] ?? '')),
            'engine_number' => strtoupper(sanitizeInput($_POST['engine_number'] ?? '')),
            'license_plate' => strtoupper(sanitizeInput($_POST['license_plate'] ?? '')),
            'color' => sanitizeInput($_POST['color'] ?? ''),
            'mileage' => (int)($_POST['mileage'] ?? 0),
            'fuel_type' => $_POST['fuel_type'] ?? '',
            'transmission' => $_POST['transmission'] ?? '',
            'purchase_price' => parseCurrency($_POST['purchase_price'] ?? '0'),
            'selling_price' => parseCurrency($_POST['selling_price'] ?? '0'),
            'purchase_date' => $_POST['purchase_date'] ?? '',
            'condition_rating' => $_POST['condition_rating'] ?? 'good',
            'notes' => sanitizeInput($_POST['notes'] ?? ''),
            'status' => $_POST['status'] ?? 'available',
            'warranty_type' => $_POST['warranty_type'] ?? 'none',
            'warranty_period' => (int)($_POST['warranty_period'] ?? 0) ?: null,
            'warranty_notes' => sanitizeInput($_POST['warranty_notes'] ?? ''),
            'warranty_expiry_date' => $_POST['warranty_expiry_date'] ?? null
        ];
        
        // Validation
        if (empty($formData['model_id'])) {
            $error = 'Model mobil wajib dipilih';
        } elseif (empty($formData['year']) || $formData['year'] < 1900 || $formData['year'] > (int)date('Y') + 1) {
            $error = 'Tahun produksi tidak valid (1900 - ' . ((int)date('Y') + 1) . ')';
        } elseif (empty($formData['chassis_number'])) {
            $error = 'Nomor rangka (VIN) wajib diisi';
        } elseif (strlen($formData['chassis_number']) < 5) {
            $error = 'Nomor rangka minimal 5 karakter';
        } elseif (empty($formData['engine_number'])) {
            $error = 'Nomor mesin wajib diisi';
        } elseif (strlen($formData['engine_number']) < 5) {
            $error = 'Nomor mesin minimal 5 karakter';
        } elseif (empty($formData['color'])) {
            $error = 'Warna mobil wajib diisi';
        } elseif (!preg_match("/^[a-zA-Z\s]+$/", $formData['color'])) {
            $error = 'Warna hanya boleh berisi huruf (tanpa angka)';
        } elseif (empty($formData['fuel_type'])) {
            $error = 'Jenis bahan bakar wajib dipilih';
        } elseif (empty($formData['transmission'])) {
            $error = 'Tipe transmisi wajib dipilih';
        } elseif ($formData['purchase_price'] <= 0) {
            $error = 'Harga beli harus lebih dari Rp 0';
        } elseif ($formData['selling_price'] <= 0) {
            $error = 'Harga jual harus lebih dari Rp 0';
        } elseif (empty($formData['purchase_date'])) {
            $error = 'Tanggal beli wajib diisi';
        } else {
            try {
                // Check chassis number
                $stmt = $db->prepare("SELECT id FROM vehicles WHERE chassis_number = ?");
                $stmt->execute([$formData['chassis_number']]);
                if ($stmt->fetch()) {
                    $error = 'Nomor rangka sudah terdaftar pada unit lain';
                }
                
                // Check engine number
                if (empty($error)) {
                    $stmt = $db->prepare("SELECT id FROM vehicles WHERE engine_number = ?");
                    $stmt->execute([$formData['engine_number']]);
                    if ($stmt->fetch()) {
                        $error = 'Nomor mesin sudah terdaftar pada unit lain';
                    }
                }
                
                // Check license plate if provided
                if (empty($error) && !empty($formData['license_plate'])) {
                    $stmt = $db->prepare("SELECT id FROM vehicles WHERE license_plate = ?");
                    $stmt->execute([$formData['license_plate']]);
                    if ($stmt->fetch()) {
                        $error = 'Nomor polisi sudah terdaftar pada unit lain';
                    }
                }
                
                if (empty($error)) {
                    // Handle single image upload (main photo)
                    $imagesJson = null;
                    if (!empty($_FILES['image']) && isset($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        $name = basename($_FILES['image']['name']);
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        if (in_array($ext, $allowed) && $_FILES['image']['size'] <= 3 * 1024 * 1024) {
                            $destDir = __DIR__ . '/../uploads/vehicles/';
                            if (!file_exists($destDir)) mkdir($destDir, 0777, true);
                            $newName = 'veh_' . uniqid() . '.' . $ext;
                            if (move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $newName)) {
                                $imagesJson = json_encode([$newName]);
                            }
                        } else if ($_FILES['image']['size'] > 3 * 1024 * 1024) {
                            $error = 'Ukuran foto maksimal adalah 3MB';
                        }
                    }

                    if (empty($error)) {
                        $stmt = $db->prepare("
                            INSERT INTO vehicles (
                                model_id, year, chassis_number, engine_number, license_plate, color,
                                mileage, fuel_type, transmission, purchase_price, selling_price,
                                purchase_date, condition_rating, notes, status,
                                warranty_type, warranty_period, warranty_notes, warranty_expiry_date, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        if ($stmt->execute([
                            $formData['model_id'],
                            $formData['year'],
                            $formData['chassis_number'],
                            $formData['engine_number'],
                            $formData['license_plate'] ?: null,
                            $formData['color'],
                            $formData['mileage'],
                            $formData['fuel_type'],
                            $formData['transmission'],
                            $formData['purchase_price'],
                            $formData['selling_price'],
                            $formData['purchase_date'],
                            $formData['condition_rating'],
                            $formData['notes'],
                            $formData['status'],
                            $formData['warranty_type'] ?: 'none',
                            $formData['warranty_period'] ?: null,
                            $formData['warranty_notes'],
                            $formData['warranty_expiry_date'] ?: null
                        ])) {
                            $lastId = $db->lastInsertId();
                            if ($imagesJson) {
                                $upd = $db->prepare("UPDATE vehicles SET images = ? WHERE id = ?");
                                $upd->execute([$imagesJson, $lastId]);
                            }

                            showAlert('Data mobil baru berhasil disimpan ke stok.', 'success');
                            redirect('vehicles/');
                        } else {
                            $error = 'Gagal menyimpan data mobil ke sistem.';
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = 'Database Error: ' . $e->getMessage();
            }
        }
    }
}

// Get models
try {
    $stmt = $db->query("
        SELECT m.id, CONCAT(b.name, ' ', m.name) as full_name
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        WHERE m.status = 'active'
        ORDER BY b.name ASC, m.name ASC
    ");
    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $models = [];
}

// Fetch Brands and Car Types for Modal
try {
    $brands = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $carTypes = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $brands = [];
    $carTypes = [];
}

if (!function_exists('parseCurrency')) {
    function parseCurrency($value) {
        return (int)preg_replace('/[^0-9]/', '', $value);
    }
}
?>

<style>
    /* ===== Consistent Master Data Page Header ===== */
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
        margin: 0 0 4px 0;
        letter-spacing: -0.025em;
    }
    .vehicles-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .vehicles-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .btn-header-secondary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
        text-decoration: none;
    }
    .btn-header-secondary:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }

    /* ===== Vehicle Form Card Styles ===== */
    .vehicle-form-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        overflow: hidden;
    }
    .vehicle-form-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .vehicle-form-card .card-header-clean {
        padding: 16px 20px;
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .card-header-clean .header-title-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card-header-clean .header-icon-badge {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f1f5f9;
        color: #475569;
        font-size: 0.85rem;
    }
    .card-header-clean h5 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .card-header-clean p {
        font-size: 0.75rem;
        color: #64748b;
        margin: 0;
    }
    .vehicle-form-card .card-body {
        padding: 20px;
    }

    /* ===== Form Fields & Labels ===== */
    .form-group-custom {
        margin-bottom: 18px;
    }
    .form-label-custom {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
    }
    .form-label-custom .req-mark {
        color: #ef4444;
        margin-left: 2px;
    }
    .form-label-custom .label-hint {
        font-weight: 400;
        color: #94a3b8;
        font-size: 0.75rem;
        margin-left: 4px;
    }

    .form-control-custom,
    .form-select-custom {
        width: 100%;
        height: 42px;
        padding: 8px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.86rem;
        font-weight: 500;
        color: #1e293b;
        background-color: #f8fafc;
        transition: all 0.15s ease-in-out;
    }
    textarea.form-control-custom {
        height: auto;
        padding: 10px 14px;
    }
    .form-control-custom:focus,
    .form-select-custom:focus {
        background-color: #ffffff;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }
    .form-control-custom::placeholder {
        color: #94a3b8;
        font-weight: 400;
    }

    .input-group-custom {
        display: flex;
        align-items: stretch;
        width: 100%;
    }
    .input-group-custom .input-addon-clean {
        display: inline-flex;
        align-items: center;
        padding: 0 14px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-right: none;
        border-radius: 10px 0 0 10px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #64748b;
    }
    .input-group-custom .input-addon-right {
        border-right: 1px solid #e2e8f0;
        border-left: none;
        border-radius: 0 10px 10px 0;
    }
    .input-group-custom .form-control-custom {
        border-radius: 0 10px 10px 0;
    }
    .input-group-custom .has-addon-right {
        border-radius: 10px 0 0 10px;
    }
    .input-group-custom .btn-addon-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-left: none;
        border-radius: 0 10px 10px 0;
        color: #334155;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .input-group-custom .btn-addon-action:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    /* ===== Live Photo Upload Box ===== */
    .photo-upload-zone {
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 24px 16px;
        text-align: center;
        background: #fafbfc;
        cursor: pointer;
        position: relative;
        transition: all 0.2s ease;
    }
    .photo-upload-zone:hover,
    .photo-upload-zone.dragover {
        border-color: #64748b;
        background: #f1f5f9;
    }
    .photo-upload-zone input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    .upload-zone-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #e2e8f0;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 10px;
    }
    .upload-zone-text {
        font-size: 0.85rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 2px;
    }
    .upload-zone-sub {
        font-size: 0.72rem;
        color: #94a3b8;
    }
    .photo-preview-box {
        display: none;
        position: relative;
        border-radius: 10px;
        overflow: hidden;
        margin-top: 12px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .photo-preview-box img {
        width: 100%;
        height: 180px;
        object-fit: cover;
        display: block;
    }
    .photo-preview-actions {
        padding: 8px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
    }
    .photo-file-info {
        font-size: 0.75rem;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 180px;
    }
    .btn-remove-photo {
        background: transparent;
        border: none;
        color: #ef4444;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: background 0.15s;
    }
    .btn-remove-photo:hover {
        background: #fee2e2;
    }

    /* ===== Profit Calculator Sidebar Widget ===== */
    .calculator-stat-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        margin-bottom: 12px;
    }
    .stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.82rem;
        margin-bottom: 8px;
    }
    .stat-row:last-child {
        margin-bottom: 0;
        padding-top: 8px;
        border-top: 1px dashed #e2e8f0;
    }
    .stat-label {
        color: #64748b;
        font-weight: 500;
    }
    .stat-value {
        font-weight: 700;
        color: #1e293b;
    }
    .stat-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .stat-badge-positive {
        background: #dcfce7;
        color: #15803d;
    }
    .stat-badge-negative {
        background: #fee2e2;
        color: #b91c1c;
    }
    .stat-badge-neutral {
        background: #f1f5f9;
        color: #64748b;
    }

    /* ===== Validation Checklist Card ===== */
    .checklist-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .checklist-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8rem;
        color: #64748b;
        padding: 6px 0;
        border-bottom: 1px solid #f8fafc;
    }
    .checklist-item:last-child {
        border-bottom: none;
    }
    .checklist-icon {
        font-size: 0.75rem;
        width: 16px;
        text-align: center;
    }
    .checklist-item.is-valid {
        color: #15803d;
        font-weight: 500;
    }
    .checklist-item.is-valid .checklist-icon {
        color: #16a34a;
    }

    /* ===== Action Buttons ===== */
    .btn-submit-save {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 11px 20px;
        background: #2d3436;
        color: #ffffff;
        font-size: 0.86rem;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
        text-decoration: none;
    }
    .btn-submit-save:hover {
        background: #1e272e;
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .btn-submit-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 10px 20px;
        background: #ffffff;
        color: #64748b;
        font-size: 0.84rem;
        font-weight: 600;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
        text-decoration: none;
    }
    .btn-submit-cancel:hover {
        background: #f8fafc;
        color: #334155;
        border-color: #cbd5e1;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .vehicle-form-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .vehicle-form-card .card-header-clean { background: #1e293b; border-bottom-color: #334155; }
    [data-theme="dark"] .card-header-clean .header-icon-badge { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .card-header-clean h5 { color: #f1f5f9; }
    [data-theme="dark"] .card-header-clean p { color: #64748b; }
    [data-theme="dark"] .form-label-custom { color: #cbd5e1; }
    [data-theme="dark"] .form-control-custom,
    [data-theme="dark"] .form-select-custom { background-color: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .form-control-custom:focus,
    [data-theme="dark"] .form-select-custom:focus { border-color: #60a5fa; background-color: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.15); }
    [data-theme="dark"] .input-group-custom .input-addon-clean,
    [data-theme="dark"] .input-group-custom .btn-addon-action { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .input-group-custom .btn-addon-action:hover { background: #334155; color: #f1f5f9; }
    [data-theme="dark"] .photo-upload-zone { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .photo-upload-zone:hover { background: #162032; border-color: #60a5fa; }
    [data-theme="dark"] .upload-zone-icon { background: #1e293b; color: #94a3b8; }
    [data-theme="dark"] .upload-zone-text { color: #f1f5f9; }
    [data-theme="dark"] .calculator-stat-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .stat-value { color: #f1f5f9; }
    [data-theme="dark"] .stat-row:last-child { border-top-color: #334155; }
    [data-theme="dark"] .btn-submit-save { background: #3b82f6; }
    [data-theme="dark"] .btn-submit-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-submit-cancel { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-submit-cancel:hover { background: #1e293b; color: #f8fafc; }
    [data-theme="dark"] .photo-preview-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .photo-preview-actions { background: #1e293b; border-top-color: #334155; }
    [data-theme="dark"] .photo-file-info { color: #cbd5e1; }
</style>

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

<style>
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
    min-height: 42px !important;
    padding: 4px 12px !important;
    border: 1px solid #e2e8f0 !important;
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
    border-top-left-radius: 10px !important;
    border-bottom-left-radius: 10px !important;
    background-color: #f8fafc !important;
    font-size: 0.86rem !important;
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
[data-theme="dark"] .input-group-choices .choices__inner {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .input-group-choices .choices__list--single .choices__item {
    color: #f1f5f9 !important;
}
[data-theme="dark"] .input-group-choices .btn-add-model-trigger {
    background: #0f172a !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}
[data-theme="dark"] .input-group-choices .btn-add-model-trigger:hover {
    background: #1e293b !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .choices__list--dropdown {
    background-color: #1e293b !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .choices__list--dropdown .choices__item {
    color: #cbd5e1 !important;
}
[data-theme="dark"] .choices__list--dropdown .choices__item--selectable.is-highlighted {
    background-color: #334155 !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .choices__input {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #f1f5f9 !important;
}
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Tambah Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/">Stok Mobil</a></li>
                <li class="breadcrumb-item active">Tambah Mobil</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>vehicles/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i>
            <span>Kembali</span>
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;" role="alert">
    <div class="d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <div><strong>Perhatian:</strong> <?php echo htmlspecialchars($error); ?></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<form method="POST" id="vehicleForm" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <div class="row">
        <!-- Main Form Column (Left) -->
        <div class="col-lg-8">
            
            <!-- Card 1: Informasi Model & Identitas -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-car"></i>
                        </div>
                        <div>
                            <h5>Identitas & Model Kendaraan</h5>
                            <p>Tentukan tipe mobil, nomor rangka, nomor mesin, dan warna</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group-custom">
                        <label for="model_id" class="form-label-custom">
                            Model Kendaraan <span class="req-mark">*</span>
                        </label>
                        <div class="input-group-choices">
                            <select id="model_id" name="model_id" required>
                                <option value="">Pilih Model Kendaraan...</option>
                                <?php foreach ($models as $model): ?>
                                <option value="<?php echo $model['id']; ?>" 
                                        <?php echo ($formData['model_id'] == $model['id']) ? 'selected' : ''; ?>>
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
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="year" class="form-label-custom">
                                    Tahun Produksi <span class="req-mark">*</span>
                                </label>
                                <input type="number" class="form-control-custom" id="year" name="year" 
                                       value="<?php echo htmlspecialchars($formData['year'] ?? date('Y')); ?>"
                                       min="1900" max="<?php echo date('Y') + 1; ?>"
                                       placeholder="Contoh: <?php echo date('Y'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="color" class="form-label-custom">
                                    Warna Kendaraan <span class="req-mark">*</span>
                                    <span class="label-hint">(Hanya huruf)</span>
                                </label>
                                <input type="text" class="form-control-custom" id="color" name="color" 
                                       value="<?php echo htmlspecialchars($formData['color'] ?? ''); ?>"
                                       placeholder="Contoh: Hitam Metalik, Putih Mutiara" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="license_plate" class="form-label-custom">
                                    Nomor Polisi <span class="label-hint">(Plat)</span>
                                </label>
                                <input type="text" class="form-control-custom text-uppercase" id="license_plate" name="license_plate" 
                                       value="<?php echo htmlspecialchars($formData['license_plate'] ?? ''); ?>"
                                       placeholder="B 1234 ABC">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="chassis_number" class="form-label-custom">
                                    Nomor Rangka (VIN) <span class="req-mark">*</span>
                                </label>
                                <input type="text" class="form-control-custom text-uppercase" id="chassis_number" name="chassis_number" 
                                       value="<?php echo htmlspecialchars($formData['chassis_number'] ?? ''); ?>"
                                       placeholder="MHK123456789" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="engine_number" class="form-label-custom">
                                    Nomor Mesin <span class="req-mark">*</span>
                                </label>
                                <input type="text" class="form-control-custom text-uppercase" id="engine_number" name="engine_number" 
                                       value="<?php echo htmlspecialchars($formData['engine_number'] ?? ''); ?>"
                                       placeholder="3SZVE123456" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Spesifikasi & Kondisi Teknis -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-sliders-h"></i>
                        </div>
                        <div>
                            <h5>Spesifikasi Teknis & Status</h5>
                            <p>Atur transmisi, bahan bakar, odometer, serta status operasional</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="mileage" class="form-label-custom">Odometer (Kilometer)</label>
                                <div class="input-group-custom">
                                    <input type="number" class="form-control-custom has-addon-right" id="mileage" name="mileage" 
                                           value="<?php echo htmlspecialchars($formData['mileage'] ?? 0); ?>" min="0">
                                    <span class="input-addon-clean input-addon-right">KM</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="fuel_type" class="form-label-custom">
                                    Bahan Bakar <span class="req-mark">*</span>
                                </label>
                                <select class="form-select-custom" id="fuel_type" name="fuel_type" required>
                                    <option value="">Pilih Bahan Bakar...</option>
                                    <option value="gasoline" <?php echo ($formData['fuel_type'] ?? '') === 'gasoline' ? 'selected' : ''; ?>>Bensin (Gasoline)</option>
                                    <option value="diesel" <?php echo ($formData['fuel_type'] ?? '') === 'diesel' ? 'selected' : ''; ?>>Diesel</option>
                                    <option value="hybrid" <?php echo ($formData['fuel_type'] ?? '') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                    <option value="electric" <?php echo ($formData['fuel_type'] ?? '') === 'electric' ? 'selected' : ''; ?>>Listrik (EV)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="transmission" class="form-label-custom">
                                    Transmisi <span class="req-mark">*</span>
                                </label>
                                <select class="form-select-custom" id="transmission" name="transmission" required>
                                    <option value="">Pilih Transmisi...</option>
                                    <option value="automatic" <?php echo ($formData['transmission'] ?? '') === 'automatic' ? 'selected' : ''; ?>>Otomatis (AT)</option>
                                    <option value="manual" <?php echo ($formData['transmission'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual (MT)</option>
                                    <option value="cvt" <?php echo ($formData['transmission'] ?? '') === 'cvt' ? 'selected' : ''; ?>>CVT</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="condition_rating" class="form-label-custom">Kondisi Keseluruhan</label>
                                <select class="form-select-custom" id="condition_rating" name="condition_rating">
                                    <option value="excellent" <?php echo ($formData['condition_rating'] ?? '') === 'excellent' ? 'selected' : ''; ?>>Sangat Baik (Grade A)</option>
                                    <option value="good" <?php echo ($formData['condition_rating'] ?? 'good') === 'good' ? 'selected' : ''; ?>>Baik (Grade B)</option>
                                    <option value="fair" <?php echo ($formData['condition_rating'] ?? '') === 'fair' ? 'selected' : ''; ?>>Cukup (Grade C)</option>
                                    <option value="poor" <?php echo ($formData['condition_rating'] ?? '') === 'poor' ? 'selected' : ''; ?>>Perlu Perbaikan (Grade D)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="status" class="form-label-custom">Status Unit</label>
                                <select class="form-select-custom" id="status" name="status">
                                    <option value="available" <?php echo ($formData['status'] ?? 'available') === 'available' ? 'selected' : ''; ?>>Tersedia (Ready Stock)</option>
                                    <option value="maintenance" <?php echo ($formData['status'] ?? '') === 'maintenance' ? 'selected' : ''; ?>>Dalam Perawatan / Bengkel</option>
                                    <option value="reserved" <?php echo ($formData['status'] ?? '') === 'reserved' ? 'selected' : ''; ?>>Dipesan (Booked / DP)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Informasi Harga & Transaksi Masuk -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div>
                            <h5>Harga & Pembelian</h5>
                            <p>Catat nilai perolehan unit dan patokan harga penawaran jual</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="purchase_date" class="form-label-custom">
                                    Tanggal Masuk/Beli <span class="req-mark">*</span>
                                </label>
                                <input type="date" class="form-control-custom" id="purchase_date" name="purchase_date" 
                                       value="<?php echo htmlspecialchars($formData['purchase_date'] ?? date('Y-m-d')); ?>"
                                       max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="purchase_price" class="form-label-custom">
                                    Harga Beli (Modal) <span class="req-mark">*</span>
                                </label>
                                <div class="input-group-custom">
                                    <span class="input-addon-clean">Rp</span>
                                    <input type="text" class="form-control-custom currency-field" id="purchase_price" name="purchase_price" 
                                           value="<?php echo !empty($formData['purchase_price']) ? number_format((int)$formData['purchase_price'], 0, ',', '.') : ''; ?>"
                                           placeholder="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group-custom">
                                <label for="selling_price" class="form-label-custom">
                                    Harga Jual (Target) <span class="req-mark">*</span>
                                </label>
                                <div class="input-group-custom">
                                    <span class="input-addon-clean">Rp</span>
                                    <input type="text" class="form-control-custom currency-field" id="selling_price" name="selling_price" 
                                           value="<?php echo !empty($formData['selling_price']) ? number_format((int)$formData['selling_price'], 0, ',', '.') : ''; ?>"
                                           placeholder="0" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 4: Informasi Garansi & Catatan -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <h5>Garansi & Catatan Tambahan</h5>
                            <p>Informasi perlindungan unit serta catatan kelengkapan atau riwayat</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="warranty_type" class="form-label-custom">Jenis Garansi</label>
                                <select class="form-select-custom" id="warranty_type" name="warranty_type">
                                    <option value="none" <?php echo ($formData['warranty_type'] ?? 'none') === 'none' ? 'selected' : ''; ?>>Tanpa Garansi</option>
                                    <option value="dealer" <?php echo ($formData['warranty_type'] ?? '') === 'dealer' ? 'selected' : ''; ?>>Garansi Showroom / Dealer</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-custom">
                                <label for="warranty_period" class="form-label-custom">Masa Garansi</label>
                                <div class="input-group-custom">
                                    <input type="number" class="form-control-custom has-addon-right" id="warranty_period" name="warranty_period" 
                                           value="<?php echo htmlspecialchars($formData['warranty_period'] ?? ''); ?>"
                                           min="1" max="120" placeholder="Contoh: 12">
                                    <span class="input-addon-clean input-addon-right">Bulan</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group-custom">
                        <label for="warranty_notes" class="form-label-custom">Cakupan / Syarat Garansi</label>
                        <input type="text" class="form-control-custom" id="warranty_notes" name="warranty_notes" 
                               value="<?php echo htmlspecialchars($formData['warranty_notes'] ?? ''); ?>"
                               placeholder="Contoh: Garansi mesin dan transmisi selama 12 bulan atau 10.000 KM">
                    </div>

                    <div class="form-group-custom mb-0">
                        <label for="notes" class="form-label-custom">Catatan Unit & Kelengkapan</label>
                        <textarea class="form-control-custom" id="notes" name="notes" rows="3"
                                  placeholder="Catatan tambahan: BPKB asli, kunci cadangan, buku manual, modifikasi minor..."><?php echo htmlspecialchars($formData['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

        </div>

        <!-- Sidebar Column (Right) -->
        <div class="col-lg-4">
            
            <!-- Sidebar Card 1: Foto Kendaraan -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-camera"></i>
                        </div>
                        <div>
                            <h5>Foto Utama Kendaraan</h5>
                            <p>Upload foto cover mobil</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="photo-upload-zone" id="photoDropZone">
                        <input type="file" name="image" id="vehicleImageInput" accept="image/jpeg,image/png,image/webp,image/gif">
                        <div class="upload-zone-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <div class="upload-zone-text">Pilih atau Drag Foto ke Sini</div>
                        <div class="upload-zone-sub">JPG, PNG, WEBP (Maksimal 3MB)</div>
                    </div>

                    <div class="photo-preview-box" id="photoPreviewContainer">
                        <img id="photoPreviewImg" src="" alt="Preview Foto">
                        <div class="photo-preview-actions">
                            <span class="photo-file-info" id="photoFileInfo">-</span>
                            <button type="button" class="btn-remove-photo" id="btnRemovePhoto">
                                <i class="fas fa-trash me-1"></i>Hapus
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar Card 2: Kalkulator Estimasi Profit -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <h5>Estimasi Profit & Margin</h5>
                            <p>Kalkulasi otomatis dari harga</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="calculator-stat-box">
                        <div class="stat-row">
                            <span class="stat-label">Estimasi Laba Kotor:</span>
                            <span class="stat-value" id="calcGrossProfit">Rp 0</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Margin Keuntungan:</span>
                            <span class="stat-value" id="calcMarginPercent">0%</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Status Margin:</span>
                            <span id="calcStatusBadge" class="stat-badge stat-badge-neutral">Menunggu Data</span>
                        </div>
                    </div>
                    <small class="text-muted" style="font-size:0.75rem;line-height:1.4;display:block;">
                        * Estimasi margin dihitung dari selisih Harga Jual terhadap Harga Beli sebelum biaya operasional tambahan.
                    </small>
                </div>
            </div>

            <!-- Sidebar Card 3: Ringkasan Validasi -->
            <div class="vehicle-form-card">
                <div class="card-header-clean">
                    <div class="header-title-group">
                        <div class="header-icon-badge">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <h5>Field Wajib</h5>
                            <p>Pastikan data pokok terisi lengkap</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <ul class="checklist-list">
                        <li class="checklist-item" id="checkItemModel">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Model Kendaraan</span>
                        </li>
                        <li class="checklist-item" id="checkItemYear">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Tahun Produksi</span>
                        </li>
                        <li class="checklist-item" id="checkItemChassis">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Nomor Rangka & Mesin</span>
                        </li>
                        <li class="checklist-item" id="checkItemColor">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Warna Kendaraan</span>
                        </li>
                        <li class="checklist-item" id="checkItemPrices">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Harga Beli & Harga Jual</span>
                        </li>
                        <li class="checklist-item" id="checkItemDate">
                            <i class="fas fa-circle checklist-icon"></i>
                            <span>Tanggal Pembelian</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Action Buttons Card -->
            <div class="vehicle-form-card">
                <div class="card-body">
                    <div class="d-flex flex-column gap-2">
                        <button type="submit" class="btn-submit-save" id="btnSubmitForm">
                            <i class="fas fa-save"></i>
                            <span>Simpan ke Stok Mobil</span>
                        </button>
                        <a href="<?php echo BASE_URL; ?>vehicles/" class="btn-submit-cancel">
                            <i class="fas fa-times"></i>
                            <span>Batal</span>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- Add Model Modal -->
<div class="modal fade" id="addModelModal" tabindex="-1" aria-labelledby="addModelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background:#f8fafc;">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:28px;height:28px;border-radius:6px;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:#334155;font-size:0.8rem;">
                        <i class="fas fa-plus"></i>
                    </div>
                    <h5 class="modal-title fw-bold text-dark fs-6" id="addModelModalLabel">Tambah Model Baru</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addModelForm" onsubmit="submitAddModel(event)">
                    <input type="hidden" name="action" value="ajax_add_model">
                    
                    <div class="form-group-custom">
                        <label class="form-label-custom">Merek Kendaraan <span class="req-mark">*</span></label>
                        <select class="form-select-custom" name="brand_id" id="modal_brand_id" required>
                             <option value="">Pilih Merek...</option>
                             <?php foreach ($brands as $b): ?>
                             <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group-custom">
                        <label class="form-label-custom">Nama Model <span class="req-mark">*</span></label>
                        <input type="text" class="form-control-custom" name="name" id="modal_model_name" required placeholder="Contoh: Avanza, Fortuner, Civic">
                    </div>
                    
                    <div class="form-group-custom">
                        <label class="form-label-custom">Jenis / Tipe Bodi <span class="req-mark">*</span></label>
                        <select class="form-select-custom" name="car_type_id" id="modal_car_type_id" required>
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
                        <button type="button" class="btn-submit-cancel" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn-submit-save" id="btnSubmitModal">
                            <i class="fas fa-save"></i>
                            <span>Simpan Model</span>
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
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Choices.js
    var modelChoices = null;
    const modelSelectEl = document.getElementById('model_id');
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
        updateValidationChecklist();
    };

    // 2. Currency Formatting
    document.querySelectorAll('.currency-field').forEach(function(input) {
        function formatMoney(val) {
            val = val.replace(/[^\d]/g, '');
            if (val) {
                return new Intl.NumberFormat('id-ID').format(val);
            }
            return '';
        }

        input.addEventListener('input', function(e) {
            let val = e.target.value;
            e.target.value = formatMoney(val);
            calculateProfit();
            updateValidationChecklist();
        });
    });

    // 3. Auto Uppercase text fields
    ['chassis_number', 'engine_number', 'license_plate'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
                updateValidationChecklist();
            });
        }
    });

    // 4. Live Photo Upload Preview
    const imageInput = document.getElementById('vehicleImageInput');
    const previewContainer = document.getElementById('photoPreviewContainer');
    const previewImg = document.getElementById('photoPreviewImg');
    const fileInfo = document.getElementById('photoFileInfo');
    const btnRemovePhoto = document.getElementById('btnRemovePhoto');
    const dropZone = document.getElementById('photoDropZone');

    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            handleFileSelect(this.files);
        });

        // Drag & Drop effects
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            }, false);
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
            }, false);
        });
        dropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                imageInput.files = e.dataTransfer.files;
                handleFileSelect(e.dataTransfer.files);
            }
        });
    }

    function handleFileSelect(files) {
        if (!files || !files[0]) return;
        const file = files[0];
        
        if (!file.type.match('image.*')) {
            alert('File yang dipilih harus berupa gambar (JPG, PNG, WEBP).');
            return;
        }

        if (file.size > 3 * 1024 * 1024) {
            alert('Ukuran file terlalu besar! Maksimal 3MB.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            fileInfo.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
            previewContainer.style.display = 'block';
            dropZone.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    if (btnRemovePhoto) {
        btnRemovePhoto.addEventListener('click', function() {
            imageInput.value = '';
            previewImg.src = '';
            previewContainer.style.display = 'none';
            dropZone.style.display = 'block';
        });
    }

    // 5. Profit & Margin Live Calculation
    function calculateProfit() {
        const purchaseInput = document.getElementById('purchase_price');
        const sellingInput = document.getElementById('selling_price');
        const grossEl = document.getElementById('calcGrossProfit');
        const marginEl = document.getElementById('calcMarginPercent');
        const statusBadge = document.getElementById('calcStatusBadge');

        const purchasePrice = parseInt(purchaseInput.value.replace(/[^\d]/g, '')) || 0;
        const sellingPrice = parseInt(sellingInput.value.replace(/[^\d]/g, '')) || 0;

        if (purchasePrice > 0 && sellingPrice > 0) {
            const profit = sellingPrice - purchasePrice;
            const margin = ((profit / purchasePrice) * 100).toFixed(1);

            grossEl.textContent = (profit < 0 ? '-' : '') + 'Rp ' + Math.abs(profit).toLocaleString('id-ID');
            marginEl.textContent = (profit < 0 ? '' : '+') + margin + '%';

            if (profit > 0) {
                if (margin >= 10) {
                    statusBadge.className = 'stat-badge stat-badge-positive';
                    statusBadge.textContent = 'Margin Sehat (' + margin + '%)';
                    grossEl.style.color = '#16a34a';
                } else {
                    statusBadge.className = 'stat-badge stat-badge-neutral';
                    statusBadge.textContent = 'Margin Rendah (' + margin + '%)';
                    grossEl.style.color = '#d97706';
                }
            } else if (profit === 0) {
                statusBadge.className = 'stat-badge stat-badge-neutral';
                statusBadge.textContent = 'Impas (BEP)';
                grossEl.style.color = '#475569';
            } else {
                statusBadge.className = 'stat-badge stat-badge-negative';
                statusBadge.textContent = 'Potensi Rugi';
                grossEl.style.color = '#dc2626';
            }
        } else {
            grossEl.textContent = 'Rp 0';
            marginEl.textContent = '0%';
            statusBadge.className = 'stat-badge stat-badge-neutral';
            statusBadge.textContent = 'Menunggu Data';
            grossEl.style.color = '';
        }
    }

    // 6. Validation Checklist Monitor
    function updateValidationChecklist() {
        const modelId = document.getElementById('model_id')?.value;
        const year = document.getElementById('year')?.value;
        const chassis = document.getElementById('chassis_number')?.value.trim();
        const engine = document.getElementById('engine_number')?.value.trim();
        const color = document.getElementById('color')?.value.trim();
        const purchase = document.getElementById('purchase_price')?.value.replace(/[^\d]/g, '');
        const selling = document.getElementById('selling_price')?.value.replace(/[^\d]/g, '');
        const dateVal = document.getElementById('purchase_date')?.value;

        setCheckItem('checkItemModel', !!modelId);
        setCheckItem('checkItemYear', year && parseInt(year) >= 1900);
        setCheckItem('checkItemChassis', chassis.length >= 5 && engine.length >= 5);
        setCheckItem('checkItemColor', color.length > 0 && /^[a-zA-Z\s]+$/.test(color));
        setCheckItem('checkItemPrices', parseInt(purchase) > 0 && parseInt(selling) > 0);
        setCheckItem('checkItemDate', !!dateVal);
    }

    function setCheckItem(elemId, isValid) {
        const el = document.getElementById(elemId);
        if (!el) return;
        const icon = el.querySelector('.checklist-icon');
        if (isValid) {
            el.classList.add('is-valid');
            if (icon) icon.className = 'fas fa-check-circle checklist-icon';
        } else {
            el.classList.remove('is-valid');
            if (icon) icon.className = 'fas fa-circle checklist-icon';
        }
    }

    // Bind inputs to checklist
    ['year', 'color', 'purchase_date'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateValidationChecklist);
    });

    // Initial triggers
    calculateProfit();
    updateValidationChecklist();

    // 7. Form Submission Validation
    const vehicleForm = document.getElementById('vehicleForm');
    if (vehicleForm) {
        vehicleForm.addEventListener('submit', function(e) {
            const modelId = document.getElementById('model_id').value;
            const year = document.getElementById('year').value;
            const chassis = document.getElementById('chassis_number').value.trim();
            const engine = document.getElementById('engine_number').value.trim();
            const color = document.getElementById('color').value.trim();
            const purchasePrice = parseInt(document.getElementById('purchase_price').value.replace(/[^\d]/g, '')) || 0;
            const sellingPrice = parseInt(document.getElementById('selling_price').value.replace(/[^\d]/g, '')) || 0;
            const purchaseDate = document.getElementById('purchase_date').value;

            if (!modelId) {
                e.preventDefault();
                alert('Silakan pilih model mobil terlebih dahulu.');
                return false;
            }

            const currentYear = new Date().getFullYear();
            if (!year || year < 1900 || year > currentYear + 1) {
                e.preventDefault();
                alert('Tahun produksi tidak valid! Masukkan antara 1900 - ' + (currentYear + 1));
                document.getElementById('year').focus();
                return false;
            }

            if (!chassis || chassis.length < 5) {
                e.preventDefault();
                alert('Nomor rangka (VIN) wajib diisi minimal 5 karakter!');
                document.getElementById('chassis_number').focus();
                return false;
            }

            if (!engine || engine.length < 5) {
                e.preventDefault();
                alert('Nomor mesin wajib diisi minimal 5 karakter!');
                document.getElementById('engine_number').focus();
                return false;
            }

            if (!color) {
                e.preventDefault();
                alert('Warna mobil wajib diisi!');
                document.getElementById('color').focus();
                return false;
            }

            if (!/^[a-zA-Z\s]+$/.test(color)) {
                e.preventDefault();
                alert('Warna hanya boleh berisi huruf (tidak boleh mengandung angka)!');
                document.getElementById('color').focus();
                return false;
            }

            if (purchasePrice <= 0) {
                e.preventDefault();
                alert('Harga beli harus lebih dari Rp 0!');
                document.getElementById('purchase_price').focus();
                return false;
            }

            if (sellingPrice <= 0) {
                e.preventDefault();
                alert('Harga jual harus lebih dari Rp 0!');
                document.getElementById('selling_price').focus();
                return false;
            }

            if (sellingPrice < purchasePrice) {
                if (!confirm("PERINGATAN:\nHarga jual lebih rendah dari harga beli (potensi kerugian).\n\nApakah Anda yakin ingin tetap menyimpan?")) {
                    e.preventDefault();
                    return false;
                }
            }

            if (!purchaseDate) {
                e.preventDefault();
                alert('Tanggal beli wajib diisi!');
                document.getElementById('purchase_date').focus();
                return false;
            }

            const btnSave = document.getElementById('btnSubmitForm');
            if (btnSave) {
                btnSave.disabled = true;
                btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Menyimpan...</span>';
            }
        });
    }
});

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
