<?php
// car_types/view.php - Detail Jenis Mobil & Daftar Model
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

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID jenis mobil tidak valid', 'danger');
    redirect('car_types/');
}

try {
    // Get car type data with statistics
    $stmt = $db->prepare("
        SELECT ct.*,
               COUNT(DISTINCT m.id) as total_models,
               COUNT(DISTINCT v.id) as total_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'available' THEN v.id END) as available_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'sold' THEN v.id END) as sold_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'maintenance' THEN v.id END) as maintenance_vehicles,
               COUNT(DISTINCT CASE WHEN v.status = 'reserved' THEN v.id END) as reserved_vehicles
        FROM car_types ct
        LEFT JOIN models m ON ct.id = m.car_type_id
        LEFT JOIN vehicles v ON m.id = v.model_id
        WHERE ct.id = ?
        GROUP BY ct.id
    ");
    $stmt->execute([$id]);
    $carType = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$carType) {
        showAlert('Jenis mobil tidak ditemukan di database.', 'danger');
        redirect('car_types/');
    }
    
    // Get models of this car type
    $stmt = $db->prepare("
        SELECT m.*, 
               b.name as brand_name,
               COUNT(v.id) as vehicle_count,
               SUM(CASE WHEN v.status = 'available' THEN 1 ELSE 0 END) as available_count
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN vehicles v ON m.id = v.model_id
        WHERE m.car_type_id = ?
        GROUP BY m.id
        ORDER BY b.name ASC, m.name ASC
    ");
    $stmt->execute([$id]);
    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all brands for the Add Model modal
    $allBrandsStmt = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
    $allBrands = $allBrandsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    showAlert('Error loading car type data: ' . $e->getMessage(), 'danger');
    redirect('car_types/');
}

