<?php
// models/view.php - Detail Model Mobil
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
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

function translateCountry($c) {
    global $countryTranslations;
    return $countryTranslations[$c] ?? ($c ?: '-');
}

function translateStatus($s) {
    $map = ['active' => 'Aktif', 'inactive' => 'Tidak Aktif'];
    return $map[strtolower((string)$s)] ?? ucfirst((string)$s);
}

function translateVehicleStatus($s) {
    $map = ['available' => 'Tersedia', 'sold' => 'Terjual', 'maintenance' => 'Perbaikan', 'reserved' => 'Dipesan'];
    return $map[strtolower((string)$s)] ?? ucfirst((string)$s);
}

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID model tidak valid', 'danger');
    redirect('models/');
}

// Handle Edit Model POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_model') {
    if (!function_exists('sanitizeInput')) { 
        function sanitizeInput($s){ return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8'); } 
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = sanitizeInput($_POST['name'] ?? '');
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $car_type_id = (int)($_POST['car_type_id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid (CSRF)', 'danger');
    } elseif (empty($id) || empty($name) || empty($brand_id) || empty($car_type_id)) {
        showAlert('Nama model, merek, dan jenis mobil wajib diisi', 'danger');
    } else {
        try {
            $checkStmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ? AND id != ?");
            $checkStmt->execute([$name, $brand_id, $id]);
            if ($checkStmt->rowCount() > 0) {
                showAlert('Model mobil ini sudah ada untuk merek tersebut!', 'danger');
            } else {
                $stmt = $db->prepare("UPDATE models SET name = ?, brand_id = ?, car_type_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $brand_id, $car_type_id, $status, $id])) {
                    showAlert('Model mobil berhasil diperbarui!', 'success');
                    redirect('models/view.php?id=' . $id);
                } else {
                    showAlert('Gagal memperbarui data.', 'danger');
                }
            }
        } catch (PDOException $e) {
            showAlert('Database Error: ' . $e->getMessage(), 'danger');
        }
    }
}

try {
    // Get model with statistics
    $sql = "
        SELECT m.*,
               b.name as brand_name, b.country,
               ct.name as car_type_name,
               COUNT(v.id) as total_vehicles,
               SUM(CASE WHEN v.status = 'available' THEN 1 ELSE 0 END) as available_vehicles,
               SUM(CASE WHEN v.status = 'sold' THEN 1 ELSE 0 END) as sold_vehicles,
               SUM(CASE WHEN v.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_vehicles,
               SUM(CASE WHEN v.status = 'reserved' THEN 1 ELSE 0 END) as reserved_vehicles,
               MIN(v.selling_price) as min_price,
               MAX(v.selling_price) as max_price,
               AVG(v.selling_price) as avg_price
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN vehicles v ON m.id = v.model_id
        WHERE m.id = ?
        GROUP BY m.id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $model = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$model) {
        showAlert('Model mobil tidak ditemukan', 'danger');
        redirect('models/');
    }

    // Get vehicles
    $sql2 = "
        SELECT v.*
        FROM vehicles v
        WHERE v.model_id = ?
        ORDER BY v.year DESC, v.created_at DESC
        LIMIT 10
    ";

    $stmt = $db->prepare($sql2);
    $stmt->execute([$id]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('models/');
}

// Fetch brands and car types for Edit Modal
$brands = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$carTypes = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* ===== Model View Page Specific Styles (Matching Brands Design) ===== */
    .models-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .models-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .models-page-header .breadcrumb {
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
        background: #fff;
        border-color: #fecaca;
        color: #dc2626;
    }
    .btn-header-danger:hover {
        background: #fee2e2;
        border-color: #f87171;
        color: #b91c1c;
    }

    /* Cards */
    .view-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
        overflow: hidden;
    }
    .view-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
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

    /* Info Hero */
    .model-hero-icon {
        width: 72px;
        height: 72px;
        border-radius: 18px;
        background: #eff6ff;
        color: #2563eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        margin-bottom: 14px;
    }
    .model-brand-name {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
    }
    .model-hero-title {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e293b;
        margin: 2px 0 12px 0;
    }

    /* Key-Value Item */
    .info-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 16px;
    }
    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #f8fafc;
        font-size: 0.82rem;
    }
    .info-item:last-child { border-bottom: none; }
    .info-label { color: #64748b; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .info-value { color: #1e293b; font-weight: 700; }
    .info-timestamp-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        margin-top: 16px;
        font-size: 0.75rem;
    }

    /* KPI Mini Grid */
    .kpi-mini-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .kpi-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        text-align: center;
    }
    .kpi-mini-val {
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1.1;
        color: #1e293b;
    }
    .kpi-mini-lbl {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        margin-top: 4px;
    }

    /* Price Range Bar */
    .price-metric-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 8px;
        font-size: 0.8rem;
    }
    .price-metric-item:last-child { margin-bottom: 0; }

    /* Tables */
    .models-table {
        width: 100%;
        border-collapse: collapse;
    }
    .models-table thead th {
        padding: 10px 14px;
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
        padding: 11px 14px;
        font-size: 0.82rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .models-table tbody tr:hover { background: #f8fafc; }
    .models-table tbody tr:last-child td { border-bottom: none; }

    /* Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }
    .status-available { background: #dcfce7; color: #166534; }
    .status-sold { background: #fee2e2; color: #991b1b; }
    .status-maintenance { background: #fef3c7; color: #92400e; }
    .status-reserved { background: #e0f2fe; color: #075985; }

    .tag-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1d4ed8;
        border-radius: 6px;
        font-size: 0.74rem;
        font-weight: 700;
    }

    /* Modal Custom */
    .modal-custom .modal-content {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .modal-custom .modal-header { padding: 18px 22px 14px; border-bottom: none; }
    .modal-custom .modal-title { font-size: 1rem; font-weight: 700; color: #1e293b; }
    .modal-custom .modal-body { padding: 8px 22px 22px; }
    .modal-custom .modal-footer { padding: 14px 22px; background: #fafbfc; border-top: 1px solid #f1f5f9; }
    .form-label-sm { font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 5px; }
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

    /* Dark Mode Overrides */
    [data-theme="dark"] .models-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .view-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .model-hero-title,
    [data-theme="dark"] .info-value,
    [data-theme="dark"] .kpi-mini-val { color: #f1f5f9; }
    [data-theme="dark"] .model-hero-icon { background: rgba(59,130,246,0.15); color: #93c5fd; }
    [data-theme="dark"] .info-item { border-bottom-color: #1e293b; }
    [data-theme="dark"] .info-timestamp-box,
    [data-theme="dark"] .kpi-mini-card,
    [data-theme="dark"] .price-metric-item { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .models-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .models-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .models-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .status-active { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-inactive { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .tag-chip { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
    [data-theme="dark"] .modal-custom .modal-title { color: #f1f5f9; }
    [data-theme="dark"] .modal-custom .modal-footer { background: #162032; border-top-color: #334155; }
    [data-theme="dark"] .form-label-sm { color: #cbd5e1; }
    [data-theme="dark"] .btn-save { background: #3b82f6; }
    [data-theme="dark"] .btn-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }

    /* Responsive */
    @media (max-width: 576px) {
        .models-page-header { flex-direction: column; gap: 10px; }
        .header-actions { width: 100%; }
        .kpi-mini-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<!-- Page Header -->
<div class="models-page-header">
    <div>
        <h1>Detail Model: <?php echo htmlspecialchars($model['name']); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>models/" class="text-primary text-decoration-underline">Model Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($model['name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>models/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-header-action btn-header-primary" data-bs-toggle="modal" data-bs-target="#editModelModal">
            <i class="fas fa-edit"></i> Edit Model
        </button>
        <button type="button" class="btn-header-action btn-header-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
            <i class="fas fa-trash"></i> Hapus
        </button>
    </div>
</div>

<div class="row">
    <!-- Left Column: Info Card -->
    <div class="col-lg-4">
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-info-circle text-primary"></i> Informasi Model
                </h5>
            </div>
            <div class="view-card-body text-center">
                <div class="model-hero-icon">
                    <i class="fas fa-car"></i>
                </div>
                
                <div class="model-brand-name"><?php echo htmlspecialchars($model['brand_name'] ?? '-'); ?></div>
                <h3 class="model-hero-title"><?php echo htmlspecialchars($model['name']); ?></h3>
                
                <div class="d-flex justify-content-center gap-2 mb-3">
                    <span class="tag-chip">
                        <i class="fas fa-car-side"></i> <?php echo htmlspecialchars($model['car_type_name'] ?? '-'); ?>
                    </span>
                    <span class="status-badge <?php echo $model['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas <?php echo $model['status'] === 'active' ? 'fa-check' : 'fa-times'; ?>"></i>
                        <?php echo $model['status'] === 'active' ? 'Aktif' : 'Tidak Aktif'; ?>
                    </span>
                </div>
                
                <div class="info-list text-start">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-hashtag"></i> ID Model</span>
                        <span class="info-value">#<?php echo $model['id']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-globe"></i> Asal Negara</span>
                        <span class="info-value"><?php echo htmlspecialchars(translateCountry($model['country'])); ?></span>
                    </div>
                </div>

                <div class="info-timestamp-box text-start">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Ditambahkan</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($model['created_at'], 'd M Y H:i'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Terakhir Diperbarui</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($model['updated_at'], 'd M Y H:i'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Range Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-money-bill-wave text-success"></i> Kisaran Harga Stok
                </h5>
            </div>
            <div class="view-card-body">
                <?php if ($model['total_vehicles'] > 0): ?>
                    <div class="price-metric-item">
                        <span class="text-muted fw-semibold">Harga Terendah</span>
                        <span class="fw-bold text-success"><?php echo formatCurrency($model['min_price']); ?></span>
                    </div>
                    <div class="price-metric-item">
                        <span class="text-muted fw-semibold">Harga Rata-rata</span>
                        <span class="fw-bold text-primary"><?php echo formatCurrency($model['avg_price']); ?></span>
                    </div>
                    <div class="price-metric-item">
                        <span class="text-muted fw-semibold">Harga Tertinggi</span>
                        <span class="fw-bold text-danger"><?php echo formatCurrency($model['max_price']); ?></span>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3 text-muted" style="font-size: 0.8rem;">
                        <i class="fas fa-chart-line fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0">Belum ada data harga unit</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Stats & Vehicles List -->
    <div class="col-lg-8">
        <!-- KPI Stats Grid -->
        <div class="kpi-mini-grid">
            <div class="kpi-mini-card">
                <div class="kpi-mini-val text-primary"><?php echo number_format($model['total_vehicles'] ?? 0); ?></div>
                <div class="kpi-mini-lbl">Total Unit</div>
            </div>
            <div class="kpi-mini-card">
                <div class="kpi-mini-val text-success"><?php echo number_format($model['available_vehicles'] ?? 0); ?></div>
                <div class="kpi-mini-lbl">Tersedia</div>
            </div>
            <div class="kpi-mini-card">
                <div class="kpi-mini-val text-danger"><?php echo number_format($model['sold_vehicles'] ?? 0); ?></div>
                <div class="kpi-mini-lbl">Terjual</div>
            </div>
            <div class="kpi-mini-card">
                <div class="kpi-mini-val text-warning"><?php echo number_format(($model['maintenance_vehicles'] ?? 0) + ($model['reserved_vehicles'] ?? 0)); ?></div>
                <div class="kpi-mini-lbl">Perbaikan / Dipesan</div>
            </div>
        </div>

        <!-- Vehicles List Table Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-car text-primary"></i> Daftar Mobil dalam Stok
                </h5>
                <a href="<?php echo BASE_URL; ?>vehicles/create.php?model_id=<?php echo $model['id']; ?>" class="btn-header-action btn-header-primary" style="padding: 6px 12px; font-size: 0.75rem;">
                    <i class="fas fa-plus"></i> Tambah Mobil
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($vehicles)): ?>
                <div class="table-responsive">
                    <table class="models-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>No. Polisi</th>
                                <th>Warna</th>
                                <th>Tahun</th>
                                <th>Harga Jual</th>
                                <th>Status</th>
                                <th style="text-align:right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $index => $v): 
                                $vstatus = strtolower((string)($v['status'] ?? 'available'));
                                $vClass = 'status-available';
                                $vLabel = 'Tersedia';
                                if ($vstatus === 'sold') { $vClass = 'status-sold'; $vLabel = 'Terjual'; }
                                elseif ($vstatus === 'maintenance') { $vClass = 'status-maintenance'; $vLabel = 'Perbaikan'; }
                                elseif ($vstatus === 'reserved') { $vClass = 'status-reserved'; $vLabel = 'Dipesan'; }
                            ?>
                            <tr>
                                <td class="text-muted fw-semibold" style="font-size: 0.78rem;"><?php echo $index + 1; ?></td>
                                <td>
                                    <?php if (!empty($v['license_plate'])): ?>
                                        <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($v['license_plate']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="text-muted"><?php echo htmlspecialchars($v['color'] ?: '-'); ?></span></td>
                                <td><strong><?php echo $v['year'] ?: '-'; ?></strong></td>
                                <td><span class="fw-bold text-success"><?php echo formatCurrency($v['selling_price']); ?></span></td>
                                <td>
                                    <span class="status-badge <?php echo $vClass; ?>">
                                        <?php echo $vLabel; ?>
                                    </span>
                                </td>
                                <td style="text-align:right">
                                    <a href="<?php echo BASE_URL; ?>vehicles/view.php?id=<?php echo $v['id']; ?>" 
                                       class="btn-header-action" style="padding: 4px 8px; font-size: 0.72rem;" title="Lihat Detail Mobil">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($model['total_vehicles'] > 10): ?>
                <div class="p-3 text-center border-top">
                    <a href="<?php echo BASE_URL; ?>vehicles/?brand=<?php echo $model['brand_id']; ?>&type=<?php echo $model['car_type_id']; ?>&search=<?php echo urlencode($model['name']); ?>" 
                       class="btn-header-action" style="font-size: 0.78rem;">
                        <i class="fas fa-list me-1"></i> Lihat Semua <?php echo $model['total_vehicles']; ?> Unit Mobil
                    </a>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-car fa-3x text-muted opacity-25 mb-3"></i>
                    <h5 class="fw-bold text-dark" style="font-size: 0.95rem;">Belum Ada Unit Mobil</h5>
                    <p class="text-muted small mb-3">Belum ada mobil yang terdaftar dengan model ini.</p>
                    <a href="<?php echo BASE_URL; ?>vehicles/create.php?model_id=<?php echo $model['id']; ?>" class="btn-header-action btn-header-primary">
                        <i class="fas fa-plus me-1"></i> Tambah Unit Pertama
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit Model Modal -->
<div class="modal fade modal-custom" id="editModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Model Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_model">
                <input type="hidden" name="id" value="<?php echo $model['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="brand_id" required>
                             <option value="">-- Pilih Merek Mobil --</option>
                             <?php foreach ($brands as $b): ?>
                             <option value="<?php echo $b['id']; ?>" <?php echo $b['id'] == $model['brand_id'] ? 'selected' : ''; ?>>
                                 <?php echo htmlspecialchars($b['name']); ?>
                             </option>
                             <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-sm">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required value="<?php echo htmlspecialchars($model['name']); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jenis Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="car_type_id" required>
                             <option value="">-- Pilih Jenis Mobil --</option>
                             <?php foreach ($carTypes as $t): ?>
                             <option value="<?php echo $t['id']; ?>" <?php echo $t['id'] == $model['car_type_id'] ? 'selected' : ''; ?>>
                                 <?php echo htmlspecialchars($t['name']); ?>
                             </option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" <?php echo $model['status'] === 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="inactive" <?php echo $model['status'] === 'inactive' ? 'selected' : ''; ?>>Tidak Aktif</option>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade modal-custom" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus Model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-dark mb-3">Apakah Anda yakin ingin menghapus model mobil <strong><?php echo htmlspecialchars($model['brand_name'] . ' ' . $model['name']); ?></strong>?</p>
                
                <?php if ($model['total_vehicles'] > 0): ?>
                <div class="alert alert-warning border-0 small mb-0">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <strong>Peringatan:</strong> Model ini memiliki <strong><?php echo number_format($model['total_vehicles']); ?> unit mobil</strong> terkait.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="delete.php" style="display: inline;">
                    <input type="hidden" name="id" value="<?php echo $model['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <button type="submit" class="btn btn-danger px-3 py-2" style="border-radius: 10px; font-weight: 600; font-size: 0.82rem;">
                        <i class="fas fa-trash me-1"></i> Hapus Model
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>