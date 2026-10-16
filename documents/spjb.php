<?php
// documents/spjb.php
$pageTitle = 'Surat Perjanjian Jual Beli (SPJB)';
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
            v.license_plate, v.chassis_number, v.engine_number, v.year, v.color, v.mileage, v.transmission, v.fuel_type,
            b.name as brand_name,
            m.name as model_name,
            ct.name as car_type_name,
            l.name as leasing_name
        FROM transactions t
        JOIN customers c ON t.customer_id = c.id
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN leasing l ON t.leasing_company_id = l.id
        WHERE t.id = ?
    ");
    $stmt->execute([$transactionId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        showAlert('Data transaksi tidak ditemukan.', 'danger');
        redirect('transactions/');
    }

    $profile = getShowroomCompanyProfile();
    $spjbNumber = 'SPJB/' . date('Ym', strtotime($data['transaction_date'])) . '/' . str_pad($data['id'], 4, '0', STR_PAD_LEFT);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('transactions/');
}
?>

<style>
/* SPJB Document Styles */
.spjb-paper {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 3.5rem 3rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    max-width: 860px;
    margin: 0 auto 3rem auto;
    color: #1e293b;
    font-size: 0.9rem;
    line-height: 1.6;
}

.spjb-article-title {
    font-size: 0.95rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #0f172a;
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 3px;
}

.spjb-spec-table td {
    padding: 4px 8px;
    font-size: 0.85rem;
}

.sig-box {
    width: 220px;
    text-align: center;
}

.materai-box {
    width: 90px;
    height: 50px;
    border: 1px dashed #94a3b8;
    margin: 1.5rem auto 1rem auto;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    color: #94a3b8;
    font-weight: 600;
}

