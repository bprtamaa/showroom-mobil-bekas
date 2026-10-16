<?php
// transactions/print.php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    die('ID transaksi tidak valid');
}

// Load system settings
$systemLogo = null;
$companyAddress = null;
try {
    $sstmt = $db->query("SELECT company_logo, company_address FROM settings WHERE id = 1");
    $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
    if ($srow) {
        if (!empty($srow['company_logo'])) {
            $systemLogo = UPLOADS_URL . 'settings/' . ltrim($srow['company_logo'], '/');
        }
        if (!empty($srow['company_address'])) {
            $companyAddress = $srow['company_address'];
        }
    }
} catch (PDOException $__ignore) {}

if (!$systemLogo) {
    $systemLogo = UPLOADS_URL . 'settings/logo_1764154626.png';
}

try {
    $stmt = $db->prepare("
        SELECT t.*,
               c.name as customer_name, c.phone as customer_phone, 
               c.address as customer_address, c.id_card,
               u.full_name as user_name
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        die('Transaksi tidak ditemukan');
    }

    // Fetch Items (Single Vehicle)
    $items = [];
    $lStmt = $db->prepare("
        SELECT t.sale_price as sold_price,
               v.*,
               CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name,
               ct.name as car_type_name
        FROM transactions t
        JOIN vehicles v ON t.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        WHERE t.id = ?
    ");
    $lStmt->execute([$id]);
    $legacyItem = $lStmt->fetch(PDO::FETCH_ASSOC);
    if ($legacyItem) {
        $items[] = $legacyItem;
    }
    
    // Fetch leasing company information if payment status is installment
    $leasingInfo = null;
    if (isset($transaction['leasing_company_id']) && $transaction['leasing_company_id']) {
        try {
            $leasingStmt = $db->prepare("SELECT * FROM leasing WHERE id = ? LIMIT 1");
            $leasingStmt->execute([(int)$transaction['leasing_company_id']]);
            $leasingInfo = $leasingStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $__ignore) {
            $leasingInfo = null;
        }
    }

} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

// Determine payment status (fix legacy data)
$status = $transaction['payment_status'] ?? 'pending';
if (empty($status)) $status = 'pending';
if ($status == 'pending' && isset($transaction['payment_method']) && $transaction['payment_method'] == 'installment') {
    $status = 'installment';
}

$statusLabel = [
    'paid' => 'LUNAS',
    'installment' => 'CICILAN',
    'pending' => 'PENDING'
];

$paymentMethodLabel = [
    'cash' => 'Tunai',
    'bank_transfer' => 'Transfer Bank',
    'credit' => 'Kartu Kredit',
    'installment' => 'Kredit'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $transaction['transaction_code']; ?> - <?php echo APP_NAME; ?></title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #111827; font-size: 11px; }
        .invoice-container { max-width: 760px; margin: 0 auto; background: #fff; }
        .header { text-align: center; margin-bottom: 20px; }
        .header-logo { max-height: 60px; margin-bottom: 8px; }
        .header h1 { font-size: 18px; font-weight: 700; margin: 0; }
        .header h2 { font-size: 14px; margin: 2px 0 6px; letter-spacing: .5px; color: #374151; }
        .code-status { margin-top: 4px; font-size: 12px; }
        
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
        .section-title { font-size: 12px; font-weight: 700; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px; }
        
        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 4px 0; vertical-align: top; }
        .kv td:first-child { width: 35%; color:#6b7280; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th, .items-table td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; }
        .items-table th { background: #f9fafb; font-weight: 600; font-size: 11px; }
        .items-table td { font-size: 11px; }
        .items-table .price-col { text-align: right; white-space: nowrap; }
        
        .summary-box { width: 50%; margin-left: auto; }
        .summary-table { width: 100%; border-collapse: collapse; }
        .summary-table td { padding: 5px 0; }
        .summary-table td:last-child { text-align: right; font-weight: 600; }
        .total-row td { border-top: 1px solid #000; padding-top: 8px; font-weight: 700; font-size: 13px; }
        
        .signature { display:flex; justify-content:space-between; margin-top: 40px; }
        .sig-box { width: 40%; text-align: center; }
        .sig-line { border-bottom: 1px solid #000; height: 60px; margin-bottom: 5px; }
        
        .footer { margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 10px; color:#6b7280; text-align: center; }
        .print-button { position: fixed; top: 16px; right: 16px; padding: 8px 14px; background:#2563eb; color:#fff; border:none; border-radius:5px; cursor:pointer; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">🖨️ Cetak</button>
    
    <div class="invoice-container">
        <div class="header">
            <?php if ($systemLogo): ?>
                <img src="<?php echo htmlspecialchars($systemLogo); ?>" alt="Logo" class="header-logo">
            <?php endif; ?>
            <h1><?php echo ($status === 'installment') ? 'TAGIHAN KE PIHAK LEASING' : 'INVOICE PEMBELIAN'; ?></h1>
            <?php if (!empty($companyAddress)): ?>
                <div style="font-size: 11px; color: #6b7280; margin-top: 5px;"><?php echo nl2br(htmlspecialchars($companyAddress)); ?></div>
            <?php endif; ?>
            <div class="code-status">
                NO: <strong><?php echo htmlspecialchars($transaction['transaction_code']); ?></strong>
            </div>
        </div>
        
        <div class="grid">
            <div class="box">
                <?php 
                // Show leasing info for installment method (regardless of status), customer info otherwise
                if ($transaction['payment_method'] === 'installment' && $leasingInfo): 
                ?>
                    <div class="section-title">INFO PIHAK LEASING</div>
                    <table class="kv">
                        <tr><td>Nama Perusahaan</td><td>: <?php echo htmlspecialchars($leasingInfo['name']); ?></td></tr>
                        <tr><td>Nama</td><td>: <?php echo htmlspecialchars($leasingInfo['contact_person'] ?? '-'); ?></td></tr>
                        <tr><td>No. Telepon</td><td>: <?php echo htmlspecialchars($leasingInfo['contact_phone'] ?? '-'); ?></td></tr>
                    </table>
                <?php else: ?>
                    <div class="section-title">INFO PELANGGAN</div>
                    <table class="kv">
                        <tr><td>Nama</td><td>: <?php echo htmlspecialchars($transaction['customer_name']); ?></td></tr>
                        <tr><td>Telepon</td><td>: <?php echo htmlspecialchars($transaction['customer_phone']); ?></td></tr>
                        <tr><td>Alamat</td><td>: <?php echo htmlspecialchars($transaction['customer_address'] ?? '-'); ?></td></tr>
                    </table>
                <?php endif; ?>
            </div>
            <div class="box">
                <div class="section-title">INFO TRANSAKSI</div>
                <table class="kv">
                    <tr><td>Tanggal</td><td>: <?php echo formatDate($transaction['transaction_date'], 'd M Y'); ?></td></tr>
                    <tr><td>Pembayaran</td><td>: <?php echo $paymentMethodLabel[$transaction['payment_method']] ?? '-'; ?></td></tr>
                    <tr><td>Status</td><td>: <?php 
                        echo $statusLabel[$status] ?? strtoupper($status); 
                    ?></td></tr>
                    <tr><td>Petugas</td><td>: <?php echo htmlspecialchars($transaction['user_name']); ?></td></tr>
                </table>
            </div>
        </div>

        <div class="section-title" style="margin-bottom:10px;">RINCIAN UNIT KENDARAAN</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th>Deskripsi Kendaraan</th>
                    <th>Warna</th>
                    <th>No. Rangka / Mesin</th>
                    <th class="price-col">Harga</th>
                </tr>
            </thead>
            <tbody>
                <?php $totalBase = 0; foreach ($items as $i => $item): $totalBase += $item['sold_price']; ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($item['vehicle_name']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($item['license_plate'] ?? '-'); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($item['color']); ?></td>
                    <td>
                        <small>No. Rangka: <?php echo htmlspecialchars($item['chassis_number']); ?></small><br>
                        <small>No. Mesin: <?php echo htmlspecialchars($item['engine_number']); ?></small>
                    </td>
                    <td class="price-col"><?php echo formatCurrency($item['sold_price']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary-box">
            <table class="summary-table">
                <tr><td>Total Harga Unit</td><td><?php echo formatCurrency($totalBase); ?></td></tr>
                <?php if ($transaction['discount'] > 0): ?>
                <tr><td>Diskon</td><td>- <?php echo formatCurrency($transaction['discount']); ?></td></tr>
                <?php endif; ?>
                <?php if ($transaction['tax'] > 0): ?>
                <tr><td>Pajak / Biaya Lain</td><td>+ <?php echo formatCurrency($transaction['tax']); ?></td></tr>
                <?php endif; ?>
                <tr class="total-row"><td>TOTAL TAGIHAN</td><td><?php echo formatCurrency($transaction['total_amount']); ?></td></tr>
            </table>
        </div>
        
        <?php if ($transaction['notes']): ?>
        <div style="margin-top: 20px; border: 1px dashed #ccc; padding: 10px; background: #fafafa;">
            <strong>Catatan:</strong><br>
            <?php echo nl2br(htmlspecialchars($transaction['notes'])); ?>
        </div>
        <?php endif; ?>

        <div class="signature">
            <div class="sig-box">
                <div class="sig-line"></div>
                <?php if ($transaction['payment_method'] === 'installment' && $leasingInfo): ?>
                    <div><strong><?php echo htmlspecialchars($leasingInfo['name']); ?></strong><br>Pihak Leasing</div>
                <?php else: ?>
                    <div><strong><?php echo htmlspecialchars($transaction['customer_name']); ?></strong><br>Pelanggan</div>
                <?php endif; ?>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div><strong><?php echo htmlspecialchars($transaction['user_name']); ?></strong><br>Pihak Showroom</div>
            </div>
        </div>

        <div class="footer">
            Dokumen ini dicetak otomatis pada <?php echo formatDate('now', 'd M Y H:i'); ?> WIB.
        </div>
    </div>
</body>
</html>
