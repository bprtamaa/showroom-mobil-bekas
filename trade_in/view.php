<?php
// trade_in/view.php - Detail Transaksi Tukar Tambah & Eksekusi Konversi Stok
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/document_helpers.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID transaksi tukar tambah tidak valid.', 'danger');
    redirect('trade_in/');
}

// Handle 1-Click Complete & Stock Conversion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_trade_in') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang halaman.';
    } else {
        try {
            $db->beginTransaction();

            // 1. Fetch trade-in record
            $fetchStmt = $db->prepare("SELECT * FROM trade_in_transactions WHERE id = ? FOR UPDATE");
            $fetchStmt->execute([$id]);
            $tt = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$tt || $tt['status'] === 'completed') {
                throw new Exception('Transaksi tidak dapat diproses (sudah selesai atau tidak ditemukan).');
            }

            // 2. Find or create Brand & Model for Old Car
            $modelId = !empty($tt['trade_in_model_id']) ? (int)$tt['trade_in_model_id'] : null;

            if (!$modelId) {
                $brandName = trim($tt['trade_in_brand_name']);
                $bStmt = $db->prepare("SELECT id FROM brands WHERE name LIKE ? LIMIT 1");
                $bStmt->execute([$brandName]);
                $brandId = $bStmt->fetchColumn();
                if (!$brandId) {
                    $insBrand = $db->prepare("INSERT INTO brands (name, country, status) VALUES (?, 'Indonesia', 'active')");
                    $insBrand->execute([$brandName]);
                    $brandId = $db->lastInsertId();
                }

                $modelName = trim($tt['trade_in_model_name']);
                $mStmt = $db->prepare("SELECT id FROM models WHERE brand_id = ? AND name LIKE ? LIMIT 1");
                $mStmt->execute([$brandId, $modelName]);
                $modelId = $mStmt->fetchColumn();
                if (!$modelId) {
                    $typeStmt = $db->query("SELECT id FROM car_types WHERE status = 'active' ORDER BY id ASC LIMIT 1");
                    $defaultCarTypeId = (int)($typeStmt->fetchColumn() ?: 1);

                    $insModel = $db->prepare("INSERT INTO models (brand_id, car_type_id, name, status, created_at, updated_at) VALUES (?, ?, ?, 'active', NOW(), NOW())");
                    $insModel->execute([$brandId, $defaultCarTypeId, $modelName]);
                    $modelId = $db->lastInsertId();
                }
            }

            // 3. Insert Old Car into `vehicles` table as new showroom stock
            $insVeh = $db->prepare("
                INSERT INTO vehicles (
                    model_id, year, chassis_number, engine_number, license_plate,
                    color, mileage, fuel_type, transmission, purchase_price, selling_price,
                    purchase_date, condition_rating, notes, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    CURDATE(), 'good', ?, 'available', NOW(), NOW()
                )
            ");
            
            $estSellingPrice = $tt['appraised_value'] * 1.15; // Estimated 15% margin
            $notesOldCar = "Unit masuk dari transaksi tukar tambah (" . $tt['trade_in_code'] . "). " . $tt['trade_in_condition'];

            $insVeh->execute([
                $modelId,
                $tt['trade_in_year'],
                $tt['trade_in_chassis_number'] ?: '-',
                $tt['trade_in_engine_number'] ?: '-',
                $tt['trade_in_license_plate'],
                $tt['trade_in_color'] ?: 'Standar',
                $tt['trade_in_mileage'] ?: 0,
                $tt['trade_in_fuel_type'] ?: 'gasoline',
                $tt['trade_in_transmission'] ?: 'automatic',
                $tt['appraised_value'],
                $estSellingPrice,
                $notesOldCar
            ]);
            $newStockVehicleId = $db->lastInsertId();

            // 4. Mark Desired Showroom Vehicle as Sold
            $updVeh = $db->prepare("UPDATE vehicles SET status = 'sold', updated_at = NOW() WHERE id = ?");
            $updVeh->execute([$tt['desired_vehicle_id']]);

            // 5. Generate Sales Transaction for Showroom Vehicle
            $trxCode = 'INV/' . date('Ymd') . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $insTrx = $db->prepare("
                INSERT INTO transactions (
                    transaction_code, vehicle_id, customer_id, user_id,
                    sale_price, total_amount, payment_method, payment_status,
                    transaction_date, notes, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, 'paid',
                    CURDATE(), ?, NOW(), NOW()
                )
            ");
            $userId = $_SESSION['user_id'] ?? null;
            $payMethod = ($tt['payment_method'] === 'leasing') ? 'installment' : 'cash';
            $trxNotes = "Transaksi Tukar Tambah (" . $tt['trade_in_code'] . "). Mobil lama " . $tt['trade_in_brand_name'] . " " . $tt['trade_in_model_name'] . " senilai " . formatCurrency($tt['appraised_value']);

            $insTrx->execute([
                $trxCode,
                $tt['desired_vehicle_id'],
                $tt['customer_id'],
                $userId,
                $tt['desired_vehicle_price'],
                $tt['desired_vehicle_price'],
                $payMethod,
                $trxNotes
            ]);
            $newTrxId = $db->lastInsertId();

            // 6. Update Trade-In record status
            $updTT = $db->prepare("
                UPDATE trade_in_transactions
                SET status = 'completed',
                    converted_vehicle_id = ?,
                    converted_transaction_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updTT->execute([$newStockVehicleId, $newTrxId, $id]);

            $db->commit();

            showAlert('Transaksi tukar tambah berhasil diselesaikan! Unit lama konsumen otomatis masuk ke daftar stok mobil showroom (# ' . $newStockVehicleId . ') dan unit showroom ditandai terjual.', 'success');
            redirect('trade_in/view.php?id=' . $id);

        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Gagal memproses tukar tambah: ' . $e->getMessage();
        }
    }
}

// Fetch Trade-In Data
try {
    $stmt = $db->prepare("
        SELECT 
            tt.*,
            c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.id_card as customer_nik,
            v.license_plate as desired_license_plate, v.chassis_number as desired_chassis, v.year as desired_year,
            v.color as desired_color, v.mileage as desired_mileage,
            vb.name as desired_brand_name, vm.name as desired_model_name,
            conv_v.license_plate as converted_plate, conv_b.name as converted_brand, conv_m.name as converted_model,
            trx.transaction_code
        FROM trade_in_transactions tt
        JOIN customers c ON tt.customer_id = c.id
        JOIN vehicles v ON tt.desired_vehicle_id = v.id
        JOIN models vm ON v.model_id = vm.id
        JOIN brands vb ON vm.brand_id = vb.id
        LEFT JOIN vehicles conv_v ON tt.converted_vehicle_id = conv_v.id
        LEFT JOIN models conv_m ON conv_v.model_id = conv_m.id
        LEFT JOIN brands conv_b ON conv_m.brand_id = conv_b.id
        LEFT JOIN transactions trx ON tt.converted_transaction_id = trx.id
        WHERE tt.id = ?
    ");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        showAlert('Data transaksi tukar tambah tidak ditemukan.', 'danger');
        redirect('trade_in/');
    }

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('trade_in/');
}
?>

<style>
    /* Consistent View Header */
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

    /* Financial Calculation Banner */
    .calc-summary-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .calc-item {
        display: flex;
        flex-direction: column;
    }
    .calc-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }
    .calc-value {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e293b;
        letter-spacing: -0.02em;
    }
    .calc-operator {
        font-size: 1.5rem;
        font-weight: 700;
        color: #94a3b8;
        display: flex;
        align-items: center;
        justify-content: center;
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
    .status-in_progress, .status-appraisal { background: #fef3c7; color: #92400e; }
    .status-scheduled, .status-approved, .status-code { background: #e0f2fe; color: #075985; }
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
    [data-theme="dark"] .calc-summary-card,
    [data-theme="dark"] .view-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .calc-value,
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .spec-val { color: #f1f5f9; }
    [data-theme="dark"] .view-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1>Detail Tukar Tambah</h1>
            <span class="status-badge status-code fw-bold">#<?php echo htmlspecialchars($data['trade_in_code']); ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-primary text-decoration-underline">Tukar Tambah</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($data['trade_in_code']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <?php if ($data['status'] !== 'completed'): ?>
        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menyelesaikan transaksi ini? Unit lama konsumen akan otomatis masuk ke stok showroom dan unit showroom akan ditandai terjual.');" class="d-inline">
            <input type="hidden" name="action" value="complete_trade_in">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <button type="submit" class="btn-header-action btn-header-success">
                <i class="fas fa-check-double"></i> Selesaikan Transaksi & Masukkan ke Stok
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($data['status'] === 'completed'): ?>
<div class="alert alert-success d-flex align-items-center mb-4 p-3 border-0 shadow-sm" style="border-radius: 12px;">
    <i class="fas fa-check-circle fa-2x me-3"></i>
    <div>
        <h6 class="fw-bold mb-0">Transaksi Tukar Tambah Telah Selesai</h6>
        <small>Mobil lama konsumen telah terdaftar ke stok showroom dengan Plat: <strong><?php echo htmlspecialchars($data['converted_plate'] ?: $data['trade_in_license_plate']); ?></strong> dan penjualan tercatat pada No. Transaksi: <strong><?php echo htmlspecialchars($data['transaction_code'] ?: '-'); ?></strong>.</small>
    </div>
</div>
<?php endif; ?>

<!-- Financial Difference Calculation Banner -->
<div class="calc-summary-card">
    <div class="row align-items-center g-3">
        <div class="col-12 col-md-3">
            <div class="calc-item">
                <div class="calc-label">Harga Mobil Showroom</div>
                <div class="calc-value"><?php echo formatCurrency($data['desired_vehicle_price']); ?></div>
            </div>
        </div>
        <div class="col-12 col-md-1 text-center">
            <div class="calc-operator">-</div>
        </div>
        <div class="col-12 col-md-3">
            <div class="calc-item">
                <div class="calc-label">Nilai Taksiran Mobil Lama</div>
                <div class="calc-value text-success"><?php echo formatCurrency($data['appraised_value']); ?></div>
            </div>
        </div>
        <div class="col-12 col-md-1 text-center">
            <div class="calc-operator">=</div>
        </div>
        <div class="col-12 col-md-4">
            <div class="calc-item">
                <div class="calc-label">Selisih yang Dibayar Konsumen</div>
                <div class="calc-value text-primary"><?php echo formatCurrency($data['price_difference']); ?></div>
                <div class="mt-1">
                    <span class="status-badge status-code">Metode Pembayaran: <?php echo strtoupper($data['payment_method']); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Comparison Grid -->
<div class="row g-4 mb-4">
    <!-- Left: Mobil Lama Konsumen -->
    <div class="col-12 col-md-6">
        <div class="view-card card h-100">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-car text-success"></i> Mobil Lama Konsumen (Trade-In)
                </h5>
                <span class="status-badge status-completed">Taksiran: <?php echo formatCurrency($data['appraised_value']); ?></span>
            </div>
            <div class="view-card-body">
                <h4 class="fw-bold text-dark fs-5 mb-3"><?php echo htmlspecialchars($data['trade_in_brand_name'] . ' ' . $data['trade_in_model_name'] . ' (' . $data['trade_in_year'] . ')'); ?></h4>
                <div class="spec-grid">
                    <div class="spec-item">
                        <span class="spec-label">Nomor Polisi / Plat</span>
                        <span class="spec-val"><span class="plate-badge"><?php echo htmlspecialchars($data['trade_in_license_plate']); ?></span></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Transmisi</span>
                        <span class="spec-val"><?php echo ucfirst($data['trade_in_transmission']); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Warna Kendaraan</span>
                        <span class="spec-val"><?php echo htmlspecialchars($data['trade_in_color'] ?: '-'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Odometer (KM)</span>
                        <span class="spec-val"><?php echo number_format((int)$data['trade_in_mileage']); ?> KM</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Nomor Rangka</span>
                        <span class="spec-val"><?php echo htmlspecialchars($data['trade_in_chassis_number'] ?: '-'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Nomor Mesin</span>
                        <span class="spec-val"><?php echo htmlspecialchars($data['trade_in_engine_number'] ?: '-'); ?></span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <span class="spec-label d-block mb-1">Catatan Kondisi Fisik & Mesin:</span>
                    <div class="p-3 bg-light rounded text-dark small" style="line-height: 1.5;">
                        <?php echo !empty($data['trade_in_condition']) ? nl2br(htmlspecialchars($data['trade_in_condition'])) : 'Tidak ada catatan kondisi khusus.'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Mobil Showroom yang Dibeli -->
    <div class="col-12 col-md-6">
        <div class="view-card card h-100">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-store text-primary"></i> Mobil Showroom yang Dipilih
                </h5>
                <span class="status-badge status-code"><?php echo formatCurrency($data['desired_vehicle_price']); ?></span>
            </div>
            <div class="view-card-body">
                <h4 class="fw-bold text-dark fs-5 mb-3"><?php echo htmlspecialchars($data['desired_brand_name'] . ' ' . $data['desired_model_name'] . ' (' . $data['desired_year'] . ')'); ?></h4>
                <div class="spec-grid">
                    <div class="spec-item">
                        <span class="spec-label">Nomor Polisi / Plat</span>
                        <span class="spec-val"><span class="plate-badge"><?php echo htmlspecialchars($data['desired_license_plate']); ?></span></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Warna Kendaraan</span>
                        <span class="spec-val"><?php echo htmlspecialchars($data['desired_color'] ?: '-'); ?></span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Odometer (KM)</span>
                        <span class="spec-val"><?php echo number_format((int)$data['desired_mileage']); ?> KM</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Nomor Rangka</span>
                        <span class="spec-val"><?php echo htmlspecialchars($data['desired_chassis'] ?: '-'); ?></span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <span class="spec-label d-block mb-2">Data Konsumen:</span>
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-user text-primary"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($data['customer_name']); ?></div>
                            <div class="text-muted small"><?php echo htmlspecialchars($data['customer_phone']); ?> • NIK: <?php echo htmlspecialchars($data['customer_nik'] ?: '-'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
