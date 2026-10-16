<?php
// consignments/view.php - Detail & Cetak Perjanjian Mobil Titip Jual
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
    showAlert('ID konsinyasi tidak valid.', 'danger');
    redirect('consignments/');
}

// Fetch consignment details
$stmt = $db->prepare("
    SELECT c.*,
           DATEDIFF(c.end_date, CURDATE()) as days_left
    FROM consignments c
    WHERE c.id = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$c) {
    showAlert('Data mobil titip jual tidak ditemukan.', 'danger');
    redirect('consignments/');
}

// Handle Status & Settlement Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $newStatus = $_POST['status'] ?? $c['status'];
        $newSettlement = $_POST['settlement_status'] ?? $c['settlement_status'];
        $settlementDate = ($newSettlement === 'paid') ? ($_POST['settlement_date'] ?: date('Y-m-d')) : null;

        try {
            $stmtUp = $db->prepare("
                UPDATE consignments SET
                    status = ?,
                    settlement_status = ?,
                    settlement_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$newStatus, $newSettlement, $settlementDate, $id]);

            // Sync vehicle status if vehicle_id is linked
            if (!empty($c['vehicle_id'])) {
                $vehStatus = 'available';
                if ($newStatus === 'sold') {
                    $vehStatus = 'sold';
                } elseif ($newStatus === 'returned' || $newStatus === 'cancelled') {
                    $vehStatus = 'maintenance';
                }
                $db->prepare("UPDATE vehicles SET status = ? WHERE id = ?")->execute([$vehStatus, $c['vehicle_id']]);
            }

            showAlert('Status titip jual berhasil diperbarui.', 'success');
            redirect('consignments/view.php?id=' . $id);
        } catch (PDOException $e) {
            $error = 'Gagal memperbarui status: ' . $e->getMessage();
        }
    }
}

$photos = !empty($c['photos']) ? json_decode($c['photos'], true) : [];
$daysLeft = (int)$c['days_left'];
$waPhone = preg_replace('/[^0-9]/', '', $c['owner_phone']);
if (substr($waPhone, 0, 1) === '0') {
    $waPhone = '62' . substr($waPhone, 1);
}
$companyName = defined('APP_NAME') ? APP_NAME : 'Mulya Motor Showroom';
?>

