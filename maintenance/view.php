<?php
// maintenance/view.php - Detail Rekam Perawatan Mobil
require_once '../config/config.php';
require_once '../includes/header.php';
requireLogin();

// Validate ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    showAlert('ID Perawatan tidak valid.', 'danger');
    redirect('maintenance/');
}

try {
    // Fetch maintenance details
    $sql = "SELECT m.*, 
            v.license_plate, v.year, v.color, v.chassis_number, v.engine_number, v.images, v.status as vehicle_status,
            mo.name as model_name, 
            b.name as brand_name, b.logo as brand_logo
            FROM maintenance m
            JOIN vehicles v ON m.vehicle_id = v.id
            JOIN models mo ON v.model_id = mo.id
            JOIN brands b ON mo.brand_id = b.id
            WHERE m.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        showAlert('Data rekam perawatan tidak ditemukan.', 'danger');
        redirect('maintenance/');
    }
    
    // Auto-update status if end_date has passed
    $today = date('Y-m-d');
    if (!empty($log['end_date']) && 
        $log['end_date'] < $today && 
        in_array($log['status'], ['in_progress', 'scheduled'])) {
        
        $updateMaintenance = $db->prepare("UPDATE maintenance SET status = 'completed', updated_at = NOW() WHERE id = ?");
        $updateMaintenance->execute([$id]);
        
        $checkVehicle = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
        $checkVehicle->execute([$log['vehicle_id']]);
        if ($checkVehicle->fetchColumn() === 'maintenance') {
            $updateVehicle = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
            $updateVehicle->execute([$log['vehicle_id']]);
        }
        $log['status'] = 'completed';
    }
} catch (PDOException $e) {
    showAlert('Gagal memuat data: ' . $e->getMessage(), 'danger');
    redirect('maintenance/');
}

