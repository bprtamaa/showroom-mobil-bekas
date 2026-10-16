<?php
// documents/delivery_order.php
$pageTitle = 'Surat Jalan Kendaraan (Delivery Order)';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/document_helpers.php';
requireLogin();

$transactionId = (int)($_GET['transaction_id'] ?? 0);

if (!$transactionId) {
    showAlert('ID transaksi tidak valid.', 'danger');
    redirect('transactions/');
}

try {
    $stmt = $db->prepare("
        SELECT 
            t.*,
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
            u.full_name as user_name,
            v.license_plate, v.chassis_number, v.engine_number, v.year, v.color, v.mileage,
            b.name as brand_name,
            m.name as model_name
        FROM transactions t
        JOIN customers c ON t.customer_id = c.id
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$transactionId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        showAlert('Data transaksi tidak ditemukan.', 'danger');
        redirect('transactions/');
    }

    $profile = getShowroomCompanyProfile();
    $doNumber = 'SJ/' . date('Ym', strtotime($data['transaction_date'])) . '/' . str_pad($data['id'], 5, '0', STR_PAD_LEFT);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('transactions/');
}
?>

<style>
.do-paper {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 3rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    max-width: 840px;
    margin: 0 auto 3rem auto;
    color: #1e293b;
}

.do-table th {
    background: #f8fafc;
    font-size: 0.8rem;
    text-transform: uppercase;
    color: #475569;
}

[data-theme="dark"] .do-paper {
    background: #ffffff;
    color: #1e293b;
}

@media print {
    .navbar, .sidebar, .page-header, .no-print, #themeToggleBtn {
        display: none !important;
    }
    body, .main-content {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .do-paper {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
    }
}
</style>

<!-- Action Header -->
<div class="page-header mb-3 no-print">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="mb-1">Surat Jalan Kendaraan</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/">Transaksi</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>">Detail</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Surat Jalan</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Kembali
            </a>
            <button type="button" class="btn btn-primary shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1.5"></i>Cetak Surat Jalan
            </button>
        </div>
    </div>
</div>

<!-- Delivery Order Paper -->
<div class="do-paper">
    <?php echo renderOfficialDocumentHeader('SURAT JALAN PENGIRIMAN', $doNumber); ?>

    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Tujuan Pengiriman / Penerima:</div>
            <div class="fw-bold fs-6 text-dark"><?php echo htmlspecialchars($data['customer_name']); ?></div>
            <div class="text-muted small mt-1"><?php echo htmlspecialchars($data['customer_address'] ?: 'Alamat tertera di data pembeli'); ?></div>
            <div class="text-muted small">No. HP: <?php echo htmlspecialchars($data['customer_phone']); ?></div>
        </div>

        <div class="col-6 text-end">
            <div class="text-muted small fw-bold text-uppercase mb-1">Detail Pengiriman:</div>
            <div class="small">Tanggal: <strong><?php echo formatDate($data['transaction_date'], 'd F Y'); ?></strong></div>
            <div class="small">Ref Transaksi: <strong class="font-monospace text-primary"><?php echo htmlspecialchars($data['transaction_code']); ?></strong></div>
            <div class="small">Petugas: <strong><?php echo htmlspecialchars($data['user_name'] ?: 'Staff Logistik'); ?></strong></div>
        </div>
    </div>

    <p class="small mb-3">Harap diterima 1 (satu) unit kendaraan bermotor dengan rincian sebagai berikut:</p>

    <div class="table-responsive mb-4">
        <table class="table table-bordered align-middle do-table">
            <thead>
                <tr>
                    <th style="width: 40px;" class="text-center">NO</th>
                    <th>UNIT KENDARAAN</th>
                    <th>NO. POLISI</th>
                    <th>NO. RANGKA / MESIN</th>
                    <th>WARNA / KM</th>
                    <th class="text-center" style="width: 100px;">KONDISI</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center fw-bold">1</td>
                    <td>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($data['brand_name'] . ' ' . $data['model_name']); ?></div>
                        <div class="small text-muted">Tahun <?php echo (int)$data['year']; ?></div>
                    </td>
                    <td><span class="badge bg-dark font-monospace"><?php echo htmlspecialchars($data['license_plate']); ?></span></td>
                    <td class="small font-monospace">
                        Rangka: <?php echo htmlspecialchars($data['chassis_number']); ?><br>
                        Mesin: <?php echo htmlspecialchars($data['engine_number']); ?>
                    </td>
                    <td class="small">
                        <?php echo htmlspecialchars($data['color']); ?><br>
                        <?php echo number_format($data['mileage']); ?> KM
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold">Baik / Siap Pakai</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="p-3 bg-light rounded border mb-4 small">
        <strong>Catatan Pengemudi & Pengiriman:</strong>
        <p class="text-muted mb-0 mt-1">
            Pengemudi wajib mematuhi peraturan lalu lintas yang berlaku. Segera periksa kelengkapan fisik dan nomor rangka kendaraan saat serah terima di lokasi penerima.
        </p>
    </div>

    <!-- Signatures 3 Columns -->
    <div class="row text-center mt-5 pt-3">
        <div class="col-4">
            <div class="fw-bold small text-dark mb-1">Pengirim / Showroom</div>
            <div style="height: 65px;"></div>
            <div class="border-top border-dark pt-1 small fw-bold"><?php echo htmlspecialchars($data['user_name'] ?: 'Staff Showroom'); ?></div>
        </div>
        <div class="col-4">
            <div class="fw-bold small text-dark mb-1">Pengemudi / Driver</div>
            <div style="height: 65px;"></div>
            <div class="border-top border-dark pt-1 small fw-bold">( .................................... )</div>
        </div>
        <div class="col-4">
            <div class="fw-bold small text-dark mb-1">Penerima / Pembeli</div>
            <div style="height: 65px;"></div>
            <div class="border-top border-dark pt-1 small fw-bold"><?php echo htmlspecialchars($data['customer_name']); ?></div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
