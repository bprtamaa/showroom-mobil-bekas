<?php
// consignments/edit.php - Edit Data Mobil Titip Jual (Konsinyasi)
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
    showAlert('ID konsinyasi tidak valid.', 'danger');
    redirect('consignments/');
}

// Fetch existing consignment record
$stmt = $db->prepare("SELECT * FROM consignments WHERE id = ?");
$stmt->execute([$id]);
$consignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$consignment) {
    showAlert('Data mobil titip jual tidak ditemukan.', 'danger');
    redirect('consignments/');
}

// Fetch brands for dropdown
$stmt = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';

// Handle Submit Edit Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_consignment') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang formulir.';
    } else {
        // Owner Data
        $owner_name = sanitizeInput($_POST['owner_name'] ?? '');
        $owner_phone = sanitizeInput($_POST['owner_phone'] ?? '');
        $owner_id_card = sanitizeInput($_POST['owner_id_card'] ?? '');
        $owner_address = sanitizeInput($_POST['owner_address'] ?? '');
        $owner_bank_name = sanitizeInput($_POST['owner_bank_name'] ?? '');
        $owner_bank_account = sanitizeInput($_POST['owner_bank_account'] ?? '');
        $owner_bank_holder = sanitizeInput($_POST['owner_bank_holder'] ?? '');

        // Car Data
        $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
        $brand_name = sanitizeInput($_POST['brand_name'] ?? '');
        if ($brand_id && empty($brand_name)) {
            $stmtB = $db->prepare("SELECT name FROM brands WHERE id = ?");
            $stmtB->execute([$brand_id]);
            $brand_name = $stmtB->fetchColumn() ?: '';
        }
        $model_name = sanitizeInput($_POST['model_name'] ?? '');
        $year = (int)($_POST['year'] ?? date('Y'));
        $color = sanitizeInput($_POST['color'] ?? '');
        $license_plate = strtoupper(trim(sanitizeInput($_POST['license_plate'] ?? '')));
        $chassis_number = strtoupper(trim(sanitizeInput($_POST['chassis_number'] ?? '')));
        $engine_number = strtoupper(trim(sanitizeInput($_POST['engine_number'] ?? '')));
        $transmission = $_POST['transmission'] ?? 'automatic';
        $fuel_type = sanitizeInput($_POST['fuel_type'] ?? 'Bensin');
        $mileage = (int)($_POST['mileage'] ?? 0);

        // Financial & Period
        $net_price_to_owner = (float)($_POST['net_price_to_owner'] ?? 0);
        $target_selling_price = (float)($_POST['target_selling_price'] ?? 0);
        $commission_amount = (float)($_POST['commission_amount'] ?? ($target_selling_price - $net_price_to_owner));
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : $consignment['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : $consignment['end_date'];
        $status = $_POST['status'] ?? $consignment['status'];
        $agreement_notes = sanitizeInput($_POST['agreement_notes'] ?? '');

        // Validation
        if (empty($owner_name) || empty($owner_phone)) {
            $error = 'Nama pemilik dan nomor kontak/WhatsApp wajib diisi.';
        } elseif (empty($brand_name) || empty($model_name) || empty($license_plate)) {
            $error = 'Merek, model mobil, dan nomor polisi wajib diisi.';
        } elseif (empty($color)) {
            $error = 'Warna kendaraan wajib diisi.';
        } elseif ($net_price_to_owner <= 0 || $target_selling_price <= 0) {
            $error = 'Harga modal pemilik dan target harga jual harus lebih besar dari Rp 0.';
        } elseif ($target_selling_price < $net_price_to_owner) {
            $error = 'Target harga jual showroom tidak boleh lebih kecil dari harga modal pemilik.';
        } else {
            // Process new photo upload if any
            $existingPhotos = !empty($consignment['photos']) ? json_decode($consignment['photos'], true) : [];
            if (!empty($_FILES['photos']['name'][0])) {
                $uploadDir = '../uploads/consignments/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                        $fileExt = strtolower(pathinfo($_FILES['photos']['name'][$key], PATHINFO_EXTENSION));
                        if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $fileName = 'csg_' . time() . '_' . $key . '.' . $fileExt;
                            $targetFile = $uploadDir . $fileName;
                            if (move_uploaded_file($tmpName, $targetFile)) {
                                $existingPhotos[] = 'uploads/consignments/' . $fileName;
                            }
                        }
                    }
                }
            }

            $photosJson = !empty($existingPhotos) ? json_encode($existingPhotos) : null;

            try {
                $stmt = $db->prepare("
                    UPDATE consignments SET
                        owner_name = ?, owner_phone = ?, owner_id_card = ?, owner_address = ?,
                        owner_bank_name = ?, owner_bank_account = ?, owner_bank_holder = ?,
                        brand_id = ?, brand_name = ?, model_name = ?, year = ?, color = ?, license_plate = ?,
                        chassis_number = ?, engine_number = ?, transmission = ?, fuel_type = ?, mileage = ?,
                        net_price_to_owner = ?, target_selling_price = ?, commission_amount = ?,
                        start_date = ?, end_date = ?, status = ?,
                        agreement_notes = ?, photos = ?, updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $owner_name, $owner_phone, $owner_id_card, $owner_address,
                    $owner_bank_name, $owner_bank_account, $owner_bank_holder,
                    $brand_id, $brand_name, $model_name, $year, $color, $license_plate,
                    $chassis_number, $engine_number, $transmission, $fuel_type, $mileage,
                    $net_price_to_owner, $target_selling_price, $commission_amount,
                    $start_date, $end_date, $status,
                    $agreement_notes, $photosJson, $id
                ]);

                showAlert('Data mobil titip jual berhasil diperbarui.', 'success');
                redirect('consignments/view.php?id=' . $id);

            } catch (PDOException $e) {
                $error = 'Gagal memperbarui data: ' . $e->getMessage();
            }
        }
    }
}
?>