$statusLabels = [
    'scheduled' => 'Terjadwal',
    'in_progress' => 'Sedang Dikerjakan',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

$serviceTypeLabels = [
    'routine' => 'Servis Rutin',
    'oil_change' => 'Ganti Oli & Filter',
    'tire_change' => 'Ganti / Rotasi Ban',
    'repair' => 'Perbaikan Mesin / Kaki-kaki',
    'body_paint' => 'Body & Pengecatan',
    'detailing' => 'Detailing & Cuci',
    'inspection' => 'Inspeksi Umum',
    'other' => 'Lainnya'
];

$vehicleImage = null;
if (!empty($log['images'])) {
    $decoded = json_decode($log['images'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $vehicleImage = $decoded[0];
    } else {
        $csv = array_filter(array_map('trim', explode(',', (string)$log['images'])));
        if (!empty($csv)) $vehicleImage = $csv[0];
    }
}

// Calculate duration
$durationText = '-';
if (!empty($log['start_date']) && !empty($log['end_date'])) {
    $d1 = new DateTime($log['start_date']);
    $d2 = new DateTime($log['end_date']);
    $diff = $d1->diff($d2)->days;
    $durationText = ($diff === 0 ? '1 hari' : ($diff + 1) . ' hari');
}
?>

<style>
    /* ===== Vehicle View / Master Detail Layout (Matching Stok Mobil) ===== */
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

    /* Vehicle Mini Header */
    .vehicle-profile-row {
        display: flex;
        gap: 16px;
        align-items: center;
        padding-bottom: 16px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 16px;
    }
    .vehicle-thumb-box {
        width: 100px;
        height: 75px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .vehicle-thumb-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }
    .detail-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.04em;
        margin-bottom: 2px;
    }
    .detail-value {
        font-size: 0.88rem;
        font-weight: 600;
        color: #1e293b;
    }

    .notes-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        font-size: 0.86rem;
        color: #334155;
        line-height: 1.6;
        white-space: pre-line;
    }

    /* Price display */
    .price-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        text-align: center;
        margin-bottom: 16px;
    }
    .price-val {
        font-size: 1.4rem;
        font-weight: 800;
        color: #16a34a;
        letter-spacing: -0.02em;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .view-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicle-thumb-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .vehicle-profile-row { border-bottom-color: #334155; }
    [data-theme="dark"] .detail-value { color: #f1f5f9; }
    [data-theme="dark"] .notes-box,
    [data-theme="dark"] .price-box { background: #0f172a; border-color: #334155; color: #cbd5e1; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1>Detail Perawatan Mobil</h1>
            <span class="badge bg-light text-dark border">#<?php echo $id; ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>maintenance/" class="text-primary text-decoration-underline">Perawatan Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Detail #<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>maintenance/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
</div>

<div class="row">
    <!-- Left Column (Main Details) -->
    <div class="col-lg-8">
        
        <!-- Card 1: Informasi Kendaraan -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-car text-primary"></i> Unit Kendaraan
                </h5>
                <a href="<?php echo BASE_URL; ?>vehicles/view.php?id=<?php echo $log['vehicle_id']; ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:0.78rem;">
                    <i class="fas fa-external-link-alt me-1"></i>Lihat Detail Mobil
                </a>
            </div>
            <div class="view-card-body">
                <div class="vehicle-profile-row">
                    <div class="vehicle-thumb-box">
                        <?php if ($vehicleImage && file_exists(__DIR__ . '/../uploads/vehicles/' . $vehicleImage)): ?>
                            <img src="<?php echo BASE_URL . 'uploads/vehicles/' . htmlspecialchars($vehicleImage); ?>" alt="Foto Mobil">
                        <?php else: ?>
                            <i class="fas fa-car fa-2x text-muted"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($log['brand_name'] . ' ' . $log['model_name']); ?></h5>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dark text-white"><?php echo htmlspecialchars($log['license_plate'] ?: 'No Plat'); ?></span>
                            <?php if (!empty($log['year'])): ?>
                                <span class="text-muted small">Tahun <?php echo $log['year']; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($log['color'])): ?>
                                <span class="text-muted small">• <?php echo htmlspecialchars($log['color']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="detail-grid">
                    <div>
                        <div class="detail-label">Nomor Rangka (VIN)</div>
                        <div class="detail-value font-monospace"><?php echo htmlspecialchars($log['chassis_number'] ?: '-'); ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Nomor Mesin</div>
                        <div class="detail-value font-monospace"><?php echo htmlspecialchars($log['engine_number'] ?: '-'); ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Status Unit Mobil</div>
                        <div class="detail-value">
                            <?php
                            $vStatusLabels = [
                                'available' => '<span class="badge bg-success">Tersedia</span>',
                                'sold' => '<span class="badge bg-secondary">Terjual</span>',
                                'maintenance' => '<span class="badge bg-warning text-dark">Dalam Perawatan</span>',
                                'reserved' => '<span class="badge bg-info text-dark">Dipesan</span>'
                            ];
                            echo $vStatusLabels[$log['vehicle_status']] ?? ucfirst($log['vehicle_status']);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Rincian & Catatan Perawatan -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-clipboard-list text-primary"></i> Rincian & Catatan Pengerjaan
                </h5>
            </div>
            <div class="view-card-body">
                <div class="mb-3">
                    <div class="detail-label">Jenis Layanan</div>
                    <div class="fs-6 fw-bold text-dark">
                        <?php echo $serviceTypeLabels[$log['service_type']] ?? ucfirst($log['service_type']); ?>
                    </div>
                </div>

                <div>
                    <div class="detail-label mb-1">Catatan / Detail Perbaikan</div>
                    <?php if (!empty($log['notes'])): ?>
                        <div class="notes-box"><?php echo htmlspecialchars($log['notes']); ?></div>
                    <?php else: ?>
                        <div class="text-muted small fst-italic">Tidak ada catatan rincian perbaikan khusus.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Right Column (Status & Actions) -->
    <div class="col-lg-4">
        
        <!-- Status & Biaya Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-receipt text-primary"></i> Status & Biaya
                </h5>
            </div>
            <div class="view-card-body">
                <div class="mb-3">
                    <div class="detail-label mb-2">Status Pengerjaan</div>
                    <?php
                    $statusClass = 'secondary';
                    $statusText = 'Terjadwal';
                    switch($log['status']) {
                        case 'scheduled': $statusClass = 'info'; $statusText = 'Terjadwal'; break;
                        case 'in_progress': $statusClass = 'warning text-dark'; $statusText = 'Sedang Dikerjakan'; break;
                        case 'completed': $statusClass = 'success'; $statusText = 'Selesai'; break;
                        case 'cancelled': $statusClass = 'danger'; $statusText = 'Dibatalkan'; break;
                    }
                    ?>
                    <div class="badge bg-<?php echo $statusClass; ?> w-100 py-2 fs-6">
                        <?php echo $statusText; ?>
                    </div>
                </div>

                <div class="price-box">
                    <div class="detail-label">Total Biaya Perawatan</div>
                    <div class="price-val"><?php echo formatCurrency($log['cost']); ?></div>
                </div>

                <div class="d-flex flex-column gap-2 py-2 border-top border-bottom my-3">
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Tanggal Mulai:</span>
                        <strong class="text-dark"><?php echo formatDate($log['start_date'], 'd F Y'); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Estimasi Selesai:</span>
                        <strong class="text-dark"><?php echo !empty($log['end_date']) ? formatDate($log['end_date'], 'd F Y') : '-'; ?></strong>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Durasi:</span>
                        <strong class="text-dark"><?php echo $durationText; ?></strong>
                    </div>
                </div>

                <div class="d-flex flex-column gap-1 text-muted" style="font-size:0.75rem;">
                    <div>Dicatat: <?php echo formatDate($log['created_at']); ?></div>
                    <div>Pembaruan: <?php echo formatDate($log['updated_at'] ?? $log['created_at']); ?></div>
                </div>
            </div>
        </div>

        <!-- Action Card -->
        <div class="view-card card">
            <div class="view-card-body">
                <button type="button" class="btn btn-outline-danger w-100" style="border-radius:10px;font-weight:600;font-size:0.84rem;" onclick="deleteMaintenance(<?php echo $log['id']; ?>)">
                    <i class="fas fa-trash me-2"></i>Hapus Rekam Perawatan
                </button>
            </div>
        </div>

    </div>
</div>

<script>
function deleteMaintenance(id) {
    if (confirm('Apakah Anda yakin ingin menghapus data perawatan ini secara permanen?')) {
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

<?php include '../includes/footer.php'; ?>