<style>
    /* ===== Consignment Detail Styles ===== */
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
    .vehicles-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
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
        border: none;
    }
    .btn-header-primary:hover {
        background: #1e272e;
        color: #fff;
    }

    /* Detail Cards */
    .detail-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-bottom: 24px;
        overflow: hidden;
    }
    .detail-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
    }
    .detail-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .detail-card-body {
        padding: 20px;
    }

    .info-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 8px;
        border-bottom: 1px solid #f8fafc;
        font-size: 0.85rem;
    }
    .info-row:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    .info-label {
        color: #64748b;
        font-weight: 500;
    }
    .info-value {
        color: #1e293b;
        font-weight: 700;
        text-align: right;
    }

    .plate-badge-lg {
        display: inline-block;
        padding: 4px 12px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 800;
        color: #1e293b;
        letter-spacing: 0.05em;
        font-family: monospace;
    }

    .photo-gallery {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 10px;
        margin-top: 14px;
    }
    .photo-thumb {
        width: 100%;
        height: 110px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: transform 0.15s;
    }
    .photo-thumb:hover {
        transform: scale(1.03);
    }

    .btn-wa-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #059669;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-wa-action:hover {
        background: #10b981;
        color: #fff;
    }

    /* Print View (Hidden in Screen) */
    @media screen {
        .print-agreement-doc {
            display: none;
        }
    }

    @media print {
        body * {
            visibility: hidden;
        }
        .print-agreement-doc, .print-agreement-doc * {
            visibility: visible;
        }
        .print-agreement-doc {
            display: block !important;
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            padding: 20px;
            background: #fff;
            color: #000;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.5;
        }
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .detail-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .detail-card-header { background: #1a2332; border-bottom-color: #334155; }
    [data-theme="dark"] .detail-card-title { color: #f1f5f9; }
    [data-theme="dark"] .info-row { border-bottom-color: #1e293b; }
    [data-theme="dark"] .info-label { color: #94a3b8; }
    [data-theme="dark"] .info-value { color: #f1f5f9; }
    [data-theme="dark"] .plate-badge-lg { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .photo-thumb { border-color: #334155; }
    [data-theme="dark"] .btn-wa-action { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Detail Titip Jual (<?php echo htmlspecialchars($c['consignment_code']); ?>)</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>consignments/" class="text-primary text-decoration-underline">Titip Jual</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($c['consignment_code']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <button type="button" class="btn-header-action" onclick="window.print()">
            <i class="fas fa-print"></i> Cetak Surat Perjanjian
        </button>
        <a href="edit.php?id=<?php echo $c['id']; ?>" class="btn-header-action">
            <i class="fas fa-edit"></i> Edit
        </a>
        <button type="button" class="btn-header-action btn-header-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
            <i class="fas fa-arrows-rotate"></i> Update Status
        </button>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Status Banner -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; background: <?php echo ($c['status'] === 'active') ? '#eff6ff' : (($c['status'] === 'sold') ? '#ecfdf5' : '#f8fafc'); ?>; border-left: 5px solid <?php echo ($c['status'] === 'active') ? '#3b82f6' : (($c['status'] === 'sold') ? '#10b981' : '#64748b'); ?> !important;">
    <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: <?php echo ($c['status'] === 'active') ? '#3b82f6' : (($c['status'] === 'sold') ? '#10b981' : '#64748b'); ?>;">
                <i class="fas <?php echo ($c['status'] === 'active') ? 'fa-car' : (($c['status'] === 'sold') ? 'fa-handshake' : 'fa-box-archive'); ?>"></i>
            </div>
            <div>
                <div class="fw-bold text-dark-emphasis" style="font-size: 1rem;">
                    Status: 
                    <?php if ($c['status'] === 'active'): ?>
                        <span class="text-primary">Aktif Dititipkan di Showroom</span>
                    <?php elseif ($c['status'] === 'sold'): ?>
                        <span class="text-success">Unit Telah Berhasil Terjual</span>
                    <?php elseif ($c['status'] === 'returned'): ?>
                        <span class="text-warning">Unit Telah Dikembalikan ke Pemilik</span>
                    <?php else: ?>
                        <span class="text-secondary">Dibatalkan</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    Masa penitipan: <?php echo date('d M Y', strtotime($c['start_date'])); ?> s/d <?php echo date('d M Y', strtotime($c['end_date'])); ?>
                </div>
            </div>
        </div>
        <div>
            <?php if ($c['status'] === 'active'): ?>
                <?php if ($daysLeft < 0): ?>
                    <span class="badge bg-danger px-3 py-2 fw-semibold" style="font-size:0.8rem; border-radius:8px;">
                        <i class="fas fa-exclamation-triangle me-1"></i> Lewat Jatuh Tempo (<?php echo abs($daysLeft); ?> Hari)
                    </span>
                <?php elseif ($daysLeft <= 7): ?>
                    <span class="badge bg-warning text-dark px-3 py-2 fw-semibold" style="font-size:0.8rem; border-radius:8px;">
                        <i class="fas fa-clock me-1"></i> Sisa Waktu <?php echo $daysLeft; ?> Hari Lagi
                    </span>
                <?php else: ?>
                    <span class="badge bg-success px-3 py-2 fw-semibold" style="font-size:0.8rem; border-radius:8px;">
                        <i class="fas fa-check me-1"></i> Sisa Waktu <?php echo $daysLeft; ?> Hari
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Car Specifications & Photos -->
    <div class="col-lg-7">
        <!-- Car Information Card -->
        <div class="detail-card">
            <div class="detail-card-header">
                <h5 class="detail-card-title">
                    <i class="fas fa-car text-primary"></i> Data Spesifikasi Kendaraan
                </h5>
                <span class="plate-badge-lg"><?php echo htmlspecialchars($c['license_plate']); ?></span>
            </div>
            <div class="detail-card-body">
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Merek & Model</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['brand_name'] . ' ' . $c['model_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Tahun Pembuatan</span>
                        <span class="info-value"><?php echo $c['year']; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Warna Bodi</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['color']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Transmisi</span>
                        <span class="info-value text-uppercase"><?php echo htmlspecialchars($c['transmission']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Bahan Bakar</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['fuel_type']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Kilometer (Odometer)</span>
                        <span class="info-value"><?php echo number_format($c['mileage']); ?> KM</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nomor Rangka (VIN)</span>
                        <span class="info-value font-monospace"><?php echo htmlspecialchars($c['chassis_number'] ?: '-'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nomor Mesin</span>
                        <span class="info-value font-monospace"><?php echo htmlspecialchars($c['engine_number'] ?: '-'); ?></span>
                    </div>
                </div>

                <!-- Photos Section -->
                <?php if (!empty($photos)): ?>
                <div class="mt-4 pt-3 border-top">
                    <div class="fw-bold text-dark-emphasis small mb-2"><i class="fas fa-camera text-muted me-1"></i> Dokumentasi Foto Mobil:</div>
                    <div class="photo-gallery">
                        <?php foreach ($photos as $p): ?>
                        <a href="<?php echo BASE_URL . $p; ?>" target="_blank">
                            <img src="<?php echo BASE_URL . $p; ?>" class="photo-thumb" alt="Foto Mobil">
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Agreement Notes Card -->
        <div class="detail-card">
            <div class="detail-card-header">
                <h5 class="detail-card-title">
                    <i class="fas fa-file-lines text-info"></i> Catatan & Klausul Kesepakatan
                </h5>
            </div>
            <div class="detail-card-body">
                <?php if (!empty($c['agreement_notes'])): ?>
                    <p class="mb-0 text-dark-emphasis" style="white-space: pre-line; font-size: 0.88rem; line-height: 1.6;">
                        <?php echo htmlspecialchars($c['agreement_notes']); ?>
                    </p>
                <?php else: ?>
                    <span class="text-muted fst-italic small">Tidak ada catatan khusus yang dilampirkan.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Owner, Financial & Settlement -->
    <div class="col-lg-5">
        <!-- Financial Agreement Card -->
        <div class="detail-card">
            <div class="detail-card-header">
                <h5 class="detail-card-title">
                    <i class="fas fa-sack-dollar text-success"></i> Kesepakatan Harga & Komisi
                </h5>
            </div>
            <div class="detail-card-body">
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Harga Bersih Pemilik (Net to Owner)</span>
                        <span class="info-value">Rp <?php echo number_format($c['net_price_to_owner'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Target Harga Jual Showroom</span>
                        <span class="info-value text-primary" style="font-size: 1rem;">Rp <?php echo number_format($c['target_selling_price'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Margin Komisi Showroom</span>
                        <span class="info-value text-success" style="font-size: 1.05rem;">
                            Rp <?php echo number_format($c['commission_amount'], 0, ',', '.'); ?>
                            <small class="text-muted d-block fw-normal" style="font-size: 0.72rem;">
                                (<?php echo $c['target_selling_price'] > 0 ? round(($c['commission_amount'] / $c['target_selling_price']) * 100, 1) : 0; ?>% dari harga jual)
                            </small>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status Pelunasan ke Pemilik</span>
                        <span class="info-value">
                            <?php if ($c['settlement_status'] === 'paid'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">
                                    <i class="fas fa-check-circle me-1"></i> Sudah Ditransfer
                                </span>
                                <?php if ($c['settlement_date']): ?>
                                <div class="text-muted fw-normal" style="font-size: 0.7rem;"><?php echo date('d M Y', strtotime($c['settlement_date'])); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">
                                    <i class="fas fa-clock me-1"></i> Belum Ditransfer
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Owner Information Card -->
        <div class="detail-card">
            <div class="detail-card-header">
                <h5 class="detail-card-title">
                    <i class="fas fa-user-tie text-primary"></i> Data Pemilik Kendaraan
                </h5>
                <a href="https://wa.me/<?php echo $waPhone; ?>?text=Halo%20<?php echo urlencode($c['owner_name']); ?>%2C%20terkait%20mobil%20titipan%20<?php echo urlencode($c['brand_name'] . ' ' . $c['model_name'] . ' (' . $c['license_plate'] . ')'); ?>" target="_blank" class="btn-wa-action">
                    <i class="fab fa-whatsapp"></i> Chat WhatsApp
                </a>
            </div>
            <div class="detail-card-body">
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Nama Pemilik</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['owner_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nomor WhatsApp / HP</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['owner_phone']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">No. KTP / NIK</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['owner_id_card'] ?: '-'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Alamat</span>
                        <span class="info-value"><?php echo htmlspecialchars($c['owner_address'] ?: '-'); ?></span>
                    </div>
                </div>

                <!-- Bank Info Box -->
                <div class="mt-3 p-3 bg-light-subtle rounded-3 border border-light-subtle">
                    <div class="fw-bold text-dark-emphasis small mb-2"><i class="fas fa-building-columns me-1 text-primary"></i> Rekening Transfer Pemilik:</div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Nama Bank:</span>
                        <span class="fw-bold text-dark-emphasis"><?php echo htmlspecialchars($c['owner_bank_name'] ?: '-'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Nomor Rekening:</span>
                        <span class="fw-bold text-primary font-monospace"><?php echo htmlspecialchars($c['owner_bank_account'] ?: '-'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-muted">Atas Nama:</span>
                        <span class="fw-bold text-dark-emphasis"><?php echo htmlspecialchars($c['owner_bank_holder'] ?: $c['owner_name']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Update Status -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-arrows-rotate me-2 text-primary"></i>Update Status Titip Jual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Status Unit Mobil</label>
                        <select name="status" class="form-select">
                            <option value="active" <?php echo $c['status'] === 'active' ? 'selected' : ''; ?>>Aktif Dititipkan di Showroom</option>
                            <option value="sold" <?php echo $c['status'] === 'sold' ? 'selected' : ''; ?>>Laku Terjual</option>
                            <option value="returned" <?php echo $c['status'] === 'returned' ? 'selected' : ''; ?>>Dikembalikan ke Pemilik</option>
                            <option value="cancelled" <?php echo $c['status'] === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Status Pelunasan ke Pemilik</label>
                        <select name="settlement_status" class="form-select" id="settlementSelect" onchange="toggleSettlementDate()">
                            <option value="unpaid" <?php echo $c['settlement_status'] === 'unpaid' ? 'selected' : ''; ?>>Belum Ditransfer (Unpaid)</option>
                            <option value="paid" <?php echo $c['settlement_status'] === 'paid' ? 'selected' : ''; ?>>Sudah Ditransfer Lunas (Paid)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="settlementDateGroup" style="display: <?php echo ($c['settlement_status'] === 'paid') ? 'block' : 'none'; ?>;">
                        <label class="form-label fw-bold small text-muted">Tanggal Transfer Pelunasan</label>
                        <input type="date" name="settlement_date" class="form-control" value="<?php echo $c['settlement_date'] ?: date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-bold">Simpan Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== PRINT ONLY: SURAT PERJANJIAN TITIP JUAL ==================== -->
<div class="print-agreement-doc">
    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($companyName); ?></h2>
        <p style="margin: 2px 0; font-size: 10pt;">Jual Beli Mobil Bekas Berkualitas & Titip Jual</p>
        <p style="margin: 2px 0; font-size: 9pt;">Surat Perjanjian Penitipan Kendaraan Bermotor</p>
    </div>

    <div style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 0; font-size: 13pt; text-decoration: underline;">SURAT PERJANJIAN TITIP JUAL KENDARAAN</h3>
        <div style="font-size: 10pt;">Nomor: <?php echo htmlspecialchars($c['consignment_code']); ?></div>
    </div>

    <p>Pada hari ini, tanggal <strong><?php echo date('d', strtotime($c['start_date'])); ?></strong> bulan <strong><?php echo date('F', strtotime($c['start_date'])); ?></strong> tahun <strong><?php echo date('Y', strtotime($c['start_date'])); ?></strong>, telah disepakati perjanjian titip jual kendaraan antara pihak-pihak sebagai berikut:</p>

    <table style="width: 100%; margin-left: 15px; margin-bottom: 15px; font-size: 11pt;">
        <tr>
            <td style="width: 160px;"><strong>I. PIHAK PERTAMA (Pemilik)</strong></td>
            <td style="width: 10px;">:</td>
            <td></td>
        </tr>
        <tr>
            <td style="padding-left: 20px;">Nama Lengkap</td>
            <td>:</td>
            <td><strong><?php echo htmlspecialchars($c['owner_name']); ?></strong></td>
        </tr>
        <tr>
            <td style="padding-left: 20px;">No. KTP / NIK</td>
            <td>:</td>
            <td><?php echo htmlspecialchars($c['owner_id_card'] ?: '-'); ?></td>
        </tr>
        <tr>
            <td style="padding-left: 20px;">No. HP / WhatsApp</td>
            <td>:</td>
            <td><?php echo htmlspecialchars($c['owner_phone']); ?></td>
        </tr>
        <tr>
            <td style="padding-left: 20px;">Alamat</td>
            <td>:</td>
            <td><?php echo htmlspecialchars($c['owner_address'] ?: '-'); ?></td>
        </tr>
    </table>

    <table style="width: 100%; margin-left: 15px; margin-bottom: 15px; font-size: 11pt;">
        <tr>
            <td style="width: 160px;"><strong>II. PIHAK KEDUA (Showroom)</strong></td>
            <td style="width: 10px;">:</td>
            <td><strong><?php echo htmlspecialchars($companyName); ?></strong></td>
        </tr>
    </table>

    <p>Kedua belah pihak telah sepakat untuk melakukan penitipan dan penjualan kendaraan bermotor dengan rincian unit sebagai berikut:</p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10pt;" border="1" cellpadding="6">
        <tr style="background-color: #f2f2f2;">
            <th>Merek / Model</th>
            <th>Tahun / Warna</th>
            <th>Nomor Polisi</th>
            <th>No. Rangka</th>
            <th>No. Mesin</th>
        </tr>
        <tr>
            <td><?php echo htmlspecialchars($c['brand_name'] . ' ' . $c['model_name']); ?></td>
            <td><?php echo $c['year'] . ' / ' . htmlspecialchars($c['color']); ?></td>
            <td style="font-weight: bold;"><?php echo htmlspecialchars($c['license_plate']); ?></td>
            <td><?php echo htmlspecialchars($c['chassis_number'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($c['engine_number'] ?: '-'); ?></td>
        </tr>
    </table>

    <p><strong>PASAL KESEPAKATAN FINANSIAL & JANGKA WAKTU:</strong></p>
    <ol style="font-size: 10.5pt; line-height: 1.6;">
        <li><strong>Harga Bersih Pemilik:</strong> Pihak Pertama sepakat menerima harga bersih (*Net Price*) sebesar <strong>Rp <?php echo number_format($c['net_price_to_owner'], 0, ',', '.'); ?></strong> saat unit kendaraan berhasil terjual.</li>
        <li><strong>Target Jual & Komisi Showroom:</strong> Pihak Kedua berhak memajang dan memasarkan unit dengan target harga <strong>Rp <?php echo number_format($c['target_selling_price'], 0, ',', '.'); ?></strong> dengan estimasi margin komisi sebesar <strong>Rp <?php echo number_format($c['commission_amount'], 0, ',', '.'); ?></strong>.</li>
        <li><strong>Masa Penitipan:</strong> Perjanjian titip jual ini berlaku mulai tanggal <strong><?php echo date('d M Y', strtotime($c['start_date'])); ?></strong> sampai dengan <strong><?php echo date('d M Y', strtotime($c['end_date'])); ?></strong>.</li>
        <li><strong>Pencairan Dana:</strong> Setelah unit terjual dan dilunasi oleh pembeli, Pihak Kedua wajib menyalurkan dana bersih ke rekening Pihak Pertama (<strong>Bank <?php echo htmlspecialchars($c['owner_bank_name'] ?: '-'); ?> No. <?php echo htmlspecialchars($c['owner_bank_account'] ?: '-'); ?> a.n <?php echo htmlspecialchars($c['owner_bank_holder'] ?: $c['owner_name']); ?></strong>).</li>
        <li><strong>Catatan Khusus:</strong> <?php echo htmlspecialchars($c['agreement_notes'] ?: 'Unit dititipkan dalam kondisi baik sesuai kesepakatan.'); ?></li>
    </ol>

    <div style="margin-top: 40px; display: flex; justify-content: space-between; text-align: center;">
        <div style="width: 40%;">
            <p style="margin-bottom: 60px;">Pihak Pertama (Pemilik),</p>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;"><?php echo htmlspecialchars($c['owner_name']); ?></p>
        </div>
        <div style="width: 40%;">
            <p style="margin-bottom: 60px;">Pihak Kedua (Showroom),</p>
            <p style="margin: 0; font-weight: bold; text-decoration: underline;"><?php echo htmlspecialchars($companyName); ?></p>
        </div>
    </div>
</div>

<script>
function toggleSettlementDate() {
    const sel = document.getElementById('settlementSelect');
    const grp = document.getElementById('settlementDateGroup');
    grp.style.display = (sel.value === 'paid') ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
