<?php
// documents/receipt.php
$pageTitle = 'Kwitansi Pembayaran Resmi';
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
            v.license_plate, v.chassis_number, v.engine_number, v.year, v.color,
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
    $kwNumber = 'KW/' . date('Ym', strtotime($data['transaction_date'])) . '/' . str_pad($data['id'], 5, '0', STR_PAD_LEFT);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('transactions/');
}
?>

<style>
/* Kwitansi Styling */
.kw-paper {
    background: #ffffff;
    border: 2px solid #cbd5e1;
    border-radius: 12px;
    padding: 3rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    max-width: 840px;
    margin: 0 auto 3rem auto;
    color: #1e293b;
    position: relative;
}

.kw-row {
    display: flex;
    align-items: flex-start;
    margin-bottom: 1.25rem;
}

.kw-label {
    width: 200px;
    font-weight: 600;
    color: #475569;
    flex-shrink: 0;
    font-size: 0.875rem;
}

.kw-sep {
    width: 20px;
    font-weight: 600;
}

.kw-val {
    flex-grow: 1;
    font-size: 0.925rem;
}

.terbilang-box {
    background: #f1f5f9;
    border: 1px dashed #94a3b8;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-style: italic;
    font-weight: 600;
    color: #0f172a;
}

.nominal-box {
    display: inline-flex;
    align-items: center;
    background: #0f172a;
    color: #ffffff;
    padding: 0.75rem 1.5rem;
    border-radius: 10px;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

[data-theme="dark"] .kw-paper {
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
    .kw-paper {
        border: 2px solid #000000 !important;
        box-shadow: none !important;
        padding: 2rem !important;
        max-width: 100% !important;
    }
}
</style>

<!-- Action Header -->
<div class="page-header mb-3 no-print">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="mb-1">Kwitansi Pembayaran Resmi</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/">Transaksi</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>">Detail</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Kwitansi</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Kembali
            </a>
            <button type="button" class="btn btn-primary shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1.5"></i>Cetak Kwitansi
            </button>
        </div>
    </div>
</div>

<!-- Kwitansi Paper -->
<div class="kw-paper">
    <?php echo renderOfficialDocumentHeader('KWITANSI PEMBAYARAN', $kwNumber); ?>

    <div class="kw-row">
        <div class="kw-label">Telah Diterima Dari</div>
        <div class="kw-sep">:</div>
        <div class="kw-val fw-bold text-dark fs-6"><?php echo htmlspecialchars($data['customer_name']); ?></div>
    </div>

    <div class="kw-row">
        <div class="kw-label">Uang Sejumlah</div>
        <div class="kw-sep">:</div>
        <div class="kw-val">
            <div class="terbilang-box">
                "<?php echo terbilang($data['total_amount']); ?>"
            </div>
        </div>
    </div>

    <div class="kw-row">
        <div class="kw-label">Untuk Pembayaran</div>
        <div class="kw-sep">:</div>
        <div class="kw-val">
            Pelunasan Pembelian 1 Unit Kendaraan Bermotor:
            <div class="fw-bold text-dark mt-1">
                <?php echo htmlspecialchars($data['brand_name'] . ' ' . $data['model_name'] . ' (' . $data['year'] . ')'); ?>
            </div>
            <div class="small text-muted mt-0.5">
                No. Polisi: <strong class="text-dark"><?php echo htmlspecialchars($data['license_plate']); ?></strong> | 
                No. Rangka: <span class="font-monospace"><?php echo htmlspecialchars($data['chassis_number']); ?></span>
            </div>
        </div>
    </div>

    <div class="kw-row">
        <div class="kw-label">Metode Pembayaran</div>
        <div class="kw-sep">:</div>
        <div class="kw-val">
            <span class="badge bg-dark px-2.5 py-1 text-uppercase fw-semibold"><?php echo htmlspecialchars($data['payment_type'] ?: $data['payment_method']); ?></span>
            <span class="badge bg-success bg-opacity-10 text-success ms-2 px-2.5 py-1 fw-bold"><?php echo strtoupper($data['payment_status']); ?></span>
        </div>
    </div>

    <!-- Bottom Nominal & Signature -->
    <div class="row align-items-end mt-5 pt-4 border-top">
        <div class="col-6">
            <div class="text-muted small fw-semibold mb-1.5">JUMLAH PEMBAYARAN:</div>
            <div class="nominal-box">
                <?php echo formatCurrency($data['total_amount']); ?>
            </div>
        </div>

        <div class="col-6 text-end">
            <div class="small text-muted mb-4"><?php echo $profile['city']; ?>, <?php echo formatDate($data['transaction_date'], 'd F Y'); ?></div>
            <div class="d-inline-block text-center border-top border-dark pt-2" style="width: 180px;">
                <div class="fw-bold text-dark"><?php echo htmlspecialchars($data['user_name'] ?: 'Kasir Showroom'); ?></div>
                <div class="text-muted small">Bagian Keuangan / Kasir</div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