<style>
    /* Consistent Page Header */
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
    .btn-header-secondary {
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
    .btn-header-secondary:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }

    /* Form Section Cards */
    .form-section-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-bottom: 20px;
        overflow: hidden;
    }
    .form-section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .form-section-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }
    .form-section-icon.blue { background: #eff6ff; color: #2563eb; }
    .form-section-icon.indigo { background: #e0e7ff; color: #4f46e5; }
    .form-section-icon.emerald { background: #ecfdf5; color: #059669; }
    .form-section-icon.amber { background: #fef3c7; color: #d97706; }

    .form-section-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .form-section-subtitle {
        font-size: 0.75rem;
        color: #64748b;
        margin: 0;
    }
    .form-section-body {
        padding: 20px;
    }

    /* Form Controls */
    .form-label-custom {
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .form-label-custom .req {
        color: #ef4444;
    }
    .form-label-custom .label-hint {
        font-weight: 400;
        color: #94a3b8;
        font-size: 0.75rem;
        margin-left: 2px;
    }
    .form-control-custom,
    .form-select-custom {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #1e293b;
        background: #f8fafc;
        transition: all 0.15s ease;
    }
    .form-control-custom:focus,
    .form-select-custom:focus {
        border-color: #94a3b8;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }

    /* Custom Input Groups for Currency */
    .input-group-custom {
        display: flex !important;
        align-items: stretch !important;
        width: 100%;
    }
    .input-group-custom .input-addon-clean {
        display: inline-flex;
        align-items: center;
        padding: 0 14px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-right: none;
        border-radius: 10px 0 0 10px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #64748b;
        white-space: nowrap;
    }
    .input-group-custom .form-control-custom {
        border-radius: 0 10px 10px 0 !important;
        flex: 1 1 auto !important;
        width: 1% !important;
    }

    /* Sticky Summary Box (Right Column) */
    .consignment-summary-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        position: sticky;
        top: 20px;
    }
    .summary-header {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.04em;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    .summary-label {
        font-size: 0.8rem;
        color: #64748b;
    }
    .summary-val {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }
    .summary-divider {
        border-top: 1px dashed #e2e8f0;
        margin: 14px 0;
    }
    .commission-stat-num {
        font-size: 1.6rem;
        font-weight: 800;
        color: #16a34a;
        letter-spacing: -0.02em;
    }
    .margin-pill {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        background: #dcfce7;
        color: #15803d;
    }

    /* Action Buttons */
    .btn-submit-save {
        width: 100%;
        padding: 10px 16px;
        background: #2d3436;
        color: #ffffff;
        font-size: 0.86rem;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .btn-submit-save:hover {
        background: #1e272e;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .btn-submit-cancel {
        width: 100%;
        padding: 9px 16px;
        background: #ffffff;
        color: #64748b;
        font-size: 0.84rem;
        font-weight: 600;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        text-align: center;
        text-decoration: none;
        display: inline-block;
        transition: all 0.15s;
    }
    .btn-submit-cancel:hover {
        background: #f8fafc;
        color: #334155;
        border-color: #cbd5e1;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .form-section-card,
    [data-theme="dark"] .consignment-summary-box { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-section-header,
    [data-theme="dark"] .summary-divider { border-bottom-color: #334155; border-top-color: #334155; }
    [data-theme="dark"] .form-section-title,
    [data-theme="dark"] .summary-val { color: #f1f5f9; }
    [data-theme="dark"] .form-section-subtitle,
    [data-theme="dark"] .summary-label { color: #94a3b8; }
    [data-theme="dark"] .form-label-custom { color: #cbd5e1; }
    [data-theme="dark"] .form-control-custom,
    [data-theme="dark"] .form-select-custom { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .input-group-custom .input-addon-clean { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .form-control-custom:focus,
    [data-theme="dark"] .form-select-custom:focus { background: #162032; border-color: #475569; }
    [data-theme="dark"] .btn-submit-save { background: #3b82f6; }
    [data-theme="dark"] .btn-submit-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-submit-cancel { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-submit-cancel:hover { background: #1e293b; color: #f8fafc; }
    [data-theme="dark"] .bg-account-box { background-color: #0f172a !important; border-color: #334155 !important; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Edit Mobil Titip Jual (<?php echo htmlspecialchars($consignment['consignment_code']); ?>)</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>consignments/" class="text-primary text-decoration-underline">Titip Jual</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>consignments/view.php?id=<?php echo $consignment['id']; ?>" class="text-primary text-decoration-underline"><?php echo htmlspecialchars($consignment['consignment_code']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="<?php echo BASE_URL; ?>consignments/view.php?id=<?php echo $consignment['id']; ?>" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Detail
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;" role="alert">
    <div class="d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-circle"></i>
        <div><strong>Perhatian:</strong> <?php echo htmlspecialchars($error); ?></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="consignmentForm">
    <input type="hidden" name="action" value="update_consignment">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <div class="row g-4">
        <!-- Main Form Column (Left 8 Cols) -->
        <div class="col-12 col-lg-8">
            
            <!-- 1. Data Pemilik / Rekanan -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon blue">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">1. Data Pemilik / Rekanan</h5>
                        <p class="form-section-subtitle">Identitas pemilik asli dan rekening transfer pencairan</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label-custom">Nama Lengkap Pemilik <span class="req">*</span></label>
                            <input type="text" name="owner_name" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['owner_name'] ?? $consignment['owner_name']); ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label-custom">No. WhatsApp / HP <span class="req">*</span></label>
                            <input type="text" name="owner_phone" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['owner_phone'] ?? $consignment['owner_phone']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">No. KTP / NIK Pemilik</label>
                            <input type="text" name="owner_id_card" class="form-control-custom" value="<?php echo htmlspecialchars($_POST['owner_id_card'] ?? $consignment['owner_id_card']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Alamat Domisili</label>
                            <input type="text" name="owner_address" class="form-control-custom" value="<?php echo htmlspecialchars($_POST['owner_address'] ?? $consignment['owner_address']); ?>">
                        </div>
                        
                        <!-- Rekening Pencairan Box -->
                        <div class="col-12 mt-3">
                            <div class="p-3 rounded-3 border bg-account-box" style="background:#f8fafc; border-color:#e2e8f0;">
                                <div class="fw-bold mb-2 small" style="color:#475569;">
                                    <i class="fas fa-credit-card me-1 text-primary"></i> Rekening Pencairan Hasil Jual (Opsional)
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="small text-muted mb-1 d-block">Nama Bank</label>
                                        <input type="text" name="owner_bank_name" class="form-control-custom" placeholder="Nama Bank" value="<?php echo htmlspecialchars($_POST['owner_bank_name'] ?? $consignment['owner_bank_name']); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small text-muted mb-1 d-block">Nomor Rekening</label>
                                        <input type="text" name="owner_bank_account" class="form-control-custom" placeholder="No. Rekening" value="<?php echo htmlspecialchars($_POST['owner_bank_account'] ?? $consignment['owner_bank_account']); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small text-muted mb-1 d-block">Atas Nama Rekening</label>
                                        <input type="text" name="owner_bank_holder" class="form-control-custom" placeholder="Atas Nama Rekening" value="<?php echo htmlspecialchars($_POST['owner_bank_holder'] ?? $consignment['owner_bank_holder']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Spesifikasi Mobil Titipan -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon indigo">
                        <i class="fas fa-car-side"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">2. Spesifikasi Mobil Titipan</h5>
                        <p class="form-section-subtitle">Informasi lengkap spesifikasi dan legalitas unit titipan</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Merek Mobil <span class="req">*</span></label>
                            <select name="brand_id" id="brandSelect" class="form-select-custom" required onchange="syncBrandName()">
                                <option value="">-- Pilih Merek Mobil --</option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?php echo $b['id']; ?>" data-name="<?php echo htmlspecialchars($b['name']); ?>" <?php echo (((isset($_POST['brand_id']) ? $_POST['brand_id'] : $consignment['brand_id']) == $b['id']) ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($b['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="brand_name" id="brandNameInput" value="<?php echo htmlspecialchars($_POST['brand_name'] ?? $consignment['brand_name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Model & Tipe Mobil <span class="req">*</span></label>
                            <input type="text" name="model_name" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['model_name'] ?? $consignment['model_name']); ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Tahun Produksi <span class="req">*</span></label>
                            <input type="number" name="year" class="form-control-custom" min="1990" max="<?php echo date('Y') + 1; ?>" value="<?php echo htmlspecialchars($_POST['year'] ?? $consignment['year']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Warna Kendaraan <span class="req">*</span> <span class="label-hint">(Hanya huruf)</span></label>
                            <input type="text" name="color" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['color'] ?? $consignment['color']); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-custom">Nomor Polisi <span class="req">*</span> <span class="label-hint">(Plat)</span></label>
                            <input type="text" name="license_plate" id="license_plate" class="form-control-custom text-uppercase" required value="<?php echo htmlspecialchars($_POST['license_plate'] ?? $consignment['license_plate']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Transmisi <span class="req">*</span></label>
                            <select name="transmission" class="form-select-custom" required>
                                <option value="automatic" <?php echo ($consignment['transmission'] === 'automatic') ? 'selected' : ''; ?>>Otomatis (AT)</option>
                                <option value="manual" <?php echo ($consignment['transmission'] === 'manual') ? 'selected' : ''; ?>>Manual (MT)</option>
                                <option value="cvt" <?php echo ($consignment['transmission'] === 'cvt') ? 'selected' : ''; ?>>CVT</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Bahan Bakar <span class="req">*</span></label>
                            <select name="fuel_type" class="form-select-custom" required>
                                <option value="Bensin" <?php echo ($consignment['fuel_type'] === 'Bensin' || $consignment['fuel_type'] === 'gasoline') ? 'selected' : ''; ?>>Bensin (Gasoline)</option>
                                <option value="Diesel" <?php echo ($consignment['fuel_type'] === 'Diesel' || $consignment['fuel_type'] === 'diesel') ? 'selected' : ''; ?>>Diesel</option>
                                <option value="Hybrid" <?php echo ($consignment['fuel_type'] === 'Hybrid' || $consignment['fuel_type'] === 'hybrid') ? 'selected' : ''; ?>>Hybrid</option>
                                <option value="Electric" <?php echo ($consignment['fuel_type'] === 'Electric' || $consignment['fuel_type'] === 'electric') ? 'selected' : ''; ?>>Listrik (EV)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-custom">Odometer (Kilometer)</label>
                            <input type="number" name="mileage" class="form-control-custom" min="0" value="<?php echo htmlspecialchars($_POST['mileage'] ?? $consignment['mileage']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Nomor Rangka (VIN)</label>
                            <input type="text" name="chassis_number" id="chassis_number" class="form-control-custom text-uppercase" value="<?php echo htmlspecialchars($_POST['chassis_number'] ?? $consignment['chassis_number']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Nomor Mesin</label>
                            <input type="text" name="engine_number" id="engine_number" class="form-control-custom text-uppercase" value="<?php echo htmlspecialchars($_POST['engine_number'] ?? $consignment['engine_number']); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Kesepakatan Harga & Jangka Waktu Penitipan -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon emerald">
                        <i class="fas fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">3. Kesepakatan Harga, Status & Periode</h5>
                        <p class="form-section-subtitle">Harga bersih pemilik, target jual showroom, serta periode titip</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Harga Bersih Pemilik (Net to Owner) <span class="req">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-addon-clean">Rp</span>
                                <input type="number" id="netPriceInput" name="net_price_to_owner" class="form-control-custom" placeholder="0" min="1000000" step="500000" required value="<?php echo htmlspecialchars($_POST['net_price_to_owner'] ?? $consignment['net_price_to_owner']); ?>" oninput="calcCommission()">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Target Harga Jual Showroom <span class="req">*</span></label>
                            <div class="input-group-custom">
                                <span class="input-addon-clean">Rp</span>
                                <input type="number" id="targetPriceInput" name="target_selling_price" class="form-control-custom" placeholder="0" min="1000000" step="500000" required value="<?php echo htmlspecialchars($_POST['target_selling_price'] ?? $consignment['target_selling_price']); ?>" oninput="calcCommission()">
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label-custom">Status Transaksi <span class="req">*</span></label>
                            <select name="status" class="form-select-custom" required>
                                <option value="active" <?php echo $consignment['status'] === 'active' ? 'selected' : ''; ?>>Aktif Dititipkan</option>
                                <option value="sold" <?php echo $consignment['status'] === 'sold' ? 'selected' : ''; ?>>Laku Terjual</option>
                                <option value="returned" <?php echo $consignment['status'] === 'returned' ? 'selected' : ''; ?>>Dikembalikan ke Pemilik</option>
                                <option value="cancelled" <?php echo $consignment['status'] === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Tanggal Mulai Titip <span class="req">*</span></label>
                            <input type="date" name="start_date" id="startDateInput" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['start_date'] ?? $consignment['start_date']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Batas Waktu Titip (Jatuh Tempo) <span class="req">*</span></label>
                            <input type="date" name="end_date" id="endDateInput" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['end_date'] ?? $consignment['end_date']); ?>">
                        </div>
                    </div>
                    <input type="hidden" id="commissionAmountInput" name="commission_amount" value="<?php echo htmlspecialchars($_POST['commission_amount'] ?? $consignment['commission_amount']); ?>">
                </div>
            </div>

            <!-- 4. Foto & Catatan Kesepakatan -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon amber">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">4. Foto & Catatan Perjanjian</h5>
                        <p class="form-section-subtitle">Dokumentasi fisik dan klausul catatan khusus penitipan</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="mb-3">
                        <label class="form-label-custom">Tambah Foto Mobil Baru <span class="label-hint">(Opsional)</span></label>
                        <input type="file" name="photos[]" id="photosInput" class="form-control-custom" multiple accept="image/*">
                        <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Format: JPG, PNG, WEBP. Maksimal 5MB per file.</small>
                    </div>
                    <div class="mb-0">
                        <label class="form-label-custom">Catatan Tambahan & Klausul Khusus</label>
                        <textarea name="agreement_notes" class="form-control-custom" rows="3"><?php echo htmlspecialchars($_POST['agreement_notes'] ?? $consignment['agreement_notes']); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sticky Summary Sidebar (4 Cols) -->
        <div class="col-12 col-lg-4">
            <div class="consignment-summary-box">
                <div class="summary-header">
                    <i class="fas fa-calculator text-primary"></i> Ringkasan Finansial Titip Jual
                </div>

                <div class="summary-row">
                    <span class="summary-label">Target Jual Showroom:</span>
                    <span class="summary-val" id="dispTargetPrice">Rp 0</span>
                </div>

                <div class="summary-row">
                    <span class="summary-label">Harga Bersih Pemilik (Net):</span>
                    <span class="summary-val text-muted" id="dispNetPrice">Rp 0</span>
                </div>

                <div class="summary-divider"></div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="summary-label">Estimasi Komisi Showroom:</span>
                        <span class="margin-pill" id="dispMarginPct">0.0% Margin</span>
                    </div>
                    <div class="commission-stat-num" id="dispCommission">Rp 0</div>
                </div>

                <div class="text-muted small mb-4" style="line-height: 1.45; font-size: 0.76rem;">
                    Perubahan data penitipan mobil ini akan diperbarui di sistem inventaris dan katalog titip jual.
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" id="btnSubmitForm" class="btn-submit-save">
                        <i class="fas fa-save"></i> Simpan Perubahan Titip Jual
                    </button>
                    <a href="<?php echo BASE_URL; ?>consignments/view.php?id=<?php echo $consignment['id']; ?>" class="btn-submit-cancel">
                        Batal
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function syncBrandName() {
    const select = document.getElementById('brandSelect');
    const input = document.getElementById('brandNameInput');
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.dataset.name) {
        input.value = selectedOption.dataset.name;
    }
}

function formatRupiah(num) {
    return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
}

function calcCommission() {
    const netPrice = parseFloat(document.getElementById('netPriceInput').value) || 0;
    const targetPrice = parseFloat(document.getElementById('targetPriceInput').value) || 0;
    const commission = Math.max(0, targetPrice - netPrice);
    const marginPct = targetPrice > 0 ? ((commission / targetPrice) * 100).toFixed(1) : '0.0';

    document.getElementById('commissionAmountInput').value = commission;
    
    // Update sticky sidebar numbers
    document.getElementById('dispTargetPrice').textContent = formatRupiah(targetPrice);
    document.getElementById('dispNetPrice').textContent = formatRupiah(netPrice);
    document.getElementById('dispCommission').textContent = formatRupiah(commission);
    document.getElementById('dispMarginPct').textContent = marginPct + '% Margin';
}

document.addEventListener('DOMContentLoaded', function() {
    calcCommission();

    // Auto uppercase fields
    ['license_plate', 'chassis_number', 'engine_number'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
            });
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
