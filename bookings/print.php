<?php
// bookings/print.php - Cetak Dokumen Resmi Surat Pemesanan Kendaraan (SPK)
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die('ID SPK tidak valid.');
}

// Get Setting / Showroom info
$settings = [
    'company_name' => 'Aplikasi Manajemen Showroom',
    'company_address' => 'Jl. Otomotif Raya No. 88',
    'company_phone' => '0812-3456-7890',
    'company_email' => 'info@showroom.com',
    'company_logo' => ''
];
try {
    $sstmt = $db->query("SELECT company_name, company_address, company_phone, company_email, company_logo FROM settings WHERE id = 1");
    $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
    if ($srow) {
        $settings = array_merge($settings, array_filter($srow));
    }
} catch (Exception $e) {}

// Fetch Booking details
try {
    $stmt = $db->prepare("
        SELECT 
            b.*,
            v.license_plate, v.year as vehicle_year, v.color as vehicle_color,
            v.engine_number, v.chassis_number, v.transmission, v.fuel_type, v.mileage,
            CONCAT(br.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name,
            br.name as brand_name, m.name as model_name,
            c.name as customer_name, c.phone as customer_phone, c.nik as customer_nik, c.address as customer_address,
            u.full_name as staff_name
        FROM vehicle_bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands br ON m.brand_id = br.id
        JOIN customers c ON b.customer_id = c.id
        JOIN users u ON b.user_id = u.id
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        die('Data SPK tidak ditemukan.');
    }

    $sisaPelunasan = max(0, $booking['agreed_price'] - $booking['booking_fee']);

} catch (PDOException $e) {
    die('Kesalahan database: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPK - <?php echo htmlspecialchars($booking['booking_code']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }
        .spk-paper {
            max-width: 800px;
            margin: 20px auto;
            background: #ffffff;
            padding: 40px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
        }
        .spk-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .spk-title {
            font-size: 18px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #0f172a;
        }
        .section-header {
            background-color: #f8fafc;
            border-left: 3px solid #0f172a;
            padding: 6px 12px;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-top: 15px;
            margin-bottom: 10px;
        }
        .table-clean th {
            width: 30%;
            font-weight: 600;
            color: #475569;
            padding: 4px 0;
            border: none;
        }
        .table-clean td {
            font-weight: 500;
            color: #0f172a;
            padding: 4px 0;
            border: none;
        }
        .sig-box {
            height: 90px;
        }
        .sig-line {
            border-top: 1px solid #94a3b8;
            padding-top: 5px;
            font-weight: 600;
        }
        @media print {
            body { background: #ffffff !important; }
            .spk-paper {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary btn-sm px-4 me-2">
        Cetak Dokumen SPK
    </button>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-sm px-3">
        Tutup
    </button>
</div>

<div class="spk-paper">
    <!-- Header -->
    <div class="spk-header">
        <div class="row align-items-center">
            <div class="col-8">
                <h4 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($settings['company_name']); ?></h4>
                <div class="text-muted small mt-1">
                    <?php echo htmlspecialchars($settings['company_address']); ?><br>
                    Telp: <?php echo htmlspecialchars($settings['company_phone']); ?> | Email: <?php echo htmlspecialchars($settings['company_email']); ?>
                </div>
            </div>
            <div class="col-4 text-end">
                <div class="spk-title">SURAT PEMESANAN KENDARAAN (SPK)</div>
                <div class="fw-bold mt-1 text-primary"><?php echo htmlspecialchars($booking['booking_code']); ?></div>
                <div class="text-muted small">Tgl: <?php echo date('d/m/Y', strtotime($booking['booking_date'])); ?></div>
            </div>
        </div>
    </div>

    <!-- Data Pemesan -->
    <div class="section-header">I. IDENTITAS PEMESAN (KONSUMEN)</div>
    <table class="table table-clean mb-3">
        <tr>
            <th>Nama Lengkap</th>
            <td>: <?php echo htmlspecialchars($booking['customer_name']); ?></td>
        </tr>
        <tr>
            <th>Nomor KTP / NIK</th>
            <td>: <?php echo htmlspecialchars($booking['customer_nik'] ?: '-'); ?></td>
        </tr>
        <tr>
            <th>Nomor Telepon / HP</th>
            <td>: <?php echo htmlspecialchars($booking['customer_phone']); ?></td>
        </tr>
        <tr>
            <th>Alamat Tempat Tinggal</th>
            <td>: <?php echo htmlspecialchars($booking['customer_address'] ?: '-'); ?></td>
        </tr>
    </table>

    <!-- Data Kendaraan -->
    <div class="section-header">II. DATA KENDARAAN YANG DIPESAN</div>
    <table class="table table-clean mb-3">
        <tr>
            <th>Merk / Tipe Mobil</th>
            <td>: <?php echo htmlspecialchars($booking['vehicle_name']); ?></td>
        </tr>
        <tr>
            <th>Tahun Pembuatan</th>
            <td>: <?php echo htmlspecialchars($booking['vehicle_year']); ?></td>
        </tr>
        <tr>
            <th>Nomor Polisi (Plat)</th>
            <td>: <?php echo htmlspecialchars($booking['license_plate']); ?></td>
        </tr>
        <tr>
            <th>Warna Kendaraan</th>
            <td>: <?php echo htmlspecialchars($booking['vehicle_color']); ?></td>
        </tr>
        <tr>
            <th>Nomor Rangka (VIN)</th>
            <td>: <?php echo htmlspecialchars($booking['chassis_number'] ?: '-'); ?></td>
        </tr>
        <tr>
            <th>Nomor Mesin</th>
            <td>: <?php echo htmlspecialchars($booking['engine_number'] ?: '-'); ?></td>
        </tr>
        <tr>
            <th>Transmisi / Bahan Bakar</th>
            <td>: <?php echo ucfirst($booking['transmission']); ?> / <?php echo ucfirst($booking['fuel_type']); ?></td>
        </tr>
    </table>

    <!-- Skema Keuangan & Pembayaran -->
    <div class="section-header">III. KESEPAKATAN HARGA & PEMBAYARAN</div>
    <table class="table table-bordered mb-3">
        <thead class="table-light">
            <tr>
                <th>Uraian Pembayaran</th>
                <th class="text-end" style="width: 200px;">Jumlah (Rupiah)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Harga Jual Kendaraan Kesepakatan (OTR)</td>
                <td class="text-end fw-bold">Rp <?php echo number_format($booking['agreed_price'], 0, ',', '.'); ?></td>
            </tr>
            <tr>
                <td>Tanda Jadi yang Telah Diterima (Booking Fee) - <em>Metode: <?php echo strtoupper($booking['payment_method']); ?></em></td>
                <td class="text-end text-success fw-bold">Rp <?php echo number_format($booking['booking_fee'], 0, ',', '.'); ?></td>
            </tr>
            <tr class="table-light">
                <td class="fw-bold">Sisa Pembayaran / Pelunasan</td>
                <td class="text-end fw-bold text-primary fs-6">Rp <?php echo number_format($sisaPelunasan, 0, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="p-2 border rounded bg-light small mb-3">
        <strong>Batas Waktu Pelunasan:</strong> s/d tanggal <strong><?php echo date('d F Y', strtotime($booking['expiry_date'])); ?></strong>.
    </div>

    <!-- Ketentuan Khusus -->
    <div class="section-header">IV. SYARAT & KETENTUAN PEMESANAN</div>
    <ol class="small text-muted ps-3 mb-4" style="line-height: 1.5;">
        <li>Surat Pemesanan Kendaraan (SPK) ini berlaku sah sebagai tanda bukti pemesanan unit kendaraan dan penerimaan uang tanda jadi (booking fee).</li>
        <li>Unit kendaraan akan disimpan dan tidak ditawarkan kepada calon pembeli lain selama masa reservasi berlaku.</li>
        <li>Pelunasan sisa pembayaran wajib dilakukan sebelum atau selambat-lambatnya pada tanggal batas waktu pelunasan yang disepakati di atas.</li>
        <li>Apabila pemesan membatalkan pemesanan secara sepihak setelah SPK diterbitkan, uang tanda jadi (booking fee) tidak dapat dikembalikan / dianggap hangus sesuai kebijakan operasional showroom.</li>
        <?php if (!empty($booking['notes'])): ?>
        <li class="text-dark"><strong>Ketentuan Tambahan:</strong> <?php echo htmlspecialchars($booking['notes']); ?></li>
        <?php endif; ?>
    </ol>

    <!-- Tanda Tangan -->
    <div class="row text-center mt-4 pt-3">
        <div class="col-6">
            <div class="text-muted small mb-1">Pihak Pemesan (Konsumen)</div>
            <div class="sig-box"></div>
            <div class="sig-line mx-auto" style="width: 220px;">
                ( <?php echo htmlspecialchars($booking['customer_name']); ?> )
            </div>
        </div>
        <div class="col-6">
            <div class="text-muted small mb-1">Pihak Showroom (Sales / Marketing)</div>
            <div class="sig-box"></div>
            <div class="sig-line mx-auto" style="width: 220px;">
                ( <?php echo htmlspecialchars($booking['staff_name']); ?> )
            </div>
        </div>
    </div>
</div>

</body>
</html>
