<?php
// vehicles/view.php - Detail Mobil
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID kendaraan tidak valid', 'danger');
    redirect('vehicles/');
}

// Handle maintenance form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_maintenance') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token validasi tidak valid.', 'danger');
    } else {
        $vehicle_id = (int)$_POST['vehicle_id'];
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $cost = (float)str_replace(['Rp', '.', ' '], '', $_POST['cost']);
        $status = $_POST['status'];
        $service_type = $_POST['service_type'] ?? 'routine';
        $notes = sanitizeInput($_POST['notes']);
        
        $checkStmt = $db->prepare("SELECT status, warranty_type FROM vehicles WHERE id = ?");
        $checkStmt->execute([$vehicle_id]);
        $vCheck = $checkStmt->fetch();

        if ($vCheck && $vCheck['status'] === 'sold' && (empty($vCheck['warranty_type']) || $vCheck['warranty_type'] === 'none')) {
            showAlert('Gagal! Mobil sudah terjual dan tidak memiliki garansi aktif. Perawatan tidak dapat ditambahkan.', 'danger');
            redirect('vehicles/view.php?id=' . $vehicle_id);
        }
        
        if (empty($vehicle_id) || empty($start_date)) {
            showAlert('Mohon lengkapi field yang wajib diisi.', 'danger');
        } else {
            try {
                $sql = "INSERT INTO maintenance (vehicle_id, service_type, start_date, end_date, cost, status, notes, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $db->prepare($sql);
                $stmt->execute([$vehicle_id, $service_type, $start_date, $end_date, $cost, $status, $notes]);
                
                $needsMaintenanceStatus = false;
                if (!empty($end_date) && !empty($start_date)) {
                    $startDateTime = new DateTime($start_date);
                    $endDateTime = new DateTime($end_date);
                    $daysDifference = $startDateTime->diff($endDateTime)->days;
                    if ($daysDifference > 1) {
                        $needsMaintenanceStatus = true;
                    }
                }
                
                if ($status === 'in_progress' || $needsMaintenanceStatus) {
                    $checkV = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
                    $checkV->execute([$vehicle_id]);
                    $vInfo = $checkV->fetch();
                    if ($vInfo && $vInfo['status'] !== 'sold') {
                        $updateV = $db->prepare("UPDATE vehicles SET status = 'maintenance' WHERE id = ?");
                        $updateV->execute([$vehicle_id]);
                    }
                }
                
                showAlert('Data perawatan berhasil ditambahkan.', 'success');
                redirect('vehicles/view.php?id=' . $vehicle_id);
                
            } catch (PDOException $e) {
                showAlert('Gagal menyimpan data: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

try {
    $stmt = $db->prepare("
        SELECT v.*,
               m.name as model_name, 
               b.name as brand_name, b.country,
               ct.name as car_type_name
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        WHERE v.id = ?
    ");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        showAlert('Kendaraan tidak ditemukan', 'danger');
        redirect('vehicles/');
    }
    
    // Get transaction history if sold
    $transaction = null;
    if ($vehicle['status'] == 'sold') {
        $stmt = $db->prepare("
            SELECT t.*, c.name as customer_name, u.full_name as user_name
            FROM transactions t
            LEFT JOIN customers c ON t.customer_id = c.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.vehicle_id = ?
            ORDER BY t.transaction_date DESC
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get latest inspection data
    require_once '../includes/inspection_helpers.php';
    $inspStmt = $db->prepare("SELECT * FROM vehicle_inspections WHERE vehicle_id = ? ORDER BY inspection_date DESC, id DESC LIMIT 1");
    $inspStmt->execute([$id]);
    $vehicleInspection = $inspStmt->fetch(PDO::FETCH_ASSOC);

    // Get vehicle documents tracking
    require_once '../includes/document_helpers.php';
    $docStmt = $db->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ? LIMIT 1");
    $docStmt->execute([$id]);
    $vehicleDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('vehicles/');
}

$statusLabels = [
    'available' => 'Tersedia',
    'sold' => 'Terjual',
    'maintenance' => 'Perbaikan',
    'reserved' => 'Dipesan'
];
$vstatus = strtolower((string)($vehicle['status'] ?? 'available'));
$vClass = 'status-available';
if ($vstatus === 'sold') $vClass = 'status-sold';
elseif ($vstatus === 'maintenance') $vClass = 'status-maintenance';
elseif ($vstatus === 'reserved') $vClass = 'status-reserved';

$conditionLabels = [
    'excellent' => 'Sangat Baik',
    'good' => 'Baik',
    'fair' => 'Cukup',
    'poor' => 'Kurang'
];

$fuelLabels = [
    'gasoline' => 'Bensin',
    'petrol' => 'Bensin',
    'diesel' => 'Diesel',
    'hybrid' => 'Hybrid',
    'electric' => 'Listrik'
];

$transmissionLabels = [
    'manual' => 'Manual',
    'automatic' => 'Otomatis',
    'cvt' => 'CVT'
];
?>

<style>
    /* ===== Vehicle View Page Specific Styles (Matching Brands Design) ===== */
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
    .btn-header-success {
        background: #166534;
        color: #fff;
        border-color: #166534;
    }
    .btn-header-success:hover {
        background: #14532d;
        color: #fff;
        border-color: #14532d;
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

    /* Hero Photo */
    .vehicle-photo-wrap {
        position: relative;
        width: 100%;
        aspect-ratio: 16/9;
        background: #f8fafc;
        overflow: hidden;
    }
    .vehicle-photo-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

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

    /* Key Value List */
    .info-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
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

    /* Quick Credit Widget */
    .credit-widget {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 16px;
        margin-top: 14px;
    }

    /* Number Box */
    .number-display-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 4px;
    }
    .btn-copy {
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #64748b;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-copy:hover { background: #f1f5f9; color: #1e293b; }

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
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .view-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .info-value { color: #f1f5f9; }
    [data-theme="dark"] .vehicle-photo-wrap { background: #0f172a; }
    [data-theme="dark"] .info-item { border-bottom-color: #1e293b; }
    [data-theme="dark"] .credit-widget,
    [data-theme="dark"] .number-display-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .btn-copy { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .status-available { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-sold { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .status-maintenance { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .status-reserved { background: rgba(14,165,233,0.15); color: #7dd3fc; }
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
        .vehicles-page-header { flex-direction: column; gap: 10px; }
        .header-actions { width: 100%; }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <h1 class="mb-0">Detail Mobil: <?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model_name']); ?></h1>
            <?php if (!empty($vehicle['is_consignment'])): ?>
                <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:0.75rem; font-weight:700; padding:4px 8px; border-radius:6px;">
                    <i class="fas fa-handshake me-1"></i>Mobil Titip Jual
                </span>
                <?php if (!empty($vehicle['consignment_id'])): ?>
                    <a href="<?php echo BASE_URL; ?>consignments/view.php?id=<?php echo $vehicle['consignment_id']; ?>" class="badge bg-primary text-white text-decoration-none" style="font-size:0.75rem; padding:4px 8px; border-radius:6px;">
                        <i class="fas fa-file-contract me-1"></i>Lihat Perjanjian Titip Jual
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/" class="text-primary text-decoration-underline">Stok Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model_name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>vehicles/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-header-action" data-bs-toggle="modal" data-bs-target="#whatsappShareModal">
            <i class="fab fa-whatsapp text-success"></i> <span>Kirim ke WhatsApp</span>
        </button>
        <?php if ($vehicle['status'] === 'available'): ?>
        <a href="<?php echo BASE_URL; ?>bookings/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action">
            <i class="fas fa-file-signature text-primary"></i> <span>Buat SPK</span>
        </a>
        <?php elseif ($vehicle['status'] === 'reserved'): ?>
        <a href="<?php echo BASE_URL; ?>bookings/" class="btn-header-action">
            <i class="fas fa-clock text-warning"></i> <span>Unit Di-Hold (SPK)</span>
        </a>
        <?php endif; ?>
        <button type="button" class="btn-header-action" data-bs-toggle="modal" data-bs-target="#marketplaceModal">
            <i class="fas fa-bullhorn text-muted"></i> <span>Iklan Marketplace</span>
        </button>
        <a href="<?php echo BASE_URL; ?>leasing/simulation.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action">
            <i class="fas fa-calculator"></i> Simulasi Kredit
        </a>
        <a href="edit.php?id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-primary">
            <i class="fas fa-edit"></i> Edit Mobil
        </a>
        <?php if ($vehicle['status'] === 'available'): ?>
        <a href="<?php echo BASE_URL; ?>transactions/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-success">
            <i class="fas fa-shopping-cart"></i> Jual Mobil
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <!-- Left Column: Image, Specs & Price -->
    <div class="col-lg-4">
        <!-- Vehicle Hero Card -->
        <div class="view-card card">
            <?php
            $uploadUrl = BASE_URL . 'uploads/vehicles/';
            $currentImage = null;
            if (!empty($vehicle['images'])) {
                $decoded = json_decode($vehicle['images'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $currentImage = $decoded[0];
                }
            }
            ?>
            <div class="vehicle-photo-wrap">
                <?php if ($currentImage): ?>
                    <img src="<?php echo $uploadUrl . htmlspecialchars($currentImage); ?>" alt="Foto Kendaraan">
                <?php else: ?>
                    <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                        <i class="fas fa-car fa-4x text-secondary opacity-25"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="view-card-body text-center">
                <div class="small text-muted fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;"><?php echo htmlspecialchars($vehicle['brand_name']); ?></div>
                <h3 class="fw-bold mb-2 text-dark" style="font-size: 1.25rem;"><?php echo htmlspecialchars($vehicle['model_name']); ?></h3>
                
                <div class="d-flex justify-content-center gap-2 mb-3">
                    <span class="tag-chip">
                        <i class="fas fa-car-side"></i> <?php echo htmlspecialchars($vehicle['car_type_name'] ?? '-'); ?>
                    </span>
                    <span class="status-badge <?php echo $vClass; ?>">
                        <?php echo $statusLabels[$vehicle['status']] ?? ucfirst($vehicle['status']); ?>
                    </span>
                </div>

                <div class="info-list text-start">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-calendar-alt"></i> Tahun Pembuatan</span>
                        <span class="info-value"><?php echo $vehicle['year'] ?: '-'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-palette"></i> Warna Kendaraan</span>
                        <span class="info-value"><?php echo htmlspecialchars($vehicle['color'] ?: '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-tachometer-alt"></i> Jarak Tempuh (KM)</span>
                        <span class="info-value"><?php echo number_format($vehicle['mileage']); ?> km</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-gas-pump"></i> Bahan Bakar</span>
                        <span class="info-value"><?php echo $fuelLabels[$vehicle['fuel_type']] ?? ucfirst($vehicle['fuel_type'] ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-cog"></i> Transmisi</span>
                        <span class="info-value"><?php echo $transmissionLabels[$vehicle['transmission']] ?? ucfirst($vehicle['transmission'] ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-star"></i> Kondisi Fisik</span>
                        <span class="info-value"><?php echo $conditionLabels[$vehicle['condition_rating']] ?? ucfirst($vehicle['condition_rating'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-money-bill-wave text-success"></i> Informasi Keuangan
                </h5>
            </div>
            <div class="view-card-body">
                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">Harga Beli Unit</span>
                        <span class="info-value text-danger"><?php echo formatCurrency($vehicle['purchase_price']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Harga Jual OTR</span>
                        <span class="info-value text-success" style="font-size: 1rem;"><?php echo formatCurrency($vehicle['selling_price']); ?></span>
                    </div>
                    <?php 
                    $profit = (float)$vehicle['selling_price'] - (float)$vehicle['purchase_price'];
                    $margin = ((float)$vehicle['purchase_price'] > 0) ? ($profit / (float)$vehicle['purchase_price']) * 100 : 0;
                    ?>
                    <div class="info-item">
                        <span class="info-label">Potensi Profit</span>
                        <span class="info-value text-primary"><?php echo formatCurrency($profit); ?> (<?php echo number_format($margin, 1); ?>%)</span>
                    </div>
                </div>

                <?php
                $quickLoan = (float)$vehicle['selling_price'] * 0.8;
                $quickInterest = $quickLoan * 0.075 * 3;
                $quickMonthly = ($quickLoan + $quickInterest) / 36;
                ?>
                <div class="credit-widget">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;"><i class="fas fa-calculator me-1 text-primary"></i> Estimasi Cicilan</span>
                        <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size: 0.68rem;">36 Bulan</span>
                    </div>
                    <div class="fw-bold text-primary fs-5 mb-1"><?php echo formatCurrency($quickMonthly); ?><span class="fs-7 text-muted fw-normal"> /bln</span></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                        <span class="text-muted" style="font-size: 0.72rem;">DP 20% (<?php echo formatCurrency((float)$vehicle['selling_price'] * 0.2); ?>)</span>
                        <a href="<?php echo BASE_URL; ?>leasing/simulation.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-primary" style="padding: 4px 10px; font-size: 0.72rem;">
                            Simulasi Lengkap <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Meta Info -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-info-circle text-muted"></i> Informasi Sistem
                </h5>
            </div>
            <div class="view-card-body">
                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">ID Kendaraan</span>
                        <span class="info-value">#<?php echo $vehicle['id']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Tanggal Beli</span>
                        <span class="info-value"><?php echo formatDate($vehicle['purchase_date'], 'd M Y'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Waktu Input</span>
                        <span class="info-value"><?php echo formatDate($vehicle['created_at'], 'd M Y H:i'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Update Terakhir</span>
                        <span class="info-value"><?php echo formatDate($vehicle['updated_at'], 'd M Y H:i'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Inspections, Documents, Identification, Actions -->
    <div class="col-lg-8">
        <!-- Inspection & Certificate Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-clipboard-check text-primary"></i> Sertifikasi & Hasil Inspeksi Teknis
                </h5>
                <?php if (!empty($vehicleInspection)): ?>
                    <?php echo renderGradeBadge($vehicleInspection['overall_grade'], $vehicleInspection['overall_score'], 'sm'); ?>
                <?php endif; ?>
            </div>
            <div class="view-card-body">
                <?php if (!empty($vehicleInspection)): 
                    $vGradeCfg = getGradeConfig($vehicleInspection['overall_grade']);
                ?>
                <div class="row align-items-center g-3">
                    <div class="col-12 col-md-4 text-center border-end">
                        <div class="d-inline-flex flex-column align-items-center justify-content-center rounded-circle text-white shadow-sm" 
                             style="width: 76px; height: 76px; background: <?php echo $vGradeCfg['badge_bg']; ?>;">
                            <span class="fs-4 fw-bold lh-1"><?php echo htmlspecialchars($vehicleInspection['overall_grade']); ?></span>
                            <span style="font-size: 0.62rem; text-transform: uppercase; font-weight: 700;">Grade</span>
                        </div>
                        <div class="fw-bold text-dark mt-2 mb-0"><?php echo number_format($vehicleInspection['overall_score'], 1); ?> / 100</div>
                        <small class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($vGradeCfg['subtext']); ?></small>
                    </div>

                    <div class="col-12 col-md-8">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="status-badge <?php echo $vehicleInspection['flood_free'] ? 'status-available' : 'status-sold'; ?>">
                                <i class="fas fa-tint"></i> <?php echo $vehicleInspection['flood_free'] ? 'Bebas Banjir' : 'Bekas Banjir'; ?>
                            </span>
                            <span class="status-badge <?php echo $vehicleInspection['accident_free'] ? 'status-available' : 'status-sold'; ?>">
                                <i class="fas fa-car-crash"></i> <?php echo $vehicleInspection['accident_free'] ? 'Bebas Tabrakan' : 'Pernah Tabrak'; ?>
                            </span>
                            <span class="status-badge <?php echo !$vehicleInspection['odometer_tampered'] ? 'status-available' : 'status-sold'; ?>">
                                <i class="fas fa-tachometer-alt"></i> <?php echo !$vehicleInspection['odometer_tampered'] ? 'Odometer Asli' : 'Indikasi Diputar'; ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <small class="text-muted" style="font-size: 0.725rem;">Diinspeksi: <?php echo formatDate($vehicleInspection['inspection_date'], 'd M Y'); ?> oleh <?php echo htmlspecialchars($vehicleInspection['inspector_name']); ?></small>
                            <a href="<?php echo BASE_URL; ?>inspections/view.php?id=<?php echo $vehicleInspection['id']; ?>" class="btn-header-action btn-header-primary" style="padding: 5px 12px; font-size: 0.75rem;">
                                <i class="fas fa-certificate me-1"></i> Lihat Sertifikat
                            </a>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <div class="text-center py-3">
                    <i class="fas fa-clipboard-list fa-2x text-muted opacity-25 mb-2"></i>
                    <p class="text-muted small mb-3">Unit mobil ini belum memiliki hasil inspeksi teknis 100+ titik.</p>
                    <a href="<?php echo BASE_URL; ?>inspections/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-primary" style="font-size: 0.78rem;">
                        <i class="fas fa-plus me-1"></i> Mulai Inspeksi Unit Ini
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Document & BPKB Status Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-folder-open text-primary"></i> Status & Posisi Dokumen (STNK & BPKB)
                </h5>
                <a href="<?php echo BASE_URL; ?>documents/tracking.php" class="btn-header-action" style="padding: 5px 12px; font-size: 0.75rem;">
                    <i class="fas fa-external-link-alt"></i> Buka Tracking
                </a>
            </div>
            <div class="view-card-body">
                <?php 
                $bpkbStatus = $vehicleDoc['bpkb_status'] ?? 'in_showroom';
                $bpkbCfg = getBpkbStatusConfig($bpkbStatus);
                ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border bg-light bg-opacity-50">
                            <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.7rem;">Posisi Fisik BPKB</div>
                            <div class="mb-1">
                                <span class="status-badge <?php echo $bpkbStatus === 'in_showroom' ? 'status-available' : ($bpkbStatus === 'in_leasing' ? 'status-reserved' : 'status-maintenance'); ?>">
                                    <i class="fas <?php echo $bpkbCfg['icon']; ?>"></i> <?php echo $bpkbCfg['label']; ?>
                                </span>
                            </div>
                            <?php if (!empty($vehicleDoc['bpkb_number'])): ?>
                            <div class="small text-muted font-monospace mt-1">No. BPKB: <strong><?php echo htmlspecialchars($vehicleDoc['bpkb_number']); ?></strong></div>
                            <?php endif; ?>
                            <?php if (!empty($vehicleDoc['bpkb_location_notes'])): ?>
                            <div class="small text-muted mt-1"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($vehicleDoc['bpkb_location_notes']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border bg-light bg-opacity-50">
                            <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.7rem;">Masa Pajak STNK</div>
                            <?php if (!empty($vehicleDoc['stnk_tax_date'])): 
                                $taxTime = strtotime($vehicleDoc['stnk_tax_date']);
                                $isExp = $taxTime < time();
                            ?>
                                <div class="fw-bold <?php echo $isExp ? 'text-danger' : 'text-success'; ?> fs-6 mb-1">
                                    <i class="fas fa-calendar-check me-1"></i><?php echo formatDate($vehicleDoc['stnk_tax_date'], 'd F Y'); ?>
                                </div>
                                <div class="small text-muted"><?php echo $isExp ? 'Masa pajak telah lewat/habis' : 'Pajak STNK masih aktif'; ?></div>
                            <?php else: ?>
                                <div class="text-muted small mb-1">Tanggal jatuh tempo belum dicatat</div>
                                <a href="<?php echo BASE_URL; ?>documents/tracking.php" class="btn-header-action" style="padding: 3px 8px; font-size: 0.72rem;">Set Tanggal Pajak</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Identification Numbers Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-id-card text-primary"></i> Nomor Identifikasi Kendaraan
                </h5>
            </div>
            <div class="view-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;">Nomor Polisi (Plat)</label>
                        <div class="number-display-box">
                            <span class="fw-bold text-dark font-monospace"><?php echo htmlspecialchars($vehicle['license_plate'] ?: '-'); ?></span>
                            <?php if (!empty($vehicle['license_plate'])): ?>
                            <button class="btn-copy" type="button" onclick="copyToClipboard('<?php echo $vehicle['license_plate']; ?>')" title="Salin">
                                <i class="fas fa-copy"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;">Nomor Rangka (VIN)</label>
                        <div class="number-display-box">
                            <span class="fw-bold text-dark font-monospace"><?php echo htmlspecialchars($vehicle['chassis_number'] ?: '-'); ?></span>
                            <?php if (!empty($vehicle['chassis_number'])): ?>
                            <button class="btn-copy" type="button" onclick="copyToClipboard('<?php echo $vehicle['chassis_number']; ?>')" title="Salin">
                                <i class="fas fa-copy"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;">Nomor Mesin</label>
                        <div class="number-display-box">
                            <span class="fw-bold text-dark font-monospace"><?php echo htmlspecialchars($vehicle['engine_number'] ?: '-'); ?></span>
                            <?php if (!empty($vehicle['engine_number'])): ?>
                            <button class="btn-copy" type="button" onclick="copyToClipboard('<?php echo $vehicle['engine_number']; ?>')" title="Salin">
                                <i class="fas fa-copy"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warranty Information Card (if any) -->
        <?php if (!empty($vehicle['warranty_type']) && $vehicle['warranty_type'] !== 'none'): ?>
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-shield-alt text-warning"></i> Informasi Garansi
                </h5>
            </div>
            <div class="view-card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-list">
                            <div class="info-item">
                                <span class="info-label">Jenis Garansi</span>
                                <span class="info-value"><?php echo ucfirst($vehicle['warranty_type']); ?></span>
                            </div>
                            <?php if (!empty($vehicle['warranty_period'])): ?>
                            <div class="info-item">
                                <span class="info-label">Periode Garansi</span>
                                <span class="info-value"><?php echo $vehicle['warranty_period']; ?> Bulan</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($vehicle['warranty_notes'])): ?>
                        <div class="small text-muted">
                            <strong>Catatan Garansi:</strong>
                            <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($vehicle['warranty_notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Control Box -->
        <div class="view-card card">
            <div class="view-card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($vehicle['status'] === 'available'): ?>
                        <a href="<?php echo BASE_URL; ?>transactions/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-success">
                            <i class="fas fa-shopping-cart"></i> Jual Mobil
                        </a>
                        <?php endif; ?>
                        
                        <a href="edit.php?id=<?php echo $vehicle['id']; ?>" class="btn-header-action btn-header-primary">
                            <i class="fas fa-edit"></i> Edit Data Mobil
                        </a>

                        <button type="button" class="btn-header-action" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                            <i class="fas fa-tools"></i> Tambah Perawatan
                        </button>
                    </div>

                    <?php if ($vehicle['status'] !== 'sold'): ?>
                    <form method="POST" action="delete.php" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data mobil ini?');">
                        <input type="hidden" name="id" value="<?php echo $vehicle['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn-header-action" style="color: #dc2626; border-color: #fecaca;">
                            <i class="fas fa-trash"></i> Hapus Mobil
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Maintenance Modal -->
<div class="modal fade modal-custom" id="addMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tools me-2"></i>Tambah Data Perawatan Kendaraan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_maintenance">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['id']; ?>">
                
                <div class="modal-body">
                    <div class="p-3 rounded border bg-light mb-3">
                        <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem;">Kendaraan:</div>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model_name']); ?> (<?php echo htmlspecialchars($vehicle['license_plate'] ?: '-'); ?>)</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="service_type" class="form-label form-label-sm">Jenis Perawatan <span class="text-danger">*</span></label>
                            <select class="form-select" id="service_type" name="service_type" required>
                                <option value="routine">Servis Rutin / Berkala</option>
                                <option value="oil_change">Ganti Oli & Filter</option>
                                <option value="tire_change">Ganti Ban / Balancing</option>
                                <option value="repair">Perbaikan Kerusakan</option>
                                <option value="body_paint">Body Repair & Pengecatan</option>
                                <option value="detailing">Detailing / Cuci Salon</option>
                                <option value="inspection">Inspeksi Umum</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label form-label-sm">Status Pengerjaan</label>
                            <select class="form-select" id="status" name="status">
                                <option value="scheduled">Terjadwal</option>
                                <option value="in_progress" selected>Sedang Dikerjakan</option>
                                <option value="completed">Selesai</option>
                                <option value="cancelled">Dibatalkan</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label form-label-sm">Tanggal Masuk Servis <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label form-label-sm">Estimasi Tanggal Selesai</label>
                            <input type="date" class="form-control" id="end_date" name="end_date">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="cost" class="form-label form-label-sm">Biaya Perawatan (Estimasi/Final)</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" class="form-control currency-input" id="cost" name="cost" placeholder="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label form-label-sm">Catatan Pengerjaan Servis</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Catatan part yang diganti atau progress pengerjaan..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Data Perawatan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Generator Iklan Marketplace -->
<div class="modal fade" id="marketplaceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-danger text-white py-3 px-4">
                <h5 class="modal-title fw-bold" style="font-size: 1.05rem;">
                    <i class="fas fa-bullhorn me-2"></i> Generator Deskripsi Iklan Marketplace Otomatis
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Nav Tabs Template -->
                <ul class="nav nav-pills nav-fill mb-3 p-1 bg-light rounded-3" id="pills-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold small py-2" id="tab-olx" data-bs-toggle="pill" data-bs-target="#content-olx" type="button" role="tab">
                            <i class="fas fa-list-check me-1"></i> Format Lengkap (OLX / Web)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold small py-2" id="tab-fb" data-bs-toggle="pill" data-bs-target="#content-fb" type="button" role="tab">
                            <i class="fab fa-facebook me-1"></i> Facebook Marketplace
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold small py-2" id="tab-ig" data-bs-toggle="pill" data-bs-target="#content-ig" type="button" role="tab">
                            <i class="fab fa-instagram me-1"></i> Instagram & TikTok Caption
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="pills-tabContent">
                    <!-- Tab 1: OLX -->
                    <div class="tab-pane fade show active" id="content-olx" role="tabpanel">
                        <div class="position-relative">
                            <textarea class="form-control font-monospace" id="textOlx" rows="12" style="font-size: 0.85rem; line-height: 1.5; background: #f8fafc; border-radius: 10px;"></textarea>
                            <button type="button" class="btn btn-danger btn-sm position-absolute" style="top: 10px; right: 10px; border-radius: 8px;" onclick="copyAdText('textOlx')">
                                <i class="fas fa-copy me-1"></i> Salin Teks
                            </button>
                        </div>
                    </div>

                    <!-- Tab 2: FB Marketplace -->
                    <div class="tab-pane fade" id="content-fb" role="tabpanel">
                        <div class="position-relative">
                            <textarea class="form-control font-monospace" id="textFb" rows="12" style="font-size: 0.85rem; line-height: 1.5; background: #f8fafc; border-radius: 10px;"></textarea>
                            <button type="button" class="btn btn-primary btn-sm position-absolute" style="top: 10px; right: 10px; border-radius: 8px;" onclick="copyAdText('textFb')">
                                <i class="fas fa-copy me-1"></i> Salin Teks
                            </button>
                        </div>
                    </div>

                    <!-- Tab 3: IG / TikTok -->
                    <div class="tab-pane fade" id="content-ig" role="tabpanel">
                        <div class="position-relative">
                            <textarea class="form-control font-monospace" id="textIg" rows="12" style="font-size: 0.85rem; line-height: 1.5; background: #f8fafc; border-radius: 10px;"></textarea>
                            <button type="button" class="btn btn-dark btn-sm position-absolute" style="top: 10px; right: 10px; border-radius: 8px;" onclick="copyAdText('textIg')">
                                <i class="fas fa-copy me-1"></i> Salin Teks
                            </button>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info border-0 shadow-sm mt-3 mb-0 d-flex align-items-center py-2 px-3">
                    <i class="fas fa-lightbulb me-2 text-info fs-5"></i>
                    <small>Klik tombol <strong>Salin Teks</strong> lalu tinggal <em>Paste (Ctrl+V)</em> langsung ke deskripsi postingan OLX, Facebook, atau Instagram Anda.</small>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Kirim WhatsApp Penawaran & Simulasi Kredit -->
<div class="modal fade" id="whatsappShareModal" tabindex="-1" aria-labelledby="whatsappShareModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success" style="width: 38px; height: 38px;">
                        <i class="fab fa-whatsapp fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6 mb-0" id="whatsappShareModalLabel">Kirim Penawaran & Simulasi Kredit ke WhatsApp</h5>
                        <small class="text-muted">Kirim draf penawaran rapi langsung ke nomor WhatsApp calon pembeli</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small text-dark">Nomor WhatsApp Penerima</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light">+62</span>
                            <input type="text" id="waRecipientNumber" class="form-control" placeholder="81234567890 (atau kosongkan untuk pilih di WA)">
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Jika dikosongkan, Anda dapat memilih kontak langsung di aplikasi WhatsApp.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small text-dark">Estimasi TDP (Uang Muka Kredit)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light">Rp</span>
                            <input type="text" id="waCustomDp" class="form-control" value="<?php echo number_format($vehicle['selling_price'] * 0.20, 0, ',', '.'); ?>" onkeyup="formatCurrency(this); renderWaMessage();">
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Default 20% dari harga OTR (bisa disesuaikan).</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold small text-dark mb-1">Pilihan Konten Pesan</label>
                    <div class="d-flex flex-wrap gap-3 p-2.5 bg-light rounded-3 small">
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" id="waOptSpecs" checked onchange="renderWaMessage()">
                            <label class="form-check-label" for="waOptSpecs">Spesifikasi Kendaraan</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" id="waOptCredit" checked onchange="renderWaMessage()">
                            <label class="form-check-label" for="waOptCredit">Paket Simulasi Cicilan</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" id="waOptContact" checked onchange="renderWaMessage()">
                            <label class="form-check-label" for="waOptContact">Alamat & Kontak Showroom</label>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label fw-semibold small text-dark mb-1">Pratinjau Pesan WhatsApp</label>
                    <textarea id="waMessagePreview" class="form-control font-monospace" rows="11" style="font-size: 0.825rem; line-height: 1.5; background: #f8fafc; border-radius: 8px;"></textarea>
                </div>
            </div>
            <div class="modal-footer border-top bg-light py-2.5 px-4 justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" onclick="copyWaText()">
                    <i class="fas fa-copy me-1"></i> Salin Draf Pesan
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-sm btn-success px-4 fw-semibold" onclick="sendToWhatsApp()">
                        <i class="fab fa-whatsapp me-1.5"></i> Buka WhatsApp & Kirim
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    if (!text || text === '-') return;
    navigator.clipboard.writeText(text).then(function() {
        alert('Teks berhasil disalin: ' + text);
    });
}

function copyAdText(elementId) {
    const textarea = document.getElementById(elementId);
    if (!textarea) return;
    textarea.select();
    navigator.clipboard.writeText(textarea.value).then(function() {
        alert('✅ Deskripsi iklan marketplace berhasil disalin ke clipboard! Siap dipaste ke marketplace/medsos.');
    });
}

// Generate templates on load
document.addEventListener('DOMContentLoaded', function() {
    const brand = "<?php echo addslashes($vehicle['brand_name']); ?>";
    const model = "<?php echo addslashes($vehicle['model_name']); ?>";
    const year = "<?php echo $vehicle['year']; ?>";
    const trans = "<?php echo strtoupper($vehicle['transmission']); ?>";
    const color = "<?php echo addslashes($vehicle['color']); ?>";
    const mileage = "<?php echo number_format($vehicle['mileage'], 0, ',', '.'); ?>";
    const plate = "<?php echo htmlspecialchars($vehicle['license_plate']); ?>";
    const fuel = "<?php echo htmlspecialchars($vehicle['fuel_type']); ?>";
    const price = "<?php echo number_format($vehicle['selling_price'], 0, ',', '.'); ?>";
    const showroom = "<?php echo defined('APP_NAME') ? addslashes(APP_NAME) : 'Mulya Motor Showroom'; ?>";
    const phone = "<?php echo defined('SHOWROOM_PHONE') ? SHOWROOM_PHONE : '0813-2329-9404'; ?>";
    const address = "<?php echo defined('SHOWROOM_ADDRESS') ? addslashes(SHOWROOM_ADDRESS) : 'Jl. Mohamad Hatta No.66, Tasikmalaya'; ?>";

    // 1. Template OLX
    const olxTemplate = `🔥 DIJUAL: ${brand} ${model} Tahun ${year} ${trans} Warna ${color} 🔥\n` +
`Kondisi Istimewa, Siap Pakai & Siap Luar Kota!\n\n` +
`📌 SPESIFIKASI KENDARAAN:\n` +
`• Merek / Tipe : ${brand} ${model}\n` +
`• Tahun        : ${year}\n` +
`• Transmisi    : ${trans}\n` +
`• Warna Bodi   : ${color}\n` +
`• Jarak Tempuh : ${mileage} KM (Asli)\n` +
`• Bahan Bakar  : ${fuel}\n` +
`• Plat Nomor   : ${plate}\n` +
`• Dokumen      : STNK, BPKB, Faktur Lengkap & Keabsahan Terjamin\n\n` +
`✨ KONDISI & KEUNGGULAN:\n` +
`• Mesin halus, kering & terawat prima\n` +
`• Kaki-kaki senyap, suspensi sangat nyaman\n` +
`• Interior bersih, wangi & orisinil\n` +
`• Jaminan Bebas Banjir & Bebas Benturan Tabrakan\n` +
`• AC dingin menggigil, kelistrikan berfungsi normal 100%\n\n` +
`💰 HARGA & PAKET KREDIT:\n` +
`• Harga Cash: Rp ${price} (Nego Halus di Tempat)\n` +
`• Paket Kredit DP Ringan & Angsuran Terjangkau\n` +
`• Proses cepat & dibantu sampai serah terima unit\n` +
`• Menerima Tukar Tambah dengan mobil lama Anda\n\n` +
`📍 LOKASI SHOWROOM:\n` +
`${showroom}\n` +
`${address}\n\n` +
`📲 INFO & JADWAL TEST DRIVE:\n` +
`WhatsApp / Telp: ${phone}`;

    // 2. Template Facebook
    const fbTemplate = `🚗 ${brand} ${model} Tahun ${year} ${trans} - ${color} 🚗\n\n` +
`Kondisi sangat mulus, terawat rapi, dan siap pakai tanpa PR!\n\n` +
`Spesifikasi Unit:\n` +
`- Tahun: ${year}\n` +
`- Transmisi: ${trans}\n` +
`- Odometer: ${mileage} KM\n` +
`- Nopol: ${plate}\n` +
`- Warna: ${color}\n\n` +
`Keunggulan:\n` +
`✅ Mesin prima & servis teratur\n` +
`✅ Interior bersih, segar & nyaman\n` +
`✅ Dokumen lengkap dan legalitas aman\n` +
`✅ Jaminan bebas banjir & bebas tabrakan\n` +
`✅ Siap cek unit & test drive sepuasnya\n\n` +
`💵 Harga: Rp ${price} (Nego di lokasi)\n` +
`Melayani Cash, Kredit DP Ceper, & Tukar Tambah.\n\n` +
`Lokasi: ${showroom} (${address})\n` +
`Minat serius hubungi WA: ${phone}`;

    // 3. Template Instagram / TikTok
    const igTemplate = `Ready stock unit istimewa hari ini! 🔥\n` +
`${brand} ${model} ${year} ${trans} warna ${color} ganteng maksimal! 😍\n\n` +
`Spesifikasi Singkat:\n` +
`▫️ Transmisi: ${trans}\n` +
`▫️ KM: ${mileage}\n` +
`▫️ Plat: ${plate}\n` +
`▫️ Kondisi: Siap gas, no PR!\n\n` +
`💸 Cash: Rp ${price}\n` +
`💳 Paket Kredit DP Ringan & Angsuran Terjangkau!\n` +
`🔄 Terima Tukar Tambah mobil lama kamu.\n\n` +
`Yuk langsung mampir dan test drive ke showroom kami:\n` +
`📍 ${showroom}\n` +
`Hubungi kami: 📲 ${phone}\n` +
`(Link WhatsApp ada di bio!)\n\n` +
`#jualmobilbekas #showroommobil #${brand.toLowerCase().replace(/\\s+/g, '')} #${model.toLowerCase().replace(/\\s+/g, '')} #mobilbekasberkualitas #mobilbekas`;

    if (document.getElementById('textOlx')) document.getElementById('textOlx').value = olxTemplate;
    if (document.getElementById('textFb')) document.getElementById('textFb').value = fbTemplate;
    if (document.getElementById('textIg')) document.getElementById('textIg').value = igTemplate;

    // Inisialisasi draf pesan WhatsApp
    renderWaMessage();

    const currencyInput = document.querySelector('#addMaintenanceModal .currency-input');
    if (currencyInput) {
        currencyInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value) {
                e.target.value = new Intl.NumberFormat('id-ID').format(parseInt(value));
            }
        });
    }
});

function formatCurrency(input) {
    let value = input.value.replace(/[^0-9]/g, '');
    if (value) {
        input.value = new Intl.NumberFormat('id-ID').format(parseInt(value));
    } else {
        input.value = '';
    }
}

function renderWaMessage() {
    const preview = document.getElementById('waMessagePreview');
    if (!preview) return;

    const brand = "<?php echo addslashes($vehicle['brand_name']); ?>";
    const model = "<?php echo addslashes($vehicle['model_name']); ?>";
    const year = "<?php echo $vehicle['year']; ?>";
    const trans = "<?php echo strtoupper($vehicle['transmission']); ?>";
    const color = "<?php echo addslashes($vehicle['color']); ?>";
    const mileage = "<?php echo number_format($vehicle['mileage'], 0, ',', '.'); ?>";
    const plate = "<?php echo htmlspecialchars($vehicle['license_plate']); ?>";
    const fuel = "<?php echo htmlspecialchars($vehicle['fuel_type']); ?>";
    const rawPrice = <?php echo (float)$vehicle['selling_price']; ?>;
    const priceFormatted = "Rp " + new Intl.NumberFormat('id-ID').format(rawPrice);
    const showroom = "<?php echo defined('APP_NAME') ? addslashes(APP_NAME) : 'Mulya Motor Showroom'; ?>";
    const phone = "<?php echo defined('SHOWROOM_PHONE') ? SHOWROOM_PHONE : '0813-2329-9404'; ?>";
    const address = "<?php echo defined('SHOWROOM_ADDRESS') ? addslashes(SHOWROOM_ADDRESS) : 'Jl. Mohamad Hatta No.66, Tasikmalaya'; ?>";

    const optSpecs = document.getElementById('waOptSpecs') ? document.getElementById('waOptSpecs').checked : true;
    const optCredit = document.getElementById('waOptCredit') ? document.getElementById('waOptCredit').checked : true;
    const optContact = document.getElementById('waOptContact') ? document.getElementById('waOptContact').checked : true;

    // Hitung TDP dan cicilan
    let dpVal = rawPrice * 0.20;
    const dpInput = document.getElementById('waCustomDp');
    if (dpInput && dpInput.value) {
        const parsed = parseInt(dpInput.value.replace(/[^0-9]/g, ''));
        if (!isNaN(parsed) && parsed > 0) {
            dpVal = parsed;
        }
    }
    const dpFormatted = "Rp " + new Intl.NumberFormat('id-ID').format(dpVal);

    let principal = rawPrice - dpVal;
    if (principal < 0) principal = 0;

    // Estimasi cicilan per bulan
    const cicilan3th = Math.round((principal * 1.24) / 36);
    const cicilan4th = Math.round((principal * 1.34) / 48);
    const cicilan5th = Math.round((principal * 1.45) / 60);

    let msg = `Halo Bapak/Ibu, salam dari *${showroom}*.\n\n`;
    msg += `Berikut kami informasikan penawaran unit *${brand} ${model} (${year})*:\n\n`;
    msg += `💰 *Harga Cash OTR:* ${priceFormatted} (Nego)\n\n`;

    if (optSpecs) {
        msg += `📋 *Spesifikasi Kendaraan:*\n`;
        msg += `• Merek & Model : ${brand} ${model}\n`;
        msg += `• Tahun Pembuatan : ${year}\n`;
        msg += `• Transmisi : ${trans}\n`;
        msg += `• Warna : ${color}\n`;
        msg += `• Jarak Tempuh : ${mileage} KM\n`;
        msg += `• Bahan Bakar : ${fuel}\n`;
        msg += `• Plat Nomor : ${plate}\n`;
        msg += `• Dokumen : BPKB, STNK, Faktur lengkap & legalitas aman terjamin\n`;
        msg += `• Kondisi : Siap pakai, mesin halus, bebas banjir & tabrakan\n\n`;
    }

    if (optCredit) {
        msg += `💳 *Simulasi Paket Kredit:* \n`;
        msg += `• Estimasi Total DP (TDP) : ${dpFormatted}\n`;
        msg += `• Angsuran 3 Tahun (36x) : Rp ${new Intl.NumberFormat('id-ID').format(cicilan3th)} / bln\n`;
        msg += `• Angsuran 4 Tahun (48x) : Rp ${new Intl.NumberFormat('id-ID').format(cicilan4th)} / bln\n`;
        msg += `• Angsuran 5 Tahun (60x) : Rp ${new Intl.NumberFormat('id-ID').format(cicilan5th)} / bln\n`;
        msg += `_(Simulasi estimasi, TDP & tenor bisa disesuaikan dengan kebutuhan Anda)_\n\n`;
    }

    if (optContact) {
        msg += `📍 *Lokasi Showroom:*\n${showroom}\n${address}\n`;
        msg += `📞 Kontak Sales: ${phone}\n\n`;
    }

    msg += `Unit siap dicek dan test drive kapan saja. Silakan balas pesan ini jika Bapak/Ibu berminat. Terima kasih! 🙏`;

    preview.value = msg;
}

function copyWaText() {
    const preview = document.getElementById('waMessagePreview');
    if (!preview) return;
    preview.select();
    navigator.clipboard.writeText(preview.value).then(function() {
        alert('Draf pesan WhatsApp berhasil disalin ke clipboard!');
    });
}

function sendToWhatsApp() {
    const preview = document.getElementById('waMessagePreview');
    if (!preview || !preview.value) {
        renderWaMessage();
    }
    const text = encodeURIComponent(preview.value);
    const phoneInput = document.getElementById('waRecipientNumber');
    let phone = phoneInput ? phoneInput.value.replace(/[^0-9]/g, '') : '';

    if (phone.startsWith('0')) {
        phone = '62' + phone.substring(1);
    } else if (phone.length > 0 && !phone.startsWith('62')) {
        phone = '62' + phone;
    }

    let url = '';
    if (phone.length >= 8) {
        url = `https://wa.me/${phone}?text=${text}`;
    } else {
        url = `https://api.whatsapp.com/send?text=${text}`;
    }

    window.open(url, '_blank');
}
</script>

<?php include '../includes/footer.php'; ?>
