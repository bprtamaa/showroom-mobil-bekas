<?php
// brands/view.php
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard/');
    exit();
}
include '../includes/header.php';
requireLogin();

$countryTranslations = [
    'Japan' => 'Jepang',
    'South Korea' => 'Korea Selatan',
    'Germany' => 'Jerman',
    'USA' => 'Amerika Serikat',
    'United States' => 'Amerika Serikat',
    'Italy' => 'Italia',
    'France' => 'Perancis',
    'United Kingdom' => 'Inggris',
    'UK' => 'Inggris',
    'China' => 'Tiongkok',
    'India' => 'India',
    'Malaysia' => 'Malaysia',
    'Thailand' => 'Thailand',
    'Sweden' => 'Swedia',
    'Indonesia' => 'Indonesia',
    'Netherlands' => 'Belanda',
    'Spain' => 'Spanyol',
    'Brazil' => 'Brasil',
    'Canada' => 'Kanada',
    'Australia' => 'Australia'
];

function translateCountry($c, $translations = []) {
    if (empty($c)) return '-';
    global $countryTranslations;
    $map = !empty($translations) ? $translations : $countryTranslations;
    return $map[$c] ?? $c;
}

function translateStatus($s) {
    $map = ['active' => 'Aktif', 'inactive' => 'Tidak Aktif'];
    return $map[strtolower((string)$s)] ?? ucfirst((string)$s);
}

$id = (int)($_GET['id'] ?? 0);

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', dirname(__DIR__) . '/uploads/');
}