[data-theme="dark"] .spjb-paper {
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
    .spjb-paper {
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
            <h1 class="mb-1">Surat Perjanjian Jual Beli (SPJB)</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/">Transaksi</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>">Detail</a></li>
                    <li class="breadcrumb-item active" aria-current="page">SPJB</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $data['id']; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Kembali
            </a>
            <button type="button" class="btn btn-primary shadow-sm" onclick="window.print()">
                <i class="fas fa-print me-1.5"></i>Cetak SPJB
            </button>
        </div>
    </div>
</div>

<!-- Printable SPJB Paper -->
<div class="spjb-paper">
    <?php echo renderOfficialDocumentHeader('SURAT PERJANJIAN JUAL BELI', $spjbNumber); ?>

    <p class="text-justify mb-3">
        Pada hari ini, tanggal <strong><?php echo formatDate($data['transaction_date'], 'd F Y'); ?></strong>, telah dibuat dan disepakati perjanjian jual beli kendaraan bermotor bekas oleh dan antara pihak-pihak sebagai berikut:
    </p>

    <!-- Pihak Pertama -->
    <div class="mb-3 ps-3 border-start border-3 border-primary">
        <div class="fw-bold text-dark mb-1">1. PIHAK PERTAMA (PENJUAL)</div>
        <table class="table-borderless spjb-spec-table">
            <tr>
                <td style="width: 140px;" class="text-muted">Nama Showroom</td>
                <td style="width: 10px;">:</td>
                <td><strong><?php echo htmlspecialchars($profile['name']); ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Alamat Showroom</td>
                <td>:</td>
                <td><?php echo htmlspecialchars($profile['address'] . ', ' . $profile['city']); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Perwakilan / Petugas</td>
                <td>:</td>
                <td><?php echo htmlspecialchars($data['user_name'] ?: 'Management Showroom'); ?></td>
            </tr>
        </table>
        <small class="text-muted fst-italic">Selanjutnya disebut sebagai <strong>PIHAK PERTAMA (PENJUAL)</strong>.</small>
    </div>

    <!-- Pihak Kedua -->
    <div class="mb-4 ps-3 border-start border-3 border-success">
        <div class="fw-bold text-dark mb-1">2. PIHAK KEDUA (PEMBELI)</div>
        <table class="table-borderless spjb-spec-table">
            <tr>
                <td style="width: 140px;" class="text-muted">Nama Lengkap</td>
                <td style="width: 10px;">:</td>
                <td><strong><?php echo htmlspecialchars($data['customer_name']); ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">No. KTP / NIK</td>
                <td>:</td>
                <td><?php echo htmlspecialchars($data['customer_nik'] ?: '-'); ?></td>
            </tr>
            <tr>
                <td class="text-muted">No. Telepon / WA</td>
                <td>:</td>
                <td><?php echo htmlspecialchars($data['customer_phone']); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Alamat Pembeli</td>
                <td>:</td>
                <td><?php echo htmlspecialchars($data['customer_address'] ?: '-'); ?></td>
            </tr>
        </table>
        <small class="text-muted fst-italic">Selanjutnya disebut sebagai <strong>PIHAK KEDUA (PEMBELI)</strong>.</small>
    </div>

    <p class="mb-3">
        Kedua belah pihak telah bersepakat untuk melakukan transaksi jual beli 1 (satu) unit kendaraan bermotor dengan syarat dan ketentuan sebagai berikut:
    </p>

    <!-- PASAL 1 -->
    <div class="spjb-article-title">Pasal 1: Objek Jual Beli Kendaraan</div>
    <p class="mb-2">PIHAK PERTAMA menjual kepada PIHAK KEDUA dan PIHAK KEDUA membeli dari PIHAK PERTAMA kendaraan bermotor dengan spesifikasi sebagai berikut:</p>
    <div class="bg-light p-2.5 rounded border mb-3">
        <div class="row g-2">
            <div class="col-6">
                <table class="table-borderless spjb-spec-table w-100">
                    <tr><td class="text-muted" style="width: 110px;">Merek / Model</td><td>: <strong><?php echo htmlspecialchars($data['brand_name'] . ' ' . $data['model_name']); ?></strong></td></tr>
                    <tr><td class="text-muted">Tahun Pembuatan</td><td>: <?php echo (int)$data['year']; ?></td></tr>
                    <tr><td class="text-muted">Nomor Polisi</td><td>: <span class="badge bg-dark text-white"><?php echo htmlspecialchars($data['license_plate']); ?></span></td></tr>
                    <tr><td class="text-muted">Warna Kendaraan</td><td>: <?php echo htmlspecialchars($data['color']); ?></td></tr>
                </table>
            </div>
            <div class="col-6">
                <table class="table-borderless spjb-spec-table w-100">
                    <tr><td class="text-muted" style="width: 110px;">Nomor Rangka</td><td>: <span class="font-monospace"><?php echo htmlspecialchars($data['chassis_number']); ?></span></td></tr>
                    <tr><td class="text-muted">Nomor Mesin</td><td>: <span class="font-monospace"><?php echo htmlspecialchars($data['engine_number']); ?></span></td></tr>
                    <tr><td class="text-muted">Transmisi</td><td>: <?php echo ucfirst($data['transmission']); ?></td></tr>
                    <tr><td class="text-muted">Kilometer</td><td>: <?php echo number_format($data['mileage']); ?> KM</td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- PASAL 2 -->
    <div class="spjb-article-title">Pasal 2: Harga & Ketentuan Pembayaran</div>
    <ol class="mb-3 ps-3">
        <li>Harga kendaraan yang disepakati adalah sebesar <strong><?php echo formatCurrency($data['total_amount']); ?></strong> (<em><?php echo terbilang($data['total_amount']); ?></em>).</li>
        <li>Metode pembayaran: <strong><?php echo strtoupper($data['payment_type'] ?: $data['payment_method']); ?></strong> <?php echo !empty($data['leasing_name']) ? '(' . htmlspecialchars($data['leasing_name']) . ')' : ''; ?>.</li>
        <li>Status Pembayaran saat perjanjian ini diterbitkan: <strong><?php echo strtoupper($data['payment_status']); ?></strong>.</li>
    </ol>

    <!-- PASAL 3 -->
    <div class="spjb-article-title">Pasal 3: Legalitas & Dokumen Kendaraan</div>
    <ol class="mb-3 ps-3">
        <li>PIHAK PERTAMA menjamin bahwa kendaraan bermotor tersebut adalah sah milik penjual, bebas dari sitaan, tidak sedang tersangkut perkara pidana/perdata, dan bebas dari sengketa hukum.</li>
        <li>Kelengkapan dokumen kendaraan berupa Surat Tanda Nomor Kendaraan (STNK) dan Buku Pemilik Kendaraan Bermotor (BPKB) beserta Faktur akan diserahkan sesuai kesepakatan pelunasan.</li>
    </ol>

    <!-- PASAL 4 -->
    <div class="spjb-article-title">Pasal 4: Kondisi Kendaraan & Serah Terima</div>
    <ol class="mb-3 ps-3">
        <li>PIHAK KEDUA telah memeriksa secara seksama kondisi fisik, kelistrikan, dan mesin kendaraan pada saat transaksi berlangsung (<em>As-Is Condition</em>).</li>
        <li>Segala risiko, biaya pemeliharaan, pajak, dan tanggung jawab hukum atas penggunaan kendaraan sejak tanggal serah terima sepenuhnya menjadi tanggung jawab PIHAK KEDUA.</li>
    </ol>

    <!-- Penutup & Tanda Tangan -->
    <p class="mt-4 mb-4">
        Demikian Surat Perjanjian Jual Beli ini dibuat dalam 2 (dua) rangkap bermaterai cukup dan memiliki kekuatan hukum yang sama bagi kedua belah pihak.
    </p>

    <div class="d-flex justify-content-between mt-5 pt-3">
        <div class="sig-box">
            <div class="fw-bold mb-1">PIHAK PERTAMA (PENJUAL)</div>
            <div class="text-muted small"><?php echo htmlspecialchars($profile['name']); ?></div>
            <div class="materai-box">MATERAI<br>10.000</div>
            <div class="border-bottom border-dark pb-1 fw-bold"><?php echo htmlspecialchars($data['user_name'] ?: 'Petugas Showroom'); ?></div>
            <div class="text-muted small mt-1">Authorized Representative</div>
        </div>

        <div class="sig-box">
            <div class="fw-bold mb-1">PIHAK KEDUA (PEMBELI)</div>
            <div class="text-muted small">Konsumen / Pembeli</div>
            <div class="materai-box">MATERAI<br>10.000</div>
            <div class="border-bottom border-dark pb-1 fw-bold"><?php echo htmlspecialchars($data['customer_name']); ?></div>
            <div class="text-muted small mt-1">Pembeli Kendaraan</div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
