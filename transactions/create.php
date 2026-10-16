<?php
// transactions/create.php - Pembuatan Transaksi Penjualan Baru
require_once '../config/config.php';

// AJAX Handler for Adding Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_add_customer') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Error'];
    
    try {
        if (empty($_SESSION['user_id'])) {
            throw new Exception("Sesi habis, silakan login kembali.");
        }

        if (!function_exists('sanitizeInput')) { function sanitizeInput($s){return htmlspecialchars(trim($s),ENT_QUOTES,'UTF-8');} }
        
        $name = sanitizeInput($_POST['name'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $id_card = sanitizeInput($_POST['id_card'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        
        if (empty($name) || empty($phone)) {
            throw new Exception("Nama dan Nomor Telepon wajib diisi.");
        }
        
        // Check duplicate phone
        $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            throw new Exception("Nomor telepon sudah terdaftar di database.");
        }
        
        $stmt = $db->prepare("INSERT INTO customers (name, phone, id_card, address, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        if ($stmt->execute([$name, $phone, $id_card, $address])) {
            $newId = $db->lastInsertId();
            
            $response = [
                'success' => true,
                'message' => 'Pelanggan berhasil ditambahkan.',
                'data' => [
                    'id' => $newId,
                    'text' => $name . ' - ' . $phone
                ]
            ];
        } else {
             throw new Exception("Gagal menyimpan data pelanggan ke database.");
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();
requireStaffOrAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_transaction') {
    if (!function_exists('sanitizeInput')) { function sanitizeInput($s){return htmlspecialchars(trim($s),ENT_QUOTES,'UTF-8');} }

    $formData = [
        'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
        'sale_price' => (int)str_replace(['Rp', '.', ' ', ','], ['', '', '', ''], $_POST['sale_price'] ?? '0'),
        'discount' => (int)str_replace(['Rp', '.', ' ', ','], ['', '', '', ''], $_POST['discount'] ?? '0'),
        'tax' => (int)str_replace(['Rp', '.', ' ', ','], ['', '', '', ''], $_POST['tax'] ?? '0'),
        'payment_method' => $_POST['payment_method'] ?? 'cash',
        'payment_status' => $_POST['payment_status'] ?? 'pending',
        'leasing_company_id' => isset($_POST['leasing_company_id']) ? (int)$_POST['leasing_company_id'] : 0,
        'transaction_date' => $_POST['transaction_date'] ?? date('Y-m-d'),
        'notes' => sanitizeInput($_POST['notes'] ?? '')
    ];

    $basePrice = max(0, $formData['sale_price'] - $formData['discount']);
    $formData['total_amount'] = $basePrice + $formData['tax'];

    if (empty($formData['vehicle_id'])) {
        $error = 'Unit mobil harus dipilih';
    } elseif (empty($formData['customer_id'])) {
        $error = 'Pelanggan harus dipilih';
    } elseif ($formData['sale_price'] <= 0) {
        $error = 'Harga jual harus lebih dari Rp 0';
    } elseif ($formData['total_amount'] <= 0) {
        $error = 'Total transaksi tidak valid';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang halaman.';
    } else {
        try {
            $db->beginTransaction();

            $from_booking_id = (int)($_POST['from_booking_id'] ?? 0);

            // Validate vehicle availability
            $stmt = $db->prepare('SELECT id, status, selling_price FROM vehicles WHERE id = ? FOR UPDATE');
            $stmt->execute([$formData['vehicle_id']]);
            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) throw new Exception('Unit mobil tidak ditemukan');
            if ($vehicle['status'] !== 'available') {
                if ($vehicle['status'] === 'reserved' && $from_booking_id > 0) {
                    // Valid reserved vehicle for this active booking
                } else {
                    throw new Exception('Unit mobil sudah tidak tersedia (terjual atau dipesan)');
                }
            }

            $formData['sale_price'] = $vehicle['selling_price'];
            $basePrice = max(0, $formData['sale_price'] - $formData['discount']);
            $formData['total_amount'] = $basePrice + $formData['tax'];

            if ($formData['total_amount'] < 0) throw new Exception('Total transaksi tidak valid (negatif)');

            $transactionCode = 'INV/' . date('Ymd') . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $check = $db->prepare('SELECT id FROM transactions WHERE transaction_code = ?');
            do {
                $check->execute([$transactionCode]);
                if ($check->fetch()) {
                    $transactionCode = 'INV/' . date('Ymd') . '/' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $exists = true;
                } else {
                    $exists = false;
                }
            } while ($exists);

            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            $stmt = $db->prepare("
                INSERT INTO transactions (
                    transaction_code, vehicle_id, customer_id, user_id,
                    sale_price, discount, tax, total_amount, payment_method,
                    payment_status, leasing_company_id, transaction_date, notes,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    NOW(), NOW()
                )
            ");

            $stmt->execute([
                $transactionCode,
                $formData['vehicle_id'],
                $formData['customer_id'],
                $userId,
                $formData['sale_price'],
                $formData['discount'],
                $formData['tax'],
                $formData['total_amount'],
                $formData['payment_method'],
                $formData['payment_status'],
                ($formData['payment_method'] === 'installment' && $formData['leasing_company_id'] > 0) ? $formData['leasing_company_id'] : null,
                $formData['transaction_date'],
                $formData['notes']
            ]);

            $newTrxId = $db->lastInsertId();

            // Update Vehicle status to sold
            $updVeh = $db->prepare("UPDATE vehicles SET status = 'sold', updated_at = NOW() WHERE id = ?");
            $updVeh->execute([$formData['vehicle_id']]);

            // If converted from booking, update booking status
            if ($from_booking_id > 0) {
                $updBooking = $db->prepare("UPDATE vehicle_bookings SET status = 'converted', transaction_id = ?, updated_at = NOW() WHERE id = ?");
                $updBooking->execute([$newTrxId, $from_booking_id]);
            }

            $db->commit();

            showAlert('Transaksi penjualan ' . $transactionCode . ' berhasil dibuat!', 'success');
            redirect('transactions/view.php?id=' . $newTrxId);

        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Gagal menyimpan transaksi: ' . $e->getMessage();
        }
    }
}

// Check if converted from booking
$from_booking = (int)($_GET['from_booking'] ?? 0);
$bookingData = null;
if ($from_booking > 0) {
    try {
        $bStmt = $db->prepare("SELECT * FROM vehicle_bookings WHERE id = ?");
        $bStmt->execute([$from_booking]);
        $bookingData = $bStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Fetch available vehicles, customers, and active leasings
try {
    if ($bookingData) {
        $stmt = $db->prepare("
            SELECT v.id, v.selling_price, v.license_plate, v.chassis_number, v.color, v.mileage, v.year as vehicle_year,
                   m.name AS model_name, b.name AS brand_name
            FROM vehicles v
            LEFT JOIN models m ON v.model_id = m.id
            LEFT JOIN brands b ON m.brand_id = b.id
            WHERE v.status = 'available' OR v.id = ?
            ORDER BY b.name ASC, m.name ASC
        ");
        $stmt->execute([$bookingData['vehicle_id']]);
    } else {
        $stmt = $db->query("
            SELECT v.id, v.selling_price, v.license_plate, v.chassis_number, v.color, v.mileage, v.year as vehicle_year,
                   m.name AS model_name, b.name AS brand_name
            FROM vehicles v
            LEFT JOIN models m ON v.model_id = m.id
            LEFT JOIN brands b ON m.brand_id = b.id
            WHERE v.status = 'available'
            ORDER BY b.name ASC, m.name ASC
        ");
    }
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query('SELECT id, name, phone FROM customers ORDER BY name ASC');
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, name FROM leasing WHERE status = 'active' ORDER BY name ASC");
    $leasing_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Gagal memuat data: ' . $e->getMessage();
}

$formData = [
    'vehicle_id' => $bookingData ? $bookingData['vehicle_id'] : ($_GET['vehicle_id'] ?? ''),
    'customer_id' => $bookingData ? $bookingData['customer_id'] : ($_GET['customer_id'] ?? ''),
    'sale_price' => $bookingData ? $bookingData['agreed_price'] : '',
    'discount' => 0,
    'tax' => 0,
    'payment_method' => 'cash',
    'payment_status' => 'paid',
    'transaction_date' => date('Y-m-d'),
    'leasing_company_id' => 0,
    'notes' => $bookingData ? ('Pelunasan dari SPK ' . $bookingData['booking_code'] . ' (Tanda jadi Rp ' . number_format($bookingData['booking_fee'], 0, ',', '.') . ' telah diterima sebelumnya).') : ''
];
?>

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

<style>
    /* ===== Header Styling ===== */
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

    /* ===== Form Cards ===== */
    .form-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        position: relative;
    }
    .form-card.card-has-dropdown {
        overflow: visible !important;
        z-index: 30;
    }
    .form-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
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
        padding: 20px;
    }
    .form-card.card-has-dropdown .form-card-body {
        overflow: visible !important;
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
        color: #1e293b;
        background-color: #f8fafc;
        transition: all 0.15s ease-in-out;
    }
    .form-control:focus, .form-select:focus {
        background-color: #ffffff;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }

    /* ===== Premium Custom Choices.js Styling ===== */
    .choices {
        margin-bottom: 0;
        font-size: 0.85rem;
        position: relative;
    }
    .choices__inner {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px !important;
        min-height: 42px;
        padding: 6px 12px;
        font-size: 0.85rem;
        color: #1e293b;
        display: flex;
        align-items: center;
        transition: all 0.15s ease-in-out;
    }
    .is-focused .choices__inner,
    .is-open .choices__inner {
        background-color: #ffffff;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
    }
    .choices__list--dropdown {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        background-color: #ffffff;
        margin-top: 4px;
        padding: 6px;
        z-index: 1050;
    }
    .choices__list--dropdown .choices__item--selectable {
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.84rem;
        color: #334155;
        transition: all 0.1s ease;
    }
    .choices__list--dropdown .choices__item--selectable.is-highlighted {
        background-color: #f1f5f9;
        color: #0f172a;
    }
    .choices__input {
        background-color: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px !important;
        padding: 8px 12px !important;
        font-size: 0.82rem !important;
        margin-bottom: 6px !important;
    }
    .choices[data-type*="select-one"]::after {
        border-color: #64748b transparent transparent transparent;
        right: 14px;
        margin-top: -2.5px;
    }
    .choices[data-type*="select-one"].is-open::after {
        border-color: transparent transparent #64748b transparent;
        margin-top: -7.5px;
    }
    .choices__placeholder {
        color: #94a3b8;
        opacity: 1;
    }

    /* Add Customer Link Button */
    .btn-add-customer-link {
        font-size: 0.78rem;
        font-weight: 600;
        color: #2563eb;
        background: transparent;
        border: none;
        padding: 0;
        cursor: pointer;
        text-decoration: none;
        transition: color 0.15s;
    }
    .btn-add-customer-link:hover {
        color: #1d4ed8;
        text-decoration: underline;
    }

    /* ===== Sticky Summary Box ===== */
    .sticky-summary-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        position: sticky;
        top: 20px;
    }
    .summary-title {
        font-size: 0.82rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.04em;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }
    .summary-label-txt {
        font-size: 0.83rem;
        color: #64748b;
    }
    .summary-val-txt {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }
    .summary-divider {
        border-top: 1px dashed #e2e8f0;
        margin: 16px 0;
    }
    .summary-grand-total {
        font-size: 1.6rem;
        font-weight: 800;
        color: #16a34a;
        letter-spacing: -0.02em;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .form-card,
    [data-theme="dark"] .sticky-summary-box { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-title,
    [data-theme="dark"] .summary-val-txt { color: #f1f5f9; }
    [data-theme="dark"] .form-label { color: #94a3b8; }
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .form-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .form-control:focus,
    [data-theme="dark"] .form-select:focus { background: #162032; border-color: #475569; }
    [data-theme="dark"] .form-card-header,
    [data-theme="dark"] .summary-divider { border-bottom-color: #334155; border-top-color: #334155; }
    [data-theme="dark"] .summary-grand-total { color: #4ade80; }
    [data-theme="dark"] .choices__inner { background-color: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .is-focused .choices__inner,
    [data-theme="dark"] .is-open .choices__inner { background-color: #162032; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .choices__list--dropdown { background-color: #1e293b; border-color: #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    [data-theme="dark"] .choices__list--dropdown .choices__item--selectable { color: #cbd5e1; }
    [data-theme="dark"] .choices__list--dropdown .choices__item--selectable.is-highlighted { background-color: #334155; color: #f8fafc; }
    [data-theme="dark"] .choices__input { background-color: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
    [data-theme="dark"] .choices[data-type*="select-one"]::after { border-color: #94a3b8 transparent transparent transparent; }
    [data-theme="dark"] .choices[data-type*="select-one"].is-open::after { border-color: transparent transparent #94a3b8 transparent; }
    [data-theme="dark"] .btn-add-customer-link { color: #60a5fa; }
    [data-theme="dark"] .modal-content { background: #1e293b; color: #f1f5f9; }
    [data-theme="dark"] .modal-header { border-bottom-color: #334155; }
    [data-theme="dark"] .modal-footer { border-top-color: #334155; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Transaksi Penjualan Baru</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-primary text-decoration-underline">Transaksi Penjualan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Buat Baru</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="transactionForm">
    <input type="hidden" name="action" value="save_transaction">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="from_booking_id" value="<?php echo (int)$from_booking; ?>">

    <?php if ($bookingData): ?>
    <div class="alert alert-info py-2.5 px-3 mb-4 rounded-3 d-flex align-items-center justify-content-between">
        <div>
            <i class="fas fa-file-contract me-1.5 text-primary"></i>
            Melanjutkan pelunasan dari <strong><?php echo htmlspecialchars($bookingData['booking_code']); ?></strong>. Tanda jadi sebesar <strong><?php echo formatCurrency($bookingData['booking_fee']); ?></strong> telah dibayarkan pemesan.
        </div>
        <span class="badge bg-primary text-white">SPK Terverifikasi</span>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left Column: Form Fields -->
        <div class="col-12 col-lg-8">
            <!-- 1. Consumer & Vehicle Selection -->
            <div class="form-card card card-has-dropdown">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-shopping-cart text-primary"></i> 1. Informasi Pelanggan & Unit Mobil
                    </h5>
                </div>
                <div class="form-card-body">
                    <!-- Customer Select Field -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="customer_id" class="form-label mb-0">Pelanggan Pembeli <span class="text-danger">*</span></label>
                            <button type="button" class="btn-add-customer-link" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                <i class="fas fa-plus-circle me-1"></i>Tambah Pelanggan Baru
                            </button>
                        </div>
                        <select class="form-select" id="customer_id" name="customer_id" required>
                            <option value="">-- Cari atau Pilih Pelanggan --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($formData['customer_id'] == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name'] . ' - ' . $c['phone']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Vehicle Select Field -->
                    <div class="mb-4">
                        <label for="vehicle_id" class="form-label">Unit Mobil Showroom yang Dijual <span class="text-danger">*</span></label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                            <option value="">-- Pilih Unit Mobil Tersedia --</option>
                            <?php foreach ($vehicles as $v): 
                                $price = $v['selling_price'];
                                $vName = $v['brand_name'] . ' ' . $v['model_name'];
                                if ($v['vehicle_year']) $vName .= ' (' . $v['vehicle_year'] . ')';
                                if ($v['license_plate']) $vName .= ' - ' . $v['license_plate'];
                            ?>
                            <option value="<?php echo $v['id']; ?>" data-price="<?php echo $price; ?>" <?php echo ($formData['vehicle_id'] == $v['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($vName) . ' - Rp ' . number_format($price, 0, ',', '.'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" id="sale_price" name="sale_price" value="">

                    <div class="mb-0">
                        <label for="transaction_date" class="form-label">Tanggal Transaksi <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?php echo htmlspecialchars($formData['transaction_date']); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
            </div>

            <!-- 2. Price, Discount & Additional Fees -->
            <div class="form-card card">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-tags text-primary"></i> 2. Harga, Potongan Diskon & Biaya
                    </h5>
                </div>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="discount" class="form-label">Potongan Diskon (Rp)</label>
                            <input type="text" class="form-control currency-input" id="discount" name="discount" value="Rp 0" placeholder="Rp 0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="tax" class="form-label">Biaya Administrasi / Pajak (Rp)</label>
                            <input type="text" class="form-control currency-input" id="tax" name="tax" value="Rp 0" placeholder="Rp 0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Payment Method & Status -->
            <div class="form-card card">
                <div class="form-card-header">
                    <h5 class="form-card-title">
                        <i class="fas fa-credit-card text-primary"></i> 3. Metode & Status Pembayaran
                    </h5>
                </div>
                <div class="form-card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Metode Pembayaran</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="cash">Tunai / Cash Langsung</option>
                                <option value="bank_transfer">Transfer Rekening Bank</option>
                                <option value="installment">Kredit / Pembiayaan Leasing</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="leasing_select" style="display:none;">
                            <label for="leasing_company_id" class="form-label">Mitra Leasing <span class="text-danger">*</span></label>
                            <select class="form-select" id="leasing_company_id" name="leasing_company_id">
                                <option value="">-- Pilih Perusahaan Leasing --</option>
                                <?php foreach ($leasing_companies as $lc): ?>
                                <option value="<?php echo $lc['id']; ?>"><?php echo htmlspecialchars($lc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="payment_status" class="form-label">Status Pembayaran</label>
                            <select class="form-select" id="payment_status" name="payment_status">
                                <option value="paid">Lunas</option>
                                <option value="installment">Cicilan</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="notes" class="form-label">Catatan Tambahan Transaksi</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3" placeholder="Catatan kesepakatan, nomor kuitansi manual, dll..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Sticky Summary Box -->
        <div class="col-12 col-lg-4">
            <div class="sticky-summary-box">
                <div class="summary-title">
                    <i class="fas fa-receipt text-primary"></i> Ringkasan Total Penjualan
                </div>

                <div class="summary-row">
                    <span class="summary-label-txt">Harga Unit:</span>
                    <span class="summary-val-txt" id="summary_price">Rp 0</span>
                </div>

                <div class="summary-row">
                    <span class="summary-label-txt">Potongan Diskon:</span>
                    <span class="summary-val-txt text-danger" id="summary_discount">Rp 0</span>
                </div>

                <div class="summary-row">
                    <span class="summary-label-txt">Biaya Administrasi:</span>
                    <span class="summary-val-txt" id="summary_tax">Rp 0</span>
                </div>

                <div class="summary-divider"></div>

                <div class="mb-4">
                    <span class="summary-label-txt d-block mb-1">Grand Total Transaksi:</span>
                    <div class="summary-grand-total" id="summary_total">Rp 0</div>
                </div>

                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary" id="btnSubmit" style="background:#2d3436;border:none;border-radius:10px;padding:12px 16px;font-weight:600;box-shadow: 0 2px 6px rgba(45, 52, 54, 0.2);">
                        <i class="fas fa-save me-1"></i> Simpan Transaksi Penjualan
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;padding:9px 16px;font-weight:600;">
                        Batal
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">
                    <i class="fas fa-user-plus me-2 text-primary"></i>Tambah Pelanggan Baru
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="customerAlert" class="alert d-none"></div>
                <form id="addCustomerForm">
                    <input type="hidden" name="action" value="ajax_add_customer">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="Nama konsumen">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="phone" required placeholder="08123456789">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Nomor KTP (NIK)</label>
                        <input type="text" class="form-control" name="id_card" maxlength="16" placeholder="16 digit NIK">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Alamat Domisili</label>
                        <textarea class="form-control" name="address" rows="3" placeholder="Alamat lengkap..."></textarea>
                    </div>
                    <div class="modal-footer border-top px-0 pb-0 pt-3">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                        <button type="submit" class="btn btn-primary" id="btnSaveCustomer" style="border-radius:8px;background:#2d3436;border:none;">
                            <i class="fas fa-save me-1"></i> Simpan Pelanggan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Choices.js JS -->
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function parseCurrency(str) {
        if (!str) return 0;
        const digits = String(str).replace(/\D+/g, '');
        return digits ? parseInt(digits, 10) : 0;
    }

    function formatIDR(num) {
        const n = Number(num) || 0;
        return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n).replace(/\./g, '.');
    }

    const vehicleSelect = document.getElementById('vehicle_id');
    const salePriceInput = document.getElementById('sale_price');
    const discountEl = document.getElementById('discount');
    const taxEl = document.getElementById('tax');
    const summaryPrice = document.getElementById('summary_price');
    const summaryDiscount = document.getElementById('summary_discount');
    const summaryTax = document.getElementById('summary_tax');
    const summaryTotal = document.getElementById('summary_total');

    function updateTotals() {
        const price = parseCurrency(salePriceInput.value);
        const disc = parseCurrency(discountEl.value);
        const tax = parseCurrency(taxEl.value);

        const base = Math.max(0, price - disc);
        const total = base + tax;

        if (summaryPrice) summaryPrice.textContent = formatIDR(price);
        if (summaryDiscount) summaryDiscount.textContent = disc > 0 ? ('- ' + formatIDR(disc)) : 'Rp 0';
        if (summaryTax) summaryTax.textContent = tax > 0 ? ('+ ' + formatIDR(tax)) : 'Rp 0';
        if (summaryTotal) summaryTotal.textContent = formatIDR(total);
    }

    // Initialize Choices.js for Customer and Vehicle
    let customerChoices = null;
    let vehicleChoices = null;

    if (document.getElementById('customer_id')) {
        customerChoices = new Choices('#customer_id', {
            searchEnabled: true,
            searchPlaceholderValue: 'Ketik nama atau nomor telepon...',
            noResultsText: 'Pelanggan tidak ditemukan',
            itemSelectText: 'Pilih',
            shouldSort: false,
            removeItemButton: false
        });
    }

    if (vehicleSelect) {
        vehicleChoices = new Choices('#vehicle_id', {
            searchEnabled: true,
            searchPlaceholderValue: 'Cari merek, model, atau plat nomor...',
            noResultsText: 'Unit mobil tidak ditemukan',
            itemSelectText: 'Pilih',
            shouldSort: false,
            removeItemButton: false
        });

        vehicleSelect.addEventListener('change', function(e) {
            const selectedVal = e.detail ? e.detail.value : this.value;
            // Find option data-price
            let foundPrice = 0;
            const opt = Array.from(this.options).find(o => o.value == selectedVal);
            if (opt && opt.getAttribute('data-price')) {
                foundPrice = opt.getAttribute('data-price');
            }

            if (foundPrice) {
                salePriceInput.value = formatIDR(foundPrice);
                updateTotals();
            } else {
                salePriceInput.value = '';
                updateTotals();
            }
        });
    }

    [discountEl, taxEl].forEach(el => {
        if (!el) return;
        el.addEventListener('input', updateTotals);
        el.addEventListener('blur', function() {
            let v = parseCurrency(this.value);
            this.value = formatIDR(v);
        });
    });

    // Payment Method & Leasing Logic
    const paymentMethodEl = document.getElementById('payment_method');
    const leasingSelectWrap = document.getElementById('leasing_select');
    const leasingSelect = document.getElementById('leasing_company_id');
    const paymentStatusEl = document.getElementById('payment_status');

    function toggleLeasingSelect() {
        if (!paymentMethodEl || !leasingSelectWrap || !paymentStatusEl) return;
        const method = paymentMethodEl.value;
        if (method === 'installment') {
            leasingSelectWrap.style.display = 'block';
            if (leasingSelect) leasingSelect.required = true;
            paymentStatusEl.value = 'installment';
        } else {
            leasingSelectWrap.style.display = 'none';
            if (leasingSelect) {
                leasingSelect.required = false;
                leasingSelect.value = '';
            }
            if (method === 'bank_transfer') {
                paymentStatusEl.value = 'pending';
            } else {
                paymentStatusEl.value = 'paid';
            }
        }
    }

    if (paymentMethodEl) {
        paymentMethodEl.addEventListener('change', toggleLeasingSelect);
    }
    toggleLeasingSelect();

    // Trigger initial calculation if pre-selected
    if (vehicleSelect && vehicleSelect.value) {
        vehicleSelect.dispatchEvent(new Event('change'));
    }

    // Submit Validation
    document.getElementById('btnSubmit').addEventListener('click', function() {
        const customerVal = document.getElementById('customer_id').value;
        const vehicleVal = document.getElementById('vehicle_id').value;

        if (!customerVal) {
            alert('Silakan pilih pelanggan pembeli terlebih dahulu.');
            return;
        }
        if (!vehicleVal) {
            alert('Silakan pilih unit mobil terlebih dahulu.');
            return;
        }
        const ps = document.getElementById('payment_status');
        if (ps) ps.removeAttribute('disabled');
        document.getElementById('transactionForm').submit();
    });

    // AJAX Add Customer
    const addCustomerForm = document.getElementById('addCustomerForm');
    const customerAlert = document.getElementById('customerAlert');
    const btnSaveCustomer = document.getElementById('btnSaveCustomer');

    if (addCustomerForm) {
        addCustomerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            btnSaveCustomer.disabled = true;
            btnSaveCustomer.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
            customerAlert.classList.add('d-none');

            const formData = new FormData(this);
            const mainToken = document.querySelector('input[name="csrf_token"]');
            if (mainToken) formData.append('csrf_token', mainToken.value);

            fetch('create.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const modalEl = document.getElementById('addCustomerModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    modal.hide();
                    addCustomerForm.reset();

                    if (customerChoices) {
                        customerChoices.setChoices([{
                            value: data.data.id.toString(),
                            label: data.data.text,
                            selected: true
                        }], 'value', 'label', false);
                    }
                    alert('Pelanggan berhasil ditambahkan!');
                } else {
                    customerAlert.textContent = data.message;
                    customerAlert.className = 'alert alert-danger';
                    customerAlert.classList.remove('d-none');
                }
            })
            .catch(err => {
                customerAlert.textContent = 'Terjadi kesalahan sistem saat menyimpan.';
                customerAlert.className = 'alert alert-danger';
                customerAlert.classList.remove('d-none');
            })
            .finally(() => {
                btnSaveCustomer.disabled = false;
                btnSaveCustomer.innerHTML = '<i class="fas fa-save me-1"></i> Simpan Pelanggan';
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