// Handle Edit Brand POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_brand') {
    $id = (int)($_POST['id'] ?? 0);
    $formData = [
        'name' => sanitizeInput($_POST['name'] ?? ''),
        'country' => sanitizeInput($_POST['country'] ?? ''),
        'status' => $_POST['status'] ?? 'active'
    ];

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF)';
    } elseif (empty($id)) {
        $error = 'ID merek tidak valid';
    } elseif (empty($formData['name'])) {
        $error = 'Nama merek harus diisi';
    } else {
        try {
            $stmt = $db->prepare("SELECT id FROM brands WHERE name = ? AND id != ?");
            $stmt->execute([$formData['name'], $id]);
            if ($stmt->fetch()) {
                $error = 'Nama merek sudah digunakan';
            } else {
                $stmt = $db->prepare("SELECT logo FROM brands WHERE id = ?");
                $stmt->execute([$id]);
                $currentBrand = $stmt->fetch();
                $logoFileName = $currentBrand['logo'];

                if (isset($_POST['delete_logo']) && $_POST['delete_logo'] == '1') {
                    $uploadDir = UPLOADS_PATH . 'brands/';
                    if ($logoFileName && file_exists($uploadDir . $logoFileName)) {
                        @unlink($uploadDir . $logoFileName);
                    }
                    $logoFileName = '';
                }

                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = UPLOADS_PATH . 'brands/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        if ($logoFileName && file_exists($uploadDir . $logoFileName)) {
                            @unlink($uploadDir . $logoFileName);
                        }
                        
                        $newFileName = uniqid() . '.' . $fileExtension;
                        $uploadPath = $uploadDir . $newFileName;
                        
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                            $logoFileName = $newFileName;
                        } else {
                            $error = 'Gagal mengupload logo';
                        }
                    } else {
                        $error = 'Format logo harus JPG, JPEG, PNG, atau GIF';
                    }
                }

                if (empty($error)) {
                    $stmt = $db->prepare("
                        UPDATE brands 
                        SET name = ?, country = ?, logo = ?, status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([
                        $formData['name'],
                        $formData['country'],
                        $logoFileName,
                        $formData['status'],
                        $id
                    ])) {
                        showAlert('Merek berhasil diperbarui', 'success');
                        redirect('brands/view.php?id=' . $id);
                    } else {
                        $error = 'Gagal memperbarui data merek';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
    
    if (isset($error)) {
        showAlert($error, 'danger');
    }
}

// Handle Add/Edit Model POST within Brand Detail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_model', 'edit_model'])) {
    $action = $_POST['action'];
    $model_name = sanitizeInput($_POST['name'] ?? '');
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $car_type_id = (int)($_POST['car_type_id'] ?? 0);
    $model_status = $_POST['status'] ?? 'active';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid (CSRF)', 'danger');
    } elseif (empty($model_name) || empty($brand_id) || empty($car_type_id)) {
        showAlert('Nama model dan jenis mobil wajib diisi', 'danger');
    } else {
        try {
            if ($action === 'add_model') {
                $checkStmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ?");
                $checkStmt->execute([$model_name, $brand_id]);
                if ($checkStmt->rowCount() > 0) {
                    showAlert('Model mobil ini sudah ada untuk merek ini!', 'danger');
                } else {
                    $stmt = $db->prepare("INSERT INTO models (name, brand_id, car_type_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    if ($stmt->execute([$model_name, $brand_id, $car_type_id, $model_status])) {
                        showAlert('Model berhasil ditambahkan!', 'success');
                        redirect('brands/view.php?id=' . $brand_id);
                    } else {
                        showAlert('Gagal menambahkan model.', 'danger');
                    }
                }
            } elseif ($action === 'edit_model') {
                $model_id = (int)($_POST['model_id'] ?? 0);
                if (empty($model_id)) {
                    showAlert('ID Model tidak valid', 'danger');
                } else {
                    $checkStmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ? AND id != ?");
                    $checkStmt->execute([$model_name, $brand_id, $model_id]);
                    if ($checkStmt->rowCount() > 0) {
                        showAlert('Model mobil ini sudah ada untuk merek ini!', 'danger');
                    } else {
                        $stmt = $db->prepare("UPDATE models SET name = ?, brand_id = ?, car_type_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt->execute([$model_name, $brand_id, $car_type_id, $model_status, $model_id])) {
                            showAlert('Model berhasil diperbarui!', 'success');
                            redirect('brands/view.php?id=' . $brand_id);
                        } else {
                            showAlert('Gagal memperbarui data model.', 'danger');
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            showAlert('Database Error: ' . $e->getMessage(), 'danger');
        }
    }
}

if (!$id) {
    showAlert('ID merek tidak valid', 'danger');
    redirect('brands/');
}

try {
    $stmt = $db->prepare("
        SELECT b.*,
               COUNT(DISTINCT m.id) as total_models,
               COUNT(DISTINCT v.id) as total_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'available' THEN v.id END) as available_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'sold' THEN v.id END) as sold_vehicles
        FROM brands b
        LEFT JOIN models m ON b.id = m.brand_id
        LEFT JOIN vehicles v ON m.id = v.model_id
        WHERE b.id = ?
        GROUP BY b.id
    ");
    $stmt->execute([$id]);
    $brand = $stmt->fetch();
    
    if (!$brand) {
        showAlert('Merek tidak ditemukan', 'danger');
        redirect('brands/');
    }
    
    $stmt = $db->prepare("
        SELECT m.*, ct.name as car_type_name,
               COUNT(v.id) as vehicle_count
        FROM models m
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN vehicles v ON m.id = v.model_id
        WHERE m.brand_id = ?
        GROUP BY m.id
        ORDER BY m.name ASC
    ");
    $stmt->execute([$id]);
    $models = $stmt->fetchAll();
    
} catch (PDOException $e) {
    showAlert('Error loading brand data: ' . $e->getMessage(), 'danger');
    redirect('brands/');
}

$carTypes = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$uniqueCountries = array_unique($countryTranslations);
?>

<style>
    /* ===== Brand Detail Specific Styles (Matched with brands/index.php) ===== */
    .brands-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .brands-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .brands-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .btn-header-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: #fff;
        color: #475569;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-header-back:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
    }
    .btn-header-edit {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s, transform 0.15s;
    }
    .btn-header-edit:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-header-delete {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: #fee2e2;
        color: #dc2626;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 10px;
        border: 1px solid #fecaca;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-header-delete:hover {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    /* Cards */
    .brand-detail-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .brand-detail-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transform: none;
    }
    .brand-detail-card .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .brand-detail-card .card-header h5 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .brand-detail-card .card-body {
        padding: 20px;
    }

    /* Brand Hero Display inside Left Card */
    .brand-hero-box {
        text-align: center;
        padding-bottom: 16px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 16px;
    }
    .brand-logo-frame {
        width: 110px;
        height: 110px;
        margin: 0 auto 14px;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .brand-logo-frame img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    .brand-logo-frame-placeholder {
        width: 110px;
        height: 110px;
        margin: 0 auto 14px;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #cbd5e1;
        font-size: 2.5rem;
    }
    .brand-title-text {
        font-size: 1.3rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 8px;
        letter-spacing: -0.02em;
    }

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }

    /* Metadata List */
    .brand-meta-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .brand-meta-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 0.84rem;
    }
    .brand-meta-icon {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        font-size: 0.75rem;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .brand-meta-content {
        flex: 1;
    }
    .brand-meta-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        display: block;
        margin-bottom: 1px;
    }
    .brand-meta-val {
        font-weight: 600;
        color: #334155;
    }

    /* Stats Grid */
    .stats-2x2-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .stat-mini-box {
        padding: 12px 14px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        transition: all 0.15s;
    }
    .stat-mini-box:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }
    .stat-mini-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 6px;
    }
    .stat-mini-label {
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .stat-mini-icon {
        font-size: 0.8rem;
    }
    .stat-mini-number {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1;
    }

    /* Table Styling */
    .models-table {
        width: 100%;
        border-collapse: collapse;
    }
    .models-table thead th {
        padding: 11px 16px;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .models-table tbody td {
        padding: 13px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .models-table tbody tr {
        transition: background 0.15s;
    }
    .models-table tbody tr:hover {
        background: #f8fafc;
    }
    .models-table tbody tr:last-child td {
        border-bottom: none;
    }
    .col-no { width: 50px; text-align: center; }
    .col-no-val { color: #94a3b8; font-weight: 500; font-size: 0.8rem; text-align: center; }
    .model-name-text { font-weight: 700; color: #1e293b; }
    
    .unit-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        background: #e0f2fe;
        color: #0369a1;
    }

    .btn-add-model {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.78rem;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: background 0.2s, transform 0.15s;
    }
    .btn-add-model:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-add-model i { font-size: 0.7rem; }

    /* Action Buttons */
    .action-btns {
        display: flex;
        align-items: center;
        gap: 5px;
        justify-content: flex-end;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.75rem;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.15s;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #dbeafe; border-color: #93c5fd; color: #2563eb; }
    .action-btn-edit:hover { background: #e0e7ff; border-color: #a5b4fc; color: #4f46e5; }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 44px 20px;
    }
    .empty-state-icon {
        font-size: 2.2rem;
        color: #e2e8f0;
        margin-bottom: 10px;
    }
    .empty-state h5 {
        font-size: 0.92rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 4px;
    }
    .empty-state p {
        font-size: 0.8rem;
        color: #94a3b8;
        margin-bottom: 14px;
    }

    /* Modal Styling */
    .modal-custom .modal-content {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .modal-custom .modal-header {
        padding: 18px 22px 14px;
        border-bottom: none;
    }
    .modal-custom .modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }
    .modal-custom .modal-title i { color: #64748b; }
    .modal-custom .modal-body {
        padding: 8px 22px 22px;
    }
    .modal-custom .modal-footer {
        padding: 14px 22px;
        background: #fafbfc;
        border-top: 1px solid #f1f5f9;
    }
    .form-label-sm {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 5px;
    }
    .form-hint {
        font-size: 0.72rem;
        color: #94a3b8;
        margin-top: 3px;
    }
    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 20px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-save:hover { background: #1e272e; color: #fff; }
    .btn-cancel {
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-cancel:hover { background: #f1f5f9; color: #334155; }
    .logo-preview-box {
        display: none;
        margin-top: 8px;
        text-align: center;
        padding: 10px;
        background: #f8fafc;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
    }
    .logo-preview-box img { max-height: 80px; border-radius: 6px; }
    .current-logo-row {
        display: none;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        background: #f8fafc;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        margin-bottom: 10px;
    }
    .current-logo-row img {
        width: 44px;
        height: 44px;
        object-fit: contain;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 3px;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .brands-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-back { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-back:hover { background: #334155; color: #f1f5f9; }
    [data-theme="dark"] .btn-header-edit { background: #3b82f6; }
    [data-theme="dark"] .btn-header-edit:hover { background: #2563eb; }
    [data-theme="dark"] .btn-header-delete { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .btn-header-delete:hover { background: #ef4444; color: #fff; }
    [data-theme="dark"] .brand-detail-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .brand-detail-card .card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .brand-detail-card .card-header h5 { color: #f1f5f9; }
    [data-theme="dark"] .brand-hero-box { border-bottom-color: #334155; }
    [data-theme="dark"] .brand-logo-frame { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .brand-logo-frame-placeholder { background: #0f172a; border-color: #334155; color: #475569; }
    [data-theme="dark"] .brand-title-text { color: #f1f5f9; }
    [data-theme="dark"] .status-active { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-inactive { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .brand-meta-icon { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .brand-meta-val { color: #e2e8f0; }
    [data-theme="dark"] .stat-mini-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .stat-mini-box:hover { background: #162032; border-color: #475569; }
    [data-theme="dark"] .stat-mini-label { color: #94a3b8; }
    [data-theme="dark"] .stat-mini-number { color: #f1f5f9; }
    [data-theme="dark"] .models-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .models-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .models-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .model-name-text { color: #f1f5f9; }
    [data-theme="dark"] .unit-pill { background: rgba(59,130,246,0.15); color: #93c5fd; }
    [data-theme="dark"] .btn-add-model { background: #3b82f6; }
    [data-theme="dark"] .btn-add-model:hover { background: #2563eb; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #60a5fa; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.3); color: #a5b4fc; }
    [data-theme="dark"] .col-no-val { color: #64748b; }
    [data-theme="dark"] .empty-state-icon { color: #334155; }
    [data-theme="dark"] .empty-state h5 { color: #e2e8f0; }
    [data-theme="dark"] .empty-state p { color: #94a3b8; }
    [data-theme="dark"] .modal-custom .modal-title { color: #f1f5f9; }
    [data-theme="dark"] .modal-custom .modal-title i { color: #94a3b8; }
    [data-theme="dark"] .modal-custom .modal-footer { background: #162032; border-top-color: #334155; }
    [data-theme="dark"] .form-label-sm { color: #cbd5e1; }
    [data-theme="dark"] .form-hint { color: #64748b; }
    [data-theme="dark"] .btn-save { background: #3b82f6; }
    [data-theme="dark"] .btn-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .logo-preview-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .current-logo-row { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .current-logo-row img { border-color: #334155; background: #1e293b; }

    /* Responsive */
    @media (max-width: 576px) {
        .brands-page-header {
            flex-direction: column;
            gap: 10px;
        }
        .header-actions {
            width: 100%;
            justify-content: flex-start;
        }
        .btn-header-back, .btn-header-edit, .btn-header-delete {
            flex: 1;
            justify-content: center;
        }
        .stats-2x2-grid {
            grid-template-columns: 1fr;
        }
        .brand-detail-card .card-body {
            padding: 16px;
        }
        .models-table thead th,
        .models-table tbody td {
            padding: 10px 10px;
        }
    }
</style>

<!-- Page Header -->
<div class="brands-page-header">
    <div>
        <h1>Detail Merek</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>brands/" class="text-primary text-decoration-underline">Merek Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Detail Merek</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>brands/" class="btn-header-back">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" 
                class="btn-header-edit" 
                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($brand), ENT_QUOTES, "UTF-8"); ?>)'>
            <i class="fas fa-edit"></i> Edit
        </button>
        <button type="button" class="btn-header-delete" onclick="deleteBrand(<?php echo $brand['id']; ?>)">
            <i class="fas fa-trash"></i> Hapus
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Brand Info & Stats -->
    <div class="col-lg-4">
        <!-- Brand Information Card -->
        <div class="brand-detail-card card">
            <div class="card-header">
                <h5>Informasi Merek</h5>
            </div>
            <div class="card-body">
                <div class="brand-hero-box">
                    <?php if (!empty($brand['logo'])): ?>
                    <div class="brand-logo-frame">
                        <img src="<?php echo UPLOADS_URL . 'brands/' . $brand['logo']; ?>" 
                             alt="<?php echo htmlspecialchars($brand['name']); ?>">
                    </div>
                    <?php else: ?>
                    <div class="brand-logo-frame-placeholder">
                        <i class="fas fa-image"></i>
                    </div>
                    <?php endif; ?>
                    
                    <h3 class="brand-title-text"><?php echo htmlspecialchars($brand['name']); ?></h3>
                    
                    <?php
                    $bstatus = strtolower((string)($brand['status'] ?? ''));
                    $statusLabel = $bstatus === 'active' ? 'Aktif' : ($bstatus === 'inactive' ? 'Tidak Aktif' : ucfirst($brand['status']));
                    $statusClass = $bstatus === 'active' ? 'status-active' : 'status-inactive';
                    ?>
                    <span class="status-badge <?php echo $statusClass; ?>">
                        <?php echo $statusLabel; ?>
                    </span>
                </div>
                
                <div class="brand-meta-list">
                    <div class="brand-meta-item">
                        <div class="brand-meta-icon">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div class="brand-meta-content">
                            <span class="brand-meta-label">Negara Asal</span>
                            <span class="brand-meta-val"><?php echo htmlspecialchars(translateCountry($brand['country'], $countryTranslations)); ?></span>
                        </div>
                    </div>
                    
                    <div class="brand-meta-item">
                        <div class="brand-meta-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="brand-meta-content">
                            <span class="brand-meta-label">Dibuat</span>
                            <span class="brand-meta-val"><?php echo formatDate($brand['created_at']); ?></span>
                        </div>
                    </div>
                    
                    <div class="brand-meta-item">
                        <div class="brand-meta-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="brand-meta-content">
                            <span class="brand-meta-label">Terakhir Diperbarui</span>
                            <span class="brand-meta-val"><?php echo !empty($brand['updated_at']) ? formatDate($brand['updated_at']) : '-'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Card -->
        <div class="brand-detail-card card">
            <div class="card-header">
                <h5>Ringkasan Unit</h5>
            </div>
            <div class="card-body">
                <div class="stats-2x2-grid">
                    <div class="stat-mini-box">
                        <div class="stat-mini-header">
                            <span class="stat-mini-label">Total Model</span>
                            <i class="fas fa-list text-primary stat-mini-icon"></i>
                        </div>
                        <div class="stat-mini-number"><?php echo number_format($brand['total_models']); ?></div>
                    </div>

                    <div class="stat-mini-box">
                        <div class="stat-mini-header">
                            <span class="stat-mini-label">Total Unit</span>
                            <i class="fas fa-car text-info stat-mini-icon"></i>
                        </div>
                        <div class="stat-mini-number"><?php echo number_format($brand['total_vehicles']); ?></div>
                    </div>

                    <div class="stat-mini-box">
                        <div class="stat-mini-header">
                            <span class="stat-mini-label">Tersedia</span>
                            <i class="fas fa-check-circle text-success stat-mini-icon"></i>
                        </div>
                        <div class="stat-mini-number" style="color:#16a34a"><?php echo number_format($brand['available_vehicles']); ?></div>
                    </div>

                    <div class="stat-mini-box">
                        <div class="stat-mini-header">
                            <span class="stat-mini-label">Terjual</span>
                            <i class="fas fa-shopping-cart text-warning stat-mini-icon"></i>
                        </div>
                        <div class="stat-mini-number" style="color:#d97706"><?php echo number_format($brand['sold_vehicles']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Models List Table -->
    <div class="col-lg-8">
        <div class="brand-detail-card card">
            <div class="card-header">
                <div class="d-flex align-items-center gap-2">
                    <h5>Daftar Model</h5>
                    <span class="badge-modern bg-primary bg-opacity-10 text-primary" style="font-size:0.75rem"><?php echo count($models); ?> Model</span>
                </div>
                <button type="button" class="btn-add-model" data-bs-toggle="modal" data-bs-target="#addModelModal">
                    <i class="fas fa-plus"></i> Tambah Model
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($models)): ?>
                <div class="table-responsive">
                    <table class="models-table">
                        <thead>
                            <tr>
                                <th class="col-no">No</th>
                                <th>Nama Model</th>
                                <th>Jenis Mobil</th>
                                <th>Jumlah Unit</th>
                                <th>Status</th>
                                <th style="text-align:right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($models as $index => $model): ?>
                            <tr>
                                <td class="col-no-val"><?php echo $index + 1; ?></td>
                                <td>
                                    <span class="model-name-text"><?php echo htmlspecialchars($model['name']); ?></span>
                                </td>
                                <td>
                                    <span style="color:#64748b"><?php echo htmlspecialchars($model['car_type_name'] ?? '-'); ?></span>
                                </td>
                                <td>
                                    <span class="unit-pill"><?php echo $model['vehicle_count']; ?> unit</span>
                                </td>
                                <td>
                                    <?php
                                    $mstatus = strtolower((string)($model['status'] ?? ''));
                                    $mstatusLabel = $mstatus === 'active' ? 'Aktif' : ($mstatus === 'inactive' ? 'Tidak Aktif' : ucfirst($model['status']));
                                    $mstatusClass = $mstatus === 'active' ? 'status-active' : 'status-inactive';
                                    ?>
                                    <span class="status-badge <?php echo $mstatusClass; ?>">
                                        <?php echo $mstatusLabel; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="<?php echo BASE_URL; ?>models/view.php?id=<?php echo $model['id']; ?>" 
                                           class="action-btn action-btn-view" data-bs-toggle="tooltip" title="Lihat Model">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" 
                                                class="action-btn action-btn-edit" 
                                                onclick='openEditModelModal(<?php echo htmlspecialchars(json_encode($model), ENT_QUOTES, "UTF-8"); ?>)'
                                                data-bs-toggle="tooltip" title="Edit Model">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-list"></i></div>
                    <h5>Belum Ada Model Mobil</h5>
                    <p>Belum ada model yang ditambahkan untuk merek ini.</p>
                    <button type="button" class="btn-add-model" data-bs-toggle="modal" data-bs-target="#addModelModal">
                        <i class="fas fa-plus"></i> Tambah Model Pertama
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let editCountryChoices;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize Edit Modal Country Select
    const editCountrySelect = document.getElementById('edit_country');
    if (editCountrySelect) {
        editCountryChoices = new Choices(editCountrySelect, {
            itemSelectText: '',
            searchEnabled: true,
            shouldSort: false,
            position: 'bottom'
        });
    }
});

// Edit Modal functionality
function openEditModal(brand) {
    const modal = document.getElementById('editBrandModal');
    const form = document.getElementById('editBrandForm');
    
    // Set form values
    form.querySelector('[name="id"]').value = brand.id;
    form.querySelector('[name="name"]').value = brand.name;
    
    // Set country using Choices.js if available
    if (editCountryChoices) {
        editCountryChoices.setChoiceByValue(brand.country);
    } else {
        form.querySelector('[name="country"]').value = brand.country;
    }

    form.querySelector('[name="status"]').value = brand.status;
    
    // Handle logo preview
    const currentLogoContainer = document.getElementById('currentLogoContainer');
    const currentLogo = document.getElementById('currentLogo');
    
    if (brand.logo) {
        currentLogo.src = '<?php echo UPLOADS_URL . "brands/"; ?>' + brand.logo;
        currentLogoContainer.style.display = 'flex';
    } else {
        currentLogoContainer.style.display = 'none';
    }
    
    // Reset file input and delete checkbox
    form.querySelector('[name="logo"]').value = '';
    document.getElementById('editLogoPreviewContainer').style.display = 'none';
    document.getElementById('delete_logo').checked = false;
    
    new bootstrap.Modal(modal).show();
}

function openEditModelModal(model) {
    const modal = document.getElementById('editModelModal');
    const form = document.getElementById('editModelForm');
    
    form.querySelector('[name="model_id"]').value = model.id;
    form.querySelector('[name="name"]').value = model.name;
    form.querySelector('[name="car_type_id"]').value = model.car_type_id;
    form.querySelector('[name="status"]').value = model.status;
    
    new bootstrap.Modal(modal).show();
}

// Edit Modal Logo Preview
document.getElementById('edit_logo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const container = document.getElementById('editLogoPreviewContainer');
    const preview = document.getElementById('editLogoPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        container.style.display = 'none';
    }
});

function deleteBrand(id) {
    if (confirmDelete('Apakah Anda yakin ingin menghapus merek ini? Semua model dan mobil terkait juga akan terhapus.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        
        form.appendChild(idInput);
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- Edit Brand Modal -->
<div class="modal fade modal-custom" id="editBrandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Merek</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editBrandForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_brand">
                    <input type="hidden" name="id" value="<?php echo $brand['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label form-label-sm">Nama Merek <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_country" class="form-label form-label-sm">Negara Asal</label>
                        <select class="form-select" id="edit_country" name="country">
                            <option value="">-- Pilih Negara --</option>
                            <?php foreach ($uniqueCountries as $code => $name): ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Logo Saat Ini</label>
                        <div id="currentLogoContainer" class="current-logo-row">
                            <img id="currentLogo" src="#" alt="Current Logo">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="delete_logo" name="delete_logo" value="1">
                                <label class="form-check-label text-danger" for="delete_logo" style="font-size:0.8rem">
                                    Hapus logo ini
                                </label>
                            </div>
                        </div>
                        
                        <label for="edit_logo" class="form-label form-label-sm">Ganti Logo</label>
                        <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                        <div class="form-hint">Format: JPG, PNG, GIF. Maks 2MB</div>
                        <div class="logo-preview-box" id="editLogoPreviewContainer">
                            <img id="editLogoPreview" src="#" alt="Preview">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label form-label-sm">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Model Modal -->
<div class="modal fade modal-custom" id="addModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Model Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addModelForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_model">
                    <input type="hidden" name="brand_id" value="<?php echo $brand['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="Contoh: Seri 3 / 320i Sport">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jenis Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="car_type_id" required>
                            <option value="">-- Pilih Jenis Mobil --</option>
                            <?php foreach ($carTypes as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" selected>Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Model
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Model Modal -->
<div class="modal fade modal-custom" id="editModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editModelForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_model">
                    <input type="hidden" name="model_id" value="">
                    <input type="hidden" name="brand_id" value="<?php echo $brand['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jenis Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="car_type_id" required>
                            <option value="">-- Pilih Jenis Mobil --</option>
                            <?php foreach ($carTypes as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Status</label>
                        <select class="form-select" name="status">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
