<?php
// bookings/view.php - Detail Surat Pemesanan Kendaraan
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
if ($id <= 0) {
    showAlert('ID pemesanan tidak valid.', 'danger');
    redirect('bookings/');
}

// Handle Cancel / Release action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid.', 'danger');
    } else {
        if ($_POST['action'] === 'release_unit') {
            try {
                $db->beginTransaction();

                $bStmt = $db->prepare("SELECT vehicle_id, status FROM vehicle_bookings WHERE id = ?");
                $bStmt->execute([$id]);
                $bData = $bStmt->fetch();

                if ($bData && ($bData['status'] === 'active' || $bData['status'] === 'expired')) {
                    $upB = $db->prepare("UPDATE vehicle_bookings SET status = 'cancelled' WHERE id = ?");
                    $upB->execute([$id]);

                    $upV = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ? AND status = 'reserved'");
                    $upV->execute([$bData['vehicle_id']]);

                    $db->commit();
                    showAlert('Unit berhasil dirilis kembali ke status Siap Jual dan SPK dibatalkan.', 'success');
                    redirect('bookings/view.php?id=' . $id);
                } else {
                    $db->rollBack();
                    showAlert('Status SPK tidak dapat dibatalkan.', 'warning');
                }
            } catch (Exception $e) {
                $db->rollBack();
                showAlert('Gagal merilis unit: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Fetch booking details
try {
    $stmt = $db->prepare("
        SELECT 
            b.*,
            v.license_plate, v.year as vehicle_year, v.color as vehicle_color,
            v.chassis_number, v.engine_number, v.mileage, v.transmission, v.fuel_type,
            v.selling_price as vehicle_original_price, v.status as vehicle_status,
            CONCAT(br.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name,
            br.name as brand_name, m.name as model_name,
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.id_card as customer_nik,
            u.full_name as staff_name,
            DATEDIFF(b.expiry_date, CURDATE()) as days_remaining,
            t.transaction_code, t.id as real_trx_id
        FROM vehicle_bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands br ON m.brand_id = br.id
        JOIN customers c ON b.customer_id = c.id
        JOIN users u ON b.user_id = u.id
        LEFT JOIN transactions t ON b.transaction_id = t.id
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        showAlert('Data SPK tidak ditemukan.', 'danger');
        redirect('bookings/');
    }

} catch (PDOException $e) {
    showAlert('Kesalahan database: ' . $e->getMessage(), 'danger');
    redirect('bookings/');
}

$badgeClass = 'status-cancelled';
$statusLabel = 'Dibatalkan';
if ($booking['status'] === 'active') {
    $badgeClass = 'status-in_progress';
    $statusLabel = 'Aktif (Hold Unit)';
} elseif ($booking['status'] === 'converted') {
    $badgeClass = 'status-completed';
    $statusLabel = 'Selesai (Terjual)';
} elseif ($booking['status'] === 'expired') {
    $badgeClass = 'status-expired';
    $statusLabel = 'Expired';
}

$phoneClean = preg_replace('/[^0-9]/', '', $booking['customer_phone']);
if (strpos($phoneClean, '0') === 0) {
    $phoneClean = '62' . substr($phoneClean, 1);
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
        border: none;
    }
    .btn-header-primary:hover {
        background: #1e272e;
        color: #fff;
    }

    /* Form Cards */
    .form-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .form-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
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
        padding: 24px;
    }

    /* Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-in_progress { background: #fef3c7; color: #92400e; }
    .status-expired { background: #fee2e2; color: #991b1b; }
    .status-cancelled { background: #f1f5f9; color: #64748b; }

    .plate-badge {
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
    .price-text {
        font-weight: 700;
        color: #1e293b;
        white-space: nowrap;
    }

    /* Financial Summary Box */
    .financial-box {
        background: #fafbfc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px;
    }
    .financial-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 0;
        font-size: 0.85rem;
    }
    .financial-row.total-row {
        border-top: 1px solid #e2e8f0;
        padding-top: 12px;
        margin-top: 6px;
        font-size: 0.95rem;
        font-weight: 700;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .form-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .form-card-title { color: #f1f5f9; }
    [data-theme="dark"] .financial-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .financial-row.total-row { border-top-color: #334155; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
</style>

<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1><?php echo htmlspecialchars($booking['booking_code']); ?></h1>
            <span class="status-badge <?php echo $badgeClass; ?>">
                <?php echo $statusLabel; ?>
            </span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">SPK & Booking Fee</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($booking['booking_code']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i>
            <span>Kembali</span>
        </a>
        <a href="print.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn-header-action">
            <i class="fas fa-print"></i>
            <span>Cetak SPK (PDF)</span>
        </a>
        <?php if ($booking['status'] === 'active'): ?>
            <a href="<?php echo BASE_URL; ?>transactions/create.php?vehicle_id=<?php echo $booking['vehicle_id']; ?>&from_booking=<?php echo $booking['id']; ?>" class="btn-header-action btn-header-primary" style="background: #16a34a; border-color: #16a34a;">
                <i class="fas fa-check-circle"></i>
                <span>Lanjut Pelunasan</span>
            </a>
            <button type="button" class="btn-header-action text-danger" onclick="openReleaseModal()">
                <i class="fas fa-undo"></i>
                <span>Rilis Unit</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Kolom Kiri: Detail Kendaraan & Pelanggan -->
    <div class="col-lg-7">
        <!-- Kendaraan Card -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <i class="fas fa-car text-primary"></i>
                    <span>Kendaraan yang Dipesan</span>
                </h3>
                <a href="<?php echo BASE_URL; ?>vehicles/view.php?id=<?php echo $booking['vehicle_id']; ?>" class="btn-header-action" style="padding: 4px 10px; font-size: 0.75rem;">
                    <span>Lihat Mobil</span> <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="form-card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <div class="text-muted small">Nama Kendaraan</div>
                        <div class="fw-bold text-dark fs-6 mt-0.5"><?php echo htmlspecialchars($booking['vehicle_name']); ?></div>
                    </div>
                    <div class="col-md-5">
                        <div class="text-muted small">Nomor Polisi</div>
                        <div class="mt-0.5"><span class="plate-badge"><?php echo htmlspecialchars($booking['license_plate']); ?></span></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Warna</div>
                        <div class="fw-semibold text-dark mt-0.5"><?php echo htmlspecialchars($booking['vehicle_color']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Transmisi</div>
                        <div class="fw-semibold text-dark mt-0.5"><?php echo strtoupper($booking['transmission']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Bahan Bakar</div>
                        <div class="fw-semibold text-dark mt-0.5"><?php echo ucfirst($booking['fuel_type']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Nomor Rangka (VIN)</div>
                        <div class="font-monospace small mt-0.5"><?php echo htmlspecialchars($booking['chassis_number'] ?: '-'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Nomor Mesin</div>
                        <div class="font-monospace small mt-0.5"><?php echo htmlspecialchars($booking['engine_number'] ?: '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Pemesan -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <i class="fas fa-user text-primary"></i>
                    <span>Identitas Calon Pembeli (Pemesan)</span>
                </h3>
            </div>
            <div class="form-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">Nama Lengkap</div>
                        <div class="fw-bold text-dark fs-6 mt-0.5"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Nomor WhatsApp / HP</div>
                        <div class="mt-0.5">
                            <a href="https://wa.me/<?php echo $phoneClean; ?>" target="_blank" class="text-decoration-none text-muted d-inline-flex align-items-center gap-1.5 fw-semibold">
                                <i class="fab fa-whatsapp text-success fs-6"></i>
                                <span><?php echo htmlspecialchars($booking['customer_phone']); ?></span>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Nomor Identitas (NIK)</div>
                        <div class="mt-0.5 text-dark"><?php echo htmlspecialchars($booking['customer_nik'] ?: '-'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Alamat Domisili</div>
                        <div class="mt-0.5 text-dark"><?php echo htmlspecialchars($booking['customer_address'] ?: '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Rincian Keuangan & Masa Berlaku -->
    <div class="col-lg-5">
        <!-- Keuangan -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <i class="fas fa-receipt text-success"></i>
                    <span>Rincian Pembayaran & Tanda Jadi</span>
                </h3>
            </div>
            <div class="form-card-body">
                <div class="financial-box mb-4">
                    <div class="financial-row">
                        <span class="text-muted">Harga Kesepakatan (OTR)</span>
                        <span class="fw-semibold text-dark">Rp <?php echo number_format($booking['agreed_price'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="financial-row">
                        <span class="text-muted">Tanda Jadi (Booking Fee)</span>
                        <span class="fw-bold text-success">- Rp <?php echo number_format($booking['booking_fee'], 0, ',', '.'); ?></span>
                    </div>
                    <div class="financial-row total-row">
                        <span class="text-dark">Sisa Pelunasan</span>
                        <span class="price-text text-primary">Rp <?php echo number_format($booking['agreed_price'] - $booking['booking_fee'], 0, ',', '.'); ?></span>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Metode Bayar Booking Fee</div>
                    <div class="fw-semibold text-dark mt-0.5">
                        <?php echo htmlspecialchars($booking['payment_method'] === 'bank_transfer' ? 'Transfer Bank' : ($booking['payment_method'] === 'cash' ? 'Tunai / Cash' : 'Kartu Debit / EDC')); ?>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Batas Waktu Hold Unit (Expiry Date)</div>
                    <div class="fw-semibold text-dark mt-0.5">
                        <?php echo date('d F Y', strtotime($booking['expiry_date'])); ?>
                        <?php if ($booking['status'] === 'active'): ?>
                            <?php if ($booking['days_remaining'] > 0): ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning ms-1">Sisa <?php echo $booking['days_remaining']; ?> hari</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger ms-1">Masa Hold Berakhir</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Sales / Staf Showroom</div>
                    <div class="fw-semibold text-dark mt-0.5"><?php echo htmlspecialchars($booking['staff_name']); ?></div>
                </div>

                <?php if (!empty($booking['notes'])): ?>
                    <div class="p-3 bg-light rounded-3 border small text-muted mt-3">
                        <i class="fas fa-info-circle me-1 text-secondary"></i>
                        <strong>Catatan:</strong> <?php echo htmlspecialchars($booking['notes']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($booking['status'] === 'converted' && !empty($booking['transaction_code'])): ?>
                    <div class="alert alert-success border-0 shadow-sm mt-3 mb-0 py-2.5 px-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="small fw-semibold">Sudah Dilunasi di Transaksi:</span>
                            <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $booking['real_trx_id']; ?>" class="fw-bold text-success text-decoration-none">
                                <?php echo htmlspecialchars($booking['transaction_code']); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Release Unit -->
<div class="modal fade" id="releaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="release_unit">

                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold text-dark fs-6">Rilis Unit ke Siap Jual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-2">Apakah Anda yakin ingin membatalkan reservasi unit ini?</p>
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($booking['booking_code']); ?></div>
                        <div class="text-muted small"><?php echo htmlspecialchars($booking['vehicle_name']); ?></div>
                    </div>
                    <div class="alert alert-warning border-0 small py-2 px-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i> Unit mobil akan otomatis kembali berstatus <strong>available (Siap Jual)</strong> dan status SPK ini akan diubah menjadi <strong>cancelled</strong>.
                    </div>
                </div>
                <div class="modal-footer border-top bg-light py-2 px-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-danger px-4 fw-semibold">Ya, Rilis Unit Sekarang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReleaseModal() {
    const myModal = new bootstrap.Modal(document.getElementById('releaseModal'));
    myModal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