// Handle Form Submission for Edit Car Type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_type') {
    $typeId = (int)($_POST['id'] ?? 0);
    $name = sanitizeInput($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'active';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF)';
    } elseif (empty($typeId) || empty($name)) {
        $error = 'Nama jenis mobil harus diisi';
    } else {
        try {
            $stmt = $db->prepare("SELECT id FROM car_types WHERE name = ? AND id != ?");
            $stmt->execute([$name, $typeId]);
            if ($stmt->fetch()) {
                $error = 'Nama jenis mobil sudah digunakan';
            } else {
                $stmt = $db->prepare("UPDATE car_types SET name = ?, status = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $status, $typeId])) {
                    showAlert('Jenis mobil berhasil diperbarui', 'success');
                    redirect('car_types/view.php?id=' . $typeId);
                } else {
                    $error = 'Gagal memperbarui data jenis mobil';
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

// Handle Add/Edit Model POST within Car Type Detail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_model', 'edit_model'])) {
    $action = $_POST['action'];
    $model_name = sanitizeInput($_POST['name'] ?? '');
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $car_type_id = $id;
    $model_status = $_POST['status'] ?? 'active';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid (CSRF)', 'danger');
    } elseif (empty($model_name) || empty($brand_id)) {
        showAlert('Nama model dan merek mobil wajib diisi', 'danger');
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
                        redirect('car_types/view.php?id=' . $car_type_id);
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
                        $stmt = $db->prepare("UPDATE models SET name = ?, brand_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt->execute([$model_name, $brand_id, $model_status, $model_id])) {
                            showAlert('Model berhasil diperbarui!', 'success');
                            redirect('car_types/view.php?id=' . $car_type_id);
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
?>

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
    .header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
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
        transform: translateY(-1px);
    }
    .btn-header-primary {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }
    .btn-header-primary:hover {
        background: #1e272e;
        color: #fff;
        border-color: #1e272e;
    }
    .btn-header-danger {
        color: #dc2626;
        border-color: #fecaca;
        background: #fff;
    }
    .btn-header-danger:hover {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fca5a5;
    }

    /* Cards */
    .view-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
    }
    .view-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .view-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .view-card-body {
        padding: 20px;
    }

    /* Type Avatar */
    .type-avatar-box {
        width: 64px;
        height: 64px;
        border-radius: 14px;
        background: #f1f5f9;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        margin-bottom: 12px;
        border: 1px solid #e2e8f0;
    }

    .detail-section-title {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.05em;
        margin-bottom: 12px;
    }

    /* 2x2 Stats Grid */
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
    .stat-mini-label {
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 4px;
    }
    .stat-mini-val {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.1;
    }

    /* Table Card */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 20px;
    }
    .table-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .table-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .table-card-controls {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .record-count {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
    }

    /* Vehicles Table */
    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 10px 10px;
        font-size: 0.68rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .vehicles-table tbody td {
        padding: 10px 10px;
        font-size: 0.82rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .status-active, .status-available { background: #dcfce7; color: #166534; }
    .status-inactive, .status-sold { background: #fee2e2; color: #991b1b; }
    .status-code { background: #e0f2fe; color: #075985; }

    .brand-tag-badge {
        display: inline-block;
        padding: 2px 7px;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }

    /* Action Buttons */
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
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .view-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header,
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .table-card-title,
    [data-theme="dark"] .stat-mini-val { color: #f1f5f9; }
    [data-theme="dark"] .type-avatar-box { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .stat-mini-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .brand-tag-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1>Detail Jenis Mobil</h1>
            <span class="status-badge status-code fw-bold">#<?php echo $carType['id']; ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>car_types/" class="text-primary text-decoration-underline">Jenis Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($carType['name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>car_types/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-header-action btn-header-primary" data-bs-toggle="modal" data-bs-target="#editTypeModal">
            <i class="fas fa-edit"></i> Edit Jenis
        </button>
        <button type="button" class="btn-header-action btn-header-danger" onclick="deleteType(<?php echo $carType['id']; ?>)">
            <i class="fas fa-trash"></i> Hapus
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Car Type Profile & Stats -->
    <div class="col-12 col-xl-4 col-lg-5">
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-car-side text-primary"></i> Informasi Jenis Mobil
                </h5>
            </div>
            <div class="view-card-body">
                <!-- Avatar & Identity -->
                <div class="text-center pb-3 border-bottom">
                    <div class="type-avatar-box">
                        <i class="fas fa-car-side"></i>
                    </div>
                    <h4 class="fw-bold text-dark fs-5 mb-1"><?php echo htmlspecialchars($carType['name']); ?></h4>
                    <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
                        <?php if ($carType['status'] === 'active'): ?>
                            <span class="status-badge status-active">Aktif</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">Tidak Aktif</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metadata -->
                <div class="pt-3 pb-3 border-bottom">
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>ID Jenis Mobil:</span>
                        <span class="fw-bold text-primary">#<?php echo $carType['id']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>Dibuat Pada:</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($carType['created_at'], 'd M Y'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Pembaruan:</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($carType['updated_at'], 'd M Y'); ?></span>
                    </div>
                </div>

                <!-- 2x2 Mini Stats Grid -->
                <div class="pt-3">
                    <div class="detail-section-title">Statistik Unit Kendaraan</div>
                    <div class="stats-2x2-grid">
                        <div class="stat-mini-box">
                            <div class="stat-mini-label">Total Model</div>
                            <div class="stat-mini-val"><?php echo number_format($carType['total_models']); ?></div>
                        </div>
                        <div class="stat-mini-box">
                            <div class="stat-mini-label">Total Unit</div>
                            <div class="stat-mini-val"><?php echo number_format($carType['total_vehicles']); ?></div>
                        </div>
                        <div class="stat-mini-box">
                            <div class="stat-mini-label">Unit Tersedia</div>
                            <div class="stat-mini-val text-success"><?php echo number_format($carType['available_vehicles']); ?></div>
                        </div>
                        <div class="stat-mini-box">
                            <div class="stat-mini-label">Unit Terjual</div>
                            <div class="stat-mini-val text-muted"><?php echo number_format($carType['sold_vehicles']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Models Table -->
    <div class="col-12 col-xl-8 col-lg-7">
        <div class="table-card card">
            <div class="table-card-header">
                <h5 class="table-card-title">Daftar Model Kendaraan</h5>
                <div class="table-card-controls">
                    <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                        <?php echo count($models); ?> Model
                    </span>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModelModal" style="background:#2d3436;border:none;border-radius:8px;font-size:0.78rem;font-weight:600;padding:6px 12px;">
                        <i class="fas fa-plus me-1"></i> Tambah Model
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($models)): ?>
                <div class="table-responsive">
                    <table class="vehicles-table">
                        <thead>
                            <tr>
                                <th style="width: 32px;" class="text-center">NO</th>
                                <th>MEREK MOBIL</th>
                                <th>NAMA MODEL</th>
                                <th class="text-center">JUMLAH UNIT</th>
                                <th class="text-center">TERSEDIA</th>
                                <th>STATUS</th>
                                <th class="text-center" style="width: 60px;">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($models as $m): 
                            ?>
                            <tr>
                                <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                                <td>
                                    <span class="brand-tag-badge"><?php echo htmlspecialchars($m['brand_name']); ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($m['name']); ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-dark"><?php echo number_format($m['vehicle_count']); ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-success"><?php echo number_format($m['available_count'] ?? 0); ?></span>
                                </td>
                                <td>
                                    <?php if ($m['status'] === 'active'): ?>
                                        <span class="status-badge status-active">Aktif</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">Non-Aktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <button type="button" class="action-btn action-btn-edit" title="Edit Model" onclick='openEditModelModal(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES, "UTF-8"); ?>)'>
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
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-car-side fa-2x text-muted mb-2 d-block"></i>
                    <div class="fw-semibold">Belum ada model kendaraan untuk jenis ini.</div>
                    <small class="text-muted">Klik tombol Tambah Model di pojok kanan atas untuk mendaftarkan model baru.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit Car Type Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">Edit Jenis Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_type">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo $carType['id']; ?>">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold small text-secondary">Nama Jenis Mobil <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" value="<?php echo htmlspecialchars($carType['name']); ?>" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label fw-bold small text-secondary">Status</label>
                        <select class="form-select" id="edit_status" name="status" style="border-radius:9px;">
                            <option value="active" <?php echo $carType['status'] === 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="inactive" <?php echo $carType['status'] === 'inactive' ? 'selected' : ''; ?>>Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Model Modal -->
<div class="modal fade" id="addModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">Tambah Model Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_model">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="add_brand_id" class="form-label fw-bold small text-secondary">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" id="add_brand_id" name="brand_id" required style="border-radius:9px;">
                            <option value="">-- Pilih Merek Mobil --</option>
                            <?php foreach ($allBrands as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_model_name" class="form-label fw-bold small text-secondary">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_model_name" name="name" required placeholder="Contoh: Avanza, Brio, Civic" style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_model_status" class="form-label fw-bold small text-secondary">Status Model</label>
                        <select class="form-select" id="add_model_status" name="status" style="border-radius:9px;">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i> Simpan Model
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Model Modal -->
<div class="modal fade" id="editModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">Edit Model Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_model">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="model_id" id="edit_model_id">
                    
                    <div class="mb-3">
                        <label for="edit_model_brand_id" class="form-label fw-bold small text-secondary">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_model_brand_id" name="brand_id" required style="border-radius:9px;">
                            <?php foreach ($allBrands as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_model_name_input" class="form-label fw-bold small text-secondary">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_model_name_input" name="name" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_model_status_select" class="form-label fw-bold small text-secondary">Status Model</label>
                        <select class="form-select" id="edit_model_status_select" name="status" style="border-radius:9px;">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function deleteType(id) {
    if (confirm('Apakah Anda yakin ingin menghapus jenis mobil ini?')) {
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

function openEditModelModal(model) {
    document.getElementById('edit_model_id').value = model.id;
    document.getElementById('edit_model_brand_id').value = model.brand_id;
    document.getElementById('edit_model_name_input').value = model.name;
    document.getElementById('edit_model_status_select').value = model.status || 'active';
    
    var modal = new bootstrap.Modal(document.getElementById('editModelModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
