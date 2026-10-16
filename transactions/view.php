<?php
// transactions/view.php - Detail Lengkap Transaksi Penjualan
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
    showAlert('ID transaksi tidak valid', 'danger');
    redirect('transactions/');
}

try {
    // Basic Transaction Info
    $stmt = $db->prepare("
        SELECT t.*,
               c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.id_card as customer_nik,
               u.full_name as user_name,
               v.id as vehicle_id,
               v.license_plate, v.chassis_number, v.engine_number, v.year as vehicle_year,
               v.color as vehicle_color, v.mileage as vehicle_mileage, v.transmission,
               m.name as model_name,
               b.name as brand_name,
               ct.name as car_type_name
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN vehicles v ON t.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        showAlert('Transaksi penjualan tidak ditemukan', 'danger');
        redirect('transactions/');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('transactions/');
}

// Try to get leasing name if column exists and value present
$leasingName = '';
if (!empty($transaction['leasing_company_id'])) {
    try {
        $lstmt = $db->prepare('SELECT name FROM leasing WHERE id = ? LIMIT 1');
        $lstmt->execute([(int)$transaction['leasing_company_id']]);
        $leasingName = $lstmt->fetchColumn() ?: '';
    } catch (Exception $__ignore) {
        $leasingName = '';
    }
}

// Fix payment status for legacy data
$actualPaymentStatus = $transaction['payment_status'] ?? 'pending';
if (empty($actualPaymentStatus)) $actualPaymentStatus = 'pending';
if ($actualPaymentStatus == 'pending' && ($transaction['payment_method'] ?? '') == 'installment') {
    $actualPaymentStatus = 'installment';
}

$statusBadgeClass = 'status-scheduled';
$statusLabelText = 'Cicilan';
switch($actualPaymentStatus) {
    case 'paid': $statusBadgeClass = 'status-completed'; $statusLabelText = 'Lunas'; break;
    case 'installment':
    case 'partial': $statusBadgeClass = 'status-installment'; $statusLabelText = 'Cicilan'; break;
    case 'pending': $statusBadgeClass = 'status-cancelled'; $statusLabelText = 'Pending'; break;
}

$paymentMethodMap = [
    'cash' => 'Tunai / Cash',
    'bank_transfer' => 'Transfer Bank',
    'credit' => 'Kartu Kredit',
    'installment' => 'Kredit / Leasing'
];
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
    .btn-header-success {
        background: #16a34a;
        color: #fff;
        border-color: #16a34a;
    }
    .btn-header-success:hover {
        background: #15803d;
        color: #fff;
        border-color: #15803d;
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

    .spec-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
    }
    .spec-item {
        display: flex;
        flex-direction: column;
    }
    .spec-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 2px;
    }
    .spec-val {
        font-size: 0.88rem;
        font-weight: 700;
        color: #1e293b;
    }

    /* Financial Breakdown List */
    .fin-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    .fin-label {
        font-size: 0.82rem;
        color: #64748b;
    }
    .fin-val {
        font-size: 0.92rem;
        font-weight: 700;
        color: #1e293b;
    }
    .fin-total-val {
        font-size: 1.4rem;
        font-weight: 800;
        color: #16a34a;
        letter-spacing: -0.02em;
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
        white-space: nowrap;
    }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-installment { background: #fef3c7; color: #92400e; }
    .status-scheduled, .status-code { background: #e0f2fe; color: #075985; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }

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

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .view-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .spec-val,
    [data-theme="dark"] .fin-val { color: #f1f5f9; }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .fin-total-val { color: #4ade80; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1>Detail Transaksi Penjualan</h1>
            <span class="status-badge status-code fw-bold">#<?php echo htmlspecialchars($transaction['transaction_code']); ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/" class="text-primary text-decoration-underline">Transaksi Penjualan</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($transaction['transaction_code']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>transactions/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>

        <!-- Legal Documents Dropdown -->
        <div class="dropdown">
            <button class="btn-header-action dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-file-contract"></i> Dokumen Legal & SPJB
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius:10px;">
                <li><h6 class="dropdown-header text-uppercase" style="font-size: 0.68rem;">Penerbitan Dokumen Resmi</h6></li>
                <li><a class="dropdown-item py-2 small" href="<?php echo BASE_URL; ?>documents/spjb.php?transaction_id=<?php echo $transaction['id']; ?>" target="_blank"><i class="fas fa-file-signature text-primary me-2"></i>Surat Perjanjian Jual Beli (SPJB)</a></li>
                <li><a class="dropdown-item py-2 small" href="<?php echo BASE_URL; ?>documents/receipt.php?transaction_id=<?php echo $transaction['id']; ?>" target="_blank"><i class="fas fa-receipt text-success me-2"></i>Kwitansi Pembayaran Resmi</a></li>
                <li><a class="dropdown-item py-2 small" href="<?php echo BASE_URL; ?>documents/delivery_order.php?transaction_id=<?php echo $transaction['id']; ?>" target="_blank"><i class="fas fa-truck-moving text-info me-2"></i>Surat Jalan Pengiriman (SJ)</a></li>
                <li><a class="dropdown-item py-2 small" href="<?php echo BASE_URL; ?>documents/bast.php?transaction_id=<?php echo $transaction['id']; ?>" target="_blank"><i class="fas fa-clipboard-check text-warning me-2"></i>Berita Acara Serah Terima (BAST)</a></li>
            </ul>
        </div>

        <a href="print.php?id=<?php echo $transaction['id']; ?>" class="btn-header-action btn-header-success" target="_blank">
            <i class="fas fa-print"></i> Cetak Invoice
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Car Specs & Customer -->
    <div class="col-12 col-xl-7 col-lg-6">
        <!-- Vehicle Details Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-car text-primary"></i> Unit Kendaraan Terjual
                </h5>
                <span class="plate-badge"><?php echo htmlspecialchars($transaction['license_plate'] ?: 'No Plat'); ?></span>
            </div>
            <div class="view-card-body">
                <h4 class="fw-bold text-dark fs-5 mb-3"><?php echo htmlspecialchars($transaction['brand_name'] . ' ' . $transaction['model_name'] . ' (' . $transaction['vehicle_year'] . ')'); ?></h4>
                <div class="spec-grid">
                    <div class="spec-item">
                        <span class="spec-label">Tipe Bodi</span>
                        <span class="spec-val"><?php echo htmlspecialchars($transaction['car_type_name'] ?: 'Sedan/SUV/MPV'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Transmisi</span>
                        <span class="spec-val"><?php echo ucfirst($transaction['transmission'] ?: 'automatic'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Warna Kendaraan</span>
                        <span class="spec-val"><?php echo htmlspecialchars($transaction['vehicle_color'] ?: '-'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Odometer (KM)</span>
                        <span class="spec-val"><?php echo number_format((int)$transaction['vehicle_mileage']); ?> KM</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Nomor Rangka</span>
                        <span class="spec-val"><?php echo htmlspecialchars($transaction['chassis_number'] ?: '-'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Nomor Mesin</span>
                        <span class="spec-val"><?php echo htmlspecialchars($transaction['engine_number'] ?: '-'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Card -->
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-user text-primary"></i> Data Pelanggan Pembeli
                </h5>
            </div>
            <div class="view-card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="fas fa-user text-primary"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($transaction['customer_name']); ?></h5>
                        <div class="text-muted small"><?php echo htmlspecialchars($transaction['customer_phone']); ?> • NIK: <?php echo htmlspecialchars($transaction['customer_nik'] ?: '-'); ?></div>
                    </div>
                </div>
                <div class="pt-2 border-top">
                    <span class="spec-label d-block mb-1">Alamat Domisili:</span>
                    <div class="text-dark small" style="line-height:1.4;">
                        <?php echo !empty($transaction['customer_address']) ? nl2br(htmlspecialchars($transaction['customer_address'])) : '-'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Financial & Transaction Status -->
    <div class="col-12 col-xl-5 col-lg-6">
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-receipt text-primary"></i> Rincian Pembayaran & Status
                </h5>
                <span class="status-badge <?php echo $statusBadgeClass; ?>">
                    <?php echo $statusLabelText; ?>
                </span>
            </div>
            <div class="view-card-body">
                <!-- Status Row -->
                <div class="fin-row">
                    <span class="fin-label">Tanggal Transaksi:</span>
                    <span class="fin-val"><?php echo formatDate($transaction['transaction_date'], 'd M Y'); ?></span>
                </div>

                <div class="fin-row">
                    <span class="fin-label">Metode Pembayaran:</span>
                    <span class="fin-val text-uppercase"><?php echo $paymentMethodMap[$transaction['payment_method']] ?? $transaction['payment_method']; ?></span>
                </div>

                <?php if (!empty($leasingName)): ?>
                <div class="fin-row">
                    <span class="fin-label">Mitra Leasing:</span>
                    <span class="fin-val text-primary fw-bold"><?php echo htmlspecialchars($leasingName); ?></span>
                </div>
                <?php endif; ?>

                <div class="fin-row">
                    <span class="fin-label">Kasir / Staf Penjualan:</span>
                    <span class="fin-val"><?php echo htmlspecialchars($transaction['user_name'] ?: 'Admin'); ?></span>
                </div>

                <hr class="my-3">

                <!-- Price Rows -->
                <div class="fin-row">
                    <span class="fin-label">Harga Kesepakatan Unit:</span>
                    <span class="fin-val"><?php echo formatCurrency($transaction['sale_price']); ?></span>
                </div>

                <?php if (!empty($transaction['discount']) && $transaction['discount'] > 0): ?>
                <div class="fin-row">
                    <span class="fin-label">Potongan Diskon:</span>
                    <span class="fin-val text-danger">- <?php echo formatCurrency($transaction['discount']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($transaction['tax']) && $transaction['tax'] > 0): ?>
                <div class="fin-row">
                    <span class="fin-label">Biaya / Pajak Administrasi:</span>
                    <span class="fin-val">+ <?php echo formatCurrency($transaction['tax']); ?></span>
                </div>
                <?php endif; ?>

                <div class="pt-3 border-top mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark">Total Transaksi:</span>
                        <div class="fin-total-val"><?php echo formatCurrency($transaction['total_amount']); ?></div>
                    </div>
                </div>

                <?php if (!empty($transaction['notes'])): ?>
                <div class="mt-4 pt-3 border-top">
                    <span class="spec-label d-block mb-1">Catatan Transaksi:</span>
                    <div class="p-3 bg-light rounded text-dark small" style="line-height:1.4;">
                        <?php echo nl2br(htmlspecialchars($transaction['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>