<?php
// bookings/create.php - Buat SPK & Catat Booking Fee
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

// Fetch available vehicles
$availableVehicles = [];
try {
    $stmt = $db->query("
        SELECT v.id, v.selling_price, v.license_plate, v.color, v.year,
               CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        WHERE v.status = 'available'
        ORDER BY b.name ASC, m.name ASC
    ");
    $availableVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch customers
$customers = [];
try {
    $stmt = $db->query("SELECT id, name, phone, id_card FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$error = '';
$selected_vehicle_id = (int)($_GET['vehicle_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $booking_fee = (float)str_replace(['.', ','], ['', '.'], $_POST['booking_fee'] ?? '0');
        $agreed_price = (float)str_replace(['.', ','], ['', '.'], $_POST['agreed_price'] ?? '0');
        $booking_date = $_POST['booking_date'] ?? date('Y-m-d');
        $expiry_date = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+3 days'));
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');
        $user_id = (int)$_SESSION['user_id'];

        if ($vehicle_id <= 0) {
            $error = 'Silakan pilih kendaraan yang akan dipesan.';
        } elseif ($customer_id <= 0) {
            $error = 'Silakan pilih pelanggan pemesan.';
        } elseif ($booking_fee <= 0) {
            $error = 'Nominal uang tanda jadi (booking fee) harus lebih dari 0.';
        } elseif ($agreed_price <= 0) {
            $error = 'Harga kesepakatan jual harus lebih dari 0.';
        } elseif ($booking_fee > $agreed_price) {
            $error = 'Booking fee tidak boleh melebihi harga kesepakatan.';
        } elseif (strtotime($expiry_date) < strtotime($booking_date)) {
            $error = 'Batas waktu reservasi tidak boleh sebelum tanggal booking.';
        } else {
            try {
                $db->beginTransaction();

                // Generate booking code: SPK/YYYYMM/XXXX
                $prefix = 'SPK/' . date('Ym') . '/';
                $codeStmt = $db->prepare("SELECT booking_code FROM vehicle_bookings WHERE booking_code LIKE ? ORDER BY id DESC LIMIT 1");
                $codeStmt->execute([$prefix . '%']);
                $lastCode = $codeStmt->fetchColumn();

                if ($lastCode) {
                    $lastSeq = (int)substr($lastCode, -4);
                    $newSeq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $newSeq = '0001';
                }
                $booking_code = $prefix . $newSeq;

                // Insert booking
                $ins = $db->prepare("
                    INSERT INTO vehicle_bookings (
                        booking_code, vehicle_id, customer_id, user_id, 
                        booking_fee, agreed_price, booking_date, expiry_date, 
                        payment_method, notes, status
                    ) VALUES (
                        ?, ?, ?, ?, 
                        ?, ?, ?, ?, 
                        ?, ?, 'active'
                    )
                ");
                $ins->execute([
                    $booking_code, $vehicle_id, $customer_id, $user_id,
                    $booking_fee, $agreed_price, $booking_date, $expiry_date,
                    $payment_method, $notes
                ]);
                $booking_id = $db->lastInsertId();

                // Update vehicle status to 'reserved'
                $upVeh = $db->prepare("UPDATE vehicles SET status = 'reserved' WHERE id = ?");
                $upVeh->execute([$vehicle_id]);

                $db->commit();

                showAlert("Surat Pemesanan Kendaraan ($booking_code) berhasil dibuat! Unit resmi di-hold.", 'success');
                redirect('bookings/view.php?id=' . $booking_id);

            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Gagal membuat SPK: ' . $e->getMessage();
            }
        }
    }
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
    .form-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
    }
    .form-control, .form-select {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 9px 14px;
        font-size: 0.85rem;
        color: #334155;
        background: #f8fafc;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .form-control:focus, .form-select:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }
    .input-group-text {
        border-radius: 10px 0 0 10px;
        border: 1px solid #e2e8f0;
        background: #f1f5f9;
        font-size: 0.85rem;
        color: #475569;
    }
    .input-group .form-control {
        border-radius: 0 10px 10px 0;
    }
    .btn-submit-form {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 24px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        box-shadow: 0 2px 6px rgba(45, 52, 54, 0.2);
    }
    .btn-submit-form:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(45, 52, 54, 0.3);
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .form-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .form-card-title { color: #f1f5f9; }
    [data-theme="dark"] .form-label { color: #cbd5e1; }
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .input-group-text { background: #1e293b; border-color: #334155; color: #cbd5e1; }
</style>

<div class="vehicles-page-header">
    <div>
        <h1>Buat SPK & Tanda Jadi</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">SPK & Booking Fee</a></li>
                <li class="breadcrumb-item active" aria-current="page">Buat SPK Baru</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i>
            <span>Kembali</span>
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger mb-4 border-0 shadow-sm" style="border-radius: 10px;">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <div class="row g-4">
        <!-- Kolom Kiri: Unit & Pemesan -->
        <div class="col-lg-6">
            <div class="form-card">
                <div class="form-card-header">
                    <h3 class="form-card-title">
                        <i class="fas fa-car text-primary"></i>
                        <span>Unit Kendaraan & Calon Pembeli</span>
                    </h3>
                </div>
                <div class="form-card-body">
                    <!-- Pilih Unit Mobil -->
                    <div class="mb-3">
                        <label class="form-label">Pilih Mobil Showroom <span class="text-danger">*</span></label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select" required onchange="updateVehiclePrice()">
                            <option value="">-- Pilih Unit Siap Jual --</option>
                            <?php foreach ($availableVehicles as $av): ?>
                                <option value="<?php echo $av['id']; ?>" 
                                    data-price="<?php echo (float)$av['selling_price']; ?>"
                                    <?php echo $selected_vehicle_id === (int)$av['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($av['vehicle_name'] . ' (' . $av['license_plate'] . ') - Rp ' . number_format($av['selling_price'], 0, ',', '.')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small" style="font-size: 0.72rem;">Unit yang dipilih akan otomatis berstatus <strong>reserved (di-hold)</strong>.</div>
                    </div>

                    <!-- Pilih Pelanggan -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Data Pelanggan (Pemesan) <span class="text-danger">*</span></label>
                            <a href="<?php echo BASE_URL; ?>customers/create.php" target="_blank" class="small text-decoration-none" style="font-size: 0.75rem;">
                                <i class="fas fa-plus me-1"></i>Pelanggan Baru
                            </a>
                        </div>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Pilih Data Pelanggan --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['name'] . ' (' . $c['phone'] . ($c['id_card'] ? ' - NIK: ' . $c['id_card'] : '') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tanggal Booking & Batas Reservasi -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Pemesanan (SPK)</label>
                            <input type="date" name="booking_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Batas Waktu Hold (Expiry) <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Finansial & Pembayaran -->
        <div class="col-lg-6">
            <div class="form-card">
                <div class="form-card-header">
                    <h3 class="form-card-title">
                        <i class="fas fa-wallet text-success"></i>
                        <span>Kesepakatan Harga & Tanda Jadi</span>
                    </h3>
                </div>
                <div class="form-card-body">
                    <!-- Kesepakatan Harga Jual -->
                    <div class="mb-3">
                        <label class="form-label">Kesepakatan Harga Jual (OTR) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" name="agreed_price" id="agreedPriceInput" class="form-control" required placeholder="0" onkeyup="formatCurrency(this)">
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Harga kesepakatan final antara showroom dan pembeli.</div>
                    </div>

                    <!-- Nominal Tanda Jadi -->
                    <div class="mb-3">
                        <label class="form-label">Nominal Uang Tanda Jadi (Booking Fee) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" name="booking_fee" class="form-control" required placeholder="0" value="5.000.000" onkeyup="formatCurrency(this)">
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Nominal uang muka tanda jadi yang diserahkan pembeli.</div>
                    </div>

                    <!-- Metode Pembayaran Booking Fee -->
                    <div class="mb-3">
                        <label class="form-label">Metode Pembayaran Booking Fee</label>
                        <select name="payment_method" class="form-select">
                            <option value="bank_transfer">Transfer Bank (BCA / Mandiri / BRI)</option>
                            <option value="cash">Tunai / Cash di Showroom</option>
                            <option value="debit_card">Kartu Debit / EDC</option>
                        </select>
                    </div>

                    <!-- Catatan Tambahan -->
                    <div class="mb-3">
                        <label class="form-label">Catatan Tambahan (Opsional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Contoh: Pembeli minta ganti oli sebelum serah terima unit."></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn-header-action" style="padding: 9px 18px;">Batal</a>
                        <button type="submit" class="btn-submit-form">
                            <i class="fas fa-save"></i>
                            <span>Terbitkan SPK & Hold Unit</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function formatCurrency(input) {
    let value = input.value.replace(/[^0-9]/g, '');
    if (value) {
        input.value = new Intl.NumberFormat('id-ID').format(parseInt(value));
    } else {
        input.value = '';
    }
}

function updateVehiclePrice() {
    const select = document.getElementById('vehicleSelect');
    const selectedOption = select.options[select.selectedIndex];
    const price = selectedOption.getAttribute('data-price');
    const priceInput = document.getElementById('agreedPriceInput');
    if (price && (!priceInput.value || priceInput.value === '0')) {
        priceInput.value = new Intl.NumberFormat('id-ID').format(parseInt(price));
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateVehiclePrice();
});
</script>

<?php include '../includes/footer.php'; ?>
