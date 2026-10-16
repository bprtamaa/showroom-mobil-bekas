<?php
// documents/bast.php
$pageTitle = 'Berita Acara Serah Terima (BAST)';
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
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.id_card as customer_nik,
            u.full_name as user_name,
            v.license_plate, v.chassis_number, v.engine_number, v.year, v.color, v.mileage, v.transmission,
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
    $bastNumber = 'BAST/' . date('Ym', strtotime($data['transaction_date'])) . '/' . str_pad($data['id'], 5, '0', STR_PAD_LEFT);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('transactions/');
}
?>

<style>
.bast-paper {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 3.5rem 3rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    max-width: 860px;
    margin: 0 auto 3rem auto;
    color: #1e293b;
    font-size: 0.885rem;
}

.bast-table th {
    background: #f8fafc;
    font-size: 0.8rem;
    text-transform: uppercase;
}

[data-theme="dark"] .bast-paper {
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
    .bast-paper {
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
            <h1 class="mb-1">Berita Acara Serah Terima (BAST)</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/">Transaksi</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>">Detail</a></li>
                    <li class="breadcrumb-item active" aria-current="page">BAST</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Kembali
            </a>
            <button type="button" class="btn btn-primary shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1.5"></i>Cetak BAST
            </button>
        </div>
    </div>
</div>

<!-- Printable BAST Paper -->
<div class="bast-paper">
    <?php echo renderOfficialDocumentHeader('BERITA ACARA SERAH TERIMA KENDARAAN', $bastNumber); ?>

    <p class="mb-3">
        Pada hari ini, tanggal <strong><?php echo formatDate($data['transaction_date'], 'd F Y'); ?></strong>, telah dilakukan serah terima fisik dan kelengkapan unit kendaraan bermotor oleh dan antara:
    </p>

    <div class="row g-3 mb-4">
        <div class="col-6">
            <div class="p-2.5 rounded border bg-light">
                <div class="fw-bold text-dark small text-uppercase mb-1">Pihak Pertama (Yang Menyerahkan):</div>
                <div><strong><?php echo htmlspecialchars($profile['name']); ?></strong></div>
                <div class="small text-muted">Petugas: <?php echo htmlspecialchars($data['user_name'] ?: 'Staff Showroom'); ?></div>
            </div>
        </div>
        <div class="col-6">
            <div class="p-2.5 rounded border bg-light">
                <div class="fw-bold text-dark small text-uppercase mb-1">Pihak Kedua (Yang Menerima):</div>
                <div><strong><?php echo htmlspecialchars($data['customer_name']); ?></strong></div>
                <div class="small text-muted">No. HP: <?php echo htmlspecialchars($data['customer_phone']); ?></div>
            </div>
        </div>
    </div>

    <div class="p-3 bg-light rounded border mb-4">
        <div class="fw-bold text-dark mb-2">Identitas Kendaraan Bermotor:</div>
        <div class="row g-2 small">
            <div class="col-6">
                <div>Merek / Model: <strong><?php echo htmlspecialchars($data['brand_name'] . ' ' . $data['model_name'] . ' (' . $data['year'] . ')'); ?></strong></div>
                <div>Nomor Polisi: <strong><?php echo htmlspecialchars($data['license_plate']); ?></strong></div>
                <div>Warna: <strong><?php echo htmlspecialchars($data['color']); ?></strong></div>
            </div>
            <div class="col-6">
                <div>Nomor Rangka: <span class="font-monospace fw-bold"><?php echo htmlspecialchars($data['chassis_number']); ?></span></div>
                <div>Nomor Mesin: <span class="font-monospace fw-bold"><?php echo htmlspecialchars($data['engine_number']); ?></span></div>
                <div>Kilometer Saat Serah: <strong><?php echo number_format($data['mileage']); ?> KM</strong></div>
            </div>
        </div>
    </div>

    <!-- Checklist Kelengkapan -->
    <div class="fw-bold text-dark mb-2">Daftar Checklist Kelengkapan Fisik & Dokumen:</div>
    <div class="table-responsive mb-4">
        <table class="table table-bordered align-middle bast-table">
            <thead>
                <tr>
                    <th style="width: 40px;" class="text-center">No</th>
                    <th>Item Kelengkapan</th>
                    <th style="width: 140px;" class="text-center">Status</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center">1</td>
                    <td>Surat Tanda Nomor Kendaraan (STNK) Asli</td>
                    <td class="text-center"><span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Diserahkan</span></td>
                    <td class="small text-muted">Masa berlaku aktif</td>
                </tr>
                <tr>
                    <td class="text-center">2</td>
                    <td>Buku Pemilik Kendaraan Bermotor (BPKB) & Faktur</td>
                    <td class="text-center">
                        <?php if ($data['payment_type'] === 'leasing' || $data['payment_method'] === 'installment'): ?>
                        <span class="badge bg-info bg-opacity-10 text-info fw-bold">Di Leasing</span>
                        <?php else: ?>
                        <span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Diserahkan</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?php echo ($data['payment_type'] === 'leasing' || $data['payment_method'] === 'installment') ? 'Sebagai jaminan pembiayaan kredit' : 'Asli lengkap'; ?></td>
                </tr>
                <tr>
                    <td class="text-center">3</td>
                    <td>Kunci Utama & Kunci Cadangan (Spare Key)</td>
                    <td class="text-center"><span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Lengkap</span></td>
                    <td class="small text-muted">2 Pcs Kunci Remote</td>
                </tr>
                <tr>
                    <td class="text-center">4</td>
                    <td>Buku Manual Kendaraan & Buku Servis</td>
                    <td class="text-center"><span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Lengkap</span></td>
                    <td class="small text-muted">Tersimpan di dashboard</td>
                </tr>
                <tr>
                    <td class="text-center">5</td>
                    <td>Toolkit Standar & Dongkrak Asli</td>
                    <td class="text-center"><span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Lengkap</span></td>
                    <td class="small text-muted">Lengkap di bagasi</td>
                </tr>
                <tr>
                    <td class="text-center">6</td>
                    <td>Ban Cadangan (Serep) & Segitiga Pengaman</td>
                    <td class="text-center"><span class="badge bg-success bg-opacity-10 text-success fw-bold">&#10003; Lengkap</span></td>
                    <td class="small text-muted">Tekanan angin standar</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="small text-muted mb-4">
        Dengan ditandatanganinya Berita Acara ini, PIHAK KEDUA menyatakan telah menerima unit kendaraan bermotor beserta seluruh kelengkapan dokumen di atas dalam keadaan baik, lengkap, dan berfungsi normal.
    </p>

    <!-- Signatures -->
    <div class="row text-center mt-5 pt-3">
        <div class="col-6">
            <div class="fw-bold small text-dark mb-1">Yang Menyerahkan (Showroom)</div>
            <div style="height: 65px;"></div>
            <div class="border-top border-dark pt-1 small fw-bold d-inline-block px-4"><?php echo htmlspecialchars($data['user_name'] ?: 'Staff Showroom'); ?></div>
        </div>
        <div class="col-6">
            <div class="fw-bold small text-dark mb-1">Yang Menerima (Konsumen)</div>
            <div style="height: 65px;"></div>
            <div class="border-top border-dark pt-1 small fw-bold d-inline-block px-4"><?php echo htmlspecialchars($data['customer_name']); ?></div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
