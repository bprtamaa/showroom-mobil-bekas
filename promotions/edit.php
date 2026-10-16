<?php
// promotions/edit.php - Edit Promo & Diskon
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
    showAlert('ID promo tidak valid.', 'danger');
    redirect('promotions/');
}

$stmt = $db->prepare("SELECT * FROM promotions WHERE id = ?");
$stmt->execute([$id]);
$promo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$promo) {
    showAlert('Data promo tidak ditemukan.', 'danger');
    redirect('promotions/');
}

$selectedIds = !empty($promo['target_ids']) ? json_decode($promo['target_ids'], true) : [];

// Fetch available vehicles
$stmtV = $db->query("
    SELECT v.id, v.year, v.license_plate, v.selling_price, m.name as model_name, b.name as brand_name 
    FROM vehicles v
    LEFT JOIN models m ON v.model_id = m.id
    LEFT JOIN brands b ON m.brand_id = b.id
    WHERE v.status = 'available'
    ORDER BY b.name ASC, m.name ASC
");
$availableVehicles = $stmtV->fetchAll(PDO::FETCH_ASSOC);

// Fetch brands
$stmtB = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
$brands = $stmtB->fetchAll(PDO::FETCH_ASSOC);

// Handle Submit Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_promotion') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang formulir.';
    } else {
        $title = sanitizeInput($_POST['title'] ?? '');
        $promo_code = strtoupper(trim(sanitizeInput($_POST['promo_code'] ?? '')));
        $promo_badge = sanitizeInput($_POST['promo_badge'] ?? 'DISKON SPESIAL');
        $discount_type = $_POST['discount_type'] ?? 'fixed';
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $start_date = $_POST['start_date'] ?? $promo['start_date'];
        $end_date = $_POST['end_date'] ?? $promo['end_date'];
        $target_scope = $_POST['target_scope'] ?? 'all_vehicles';
        $status = $_POST['status'] ?? 'active';
        $banner_text = sanitizeInput($_POST['banner_text'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');

        $target_ids = null;
        if ($target_scope === 'selected_vehicles' && !empty($_POST['vehicle_ids'])) {
            $target_ids = json_encode(array_map('intval', $_POST['vehicle_ids']));
        } elseif ($target_scope === 'selected_brands' && !empty($_POST['brand_ids'])) {
            $target_ids = json_encode(array_map('intval', $_POST['brand_ids']));
        }

        if (empty($title) || empty($promo_badge)) {
            $error = 'Judul promo dan label badge wajib diisi.';
        } elseif ($discount_amount <= 0) {
            $error = 'Nominal atau persentase potongan diskon harus lebih besar dari 0.';
        } elseif ($discount_type === 'percentage' && $discount_amount > 100) {
            $error = 'Persentase diskon tidak boleh melebihi 100%.';
        } elseif ($end_date < $start_date) {
            $error = 'Tanggal berakhir promo tidak boleh lebih awal dari tanggal mulai.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE promotions SET
                        title = ?, promo_code = ?, promo_badge = ?, discount_type = ?, discount_amount = ?,
                        start_date = ?, end_date = ?, target_scope = ?, target_ids = ?, status = ?,
                        banner_text = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $promo_code, $promo_badge, $discount_type, $discount_amount,
                    $start_date, $end_date, $target_scope, $target_ids, $status,
                    $banner_text, $notes, $id
                ]);

                showAlert('Data promo berhasil diperbarui.', 'success');
                redirect('promotions/');

            } catch (PDOException $e) {
                $error = 'Gagal memperbarui promo: ' . $e->getMessage();
            }
        }
    }
}
?>

<style>
    /* ===== Promotion Edit Page Styles ===== */
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

    .form-section-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        margin-bottom: 24px;
        overflow: hidden;
    }
    .form-section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
    }
    .form-section-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .form-section-icon.red { background: #fee2e2; color: #ef4444; }
    .form-section-icon.blue { background: #eff6ff; color: #2563eb; }
    .form-section-icon.emerald { background: #ecfdf5; color: #059669; }
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

    .form-label-custom {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .form-label-custom .req { color: #ef4444; }
    .form-control-custom,
    .form-select-custom {
        width: 100%;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        transition: all 0.15s ease;
    }
    .form-control-custom:focus,
    .form-select-custom:focus {
        border-color: #ef4444;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
        outline: none;
    }

    .badge-preview-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .btn-submit-promo {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 24px;
        background: #ef4444;
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-submit-promo:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .form-section-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-section-header { background: #1a2332; border-bottom-color: #334155; }
    [data-theme="dark"] .form-section-title { color: #f1f5f9; }
    [data-theme="dark"] .form-section-subtitle { color: #94a3b8; }
    [data-theme="dark"] .form-label-custom { color: #cbd5e1; }
    [data-theme="dark"] .form-control-custom,
    [data-theme="dark"] .form-select-custom { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .badge-preview-box { background: #0f172a; border-color: #334155; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Edit Promo: <?php echo htmlspecialchars($promo['title']); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>promotions/" class="text-primary text-decoration-underline">Promo & Diskon</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="<?php echo BASE_URL; ?>promotions/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="action" value="update_promotion">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <div class="row g-4">
        <div class="col-lg-7">
            <!-- 1. Informasi Promo -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon red">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">1. Informasi & Potongan Diskon</h5>
                        <p class="form-section-subtitle">Judul promo, label badge, dan besaran potongan</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label-custom">Judul / Nama Promo <span class="req">*</span></label>
                            <input type="text" name="title" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['title'] ?? $promo['title']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Kode Promo (Opsional)</label>
                            <input type="text" name="promo_code" class="form-control-custom" style="text-transform: uppercase;" value="<?php echo htmlspecialchars($_POST['promo_code'] ?? $promo['promo_code']); ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Label Badge Promo <span class="req">*</span></label>
                            <input type="text" id="badgeInput" name="promo_badge" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['promo_badge'] ?? $promo['promo_badge']); ?>" oninput="updateBadgePreview()">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Preview Tampilan Badge</label>
                            <div class="badge-preview-box">
                                <span class="badge bg-danger text-white px-3 py-2 fw-bold" id="badgePreview" style="border-radius: 6px; font-size: 0.75rem; letter-spacing: 0.05em;">
                                    🔥 <?php echo htmlspecialchars($promo['promo_badge']); ?>
                                </span>
                                <small class="text-muted" style="font-size:0.75rem;">(Muncul di Katalog)</small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Tipe Potongan <span class="req">*</span></label>
                            <select name="discount_type" id="discountTypeSelect" class="form-select-custom" onchange="toggleDiscountPrefix()">
                                <option value="fixed" <?php echo ($promo['discount_type'] === 'fixed') ? 'selected' : ''; ?>>Nominal Tetap (Rupiah)</option>
                                <option value="percentage" <?php echo ($promo['discount_type'] === 'percentage') ? 'selected' : ''; ?>>Persentase (%)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Besaran Potongan <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted" id="discountPrefix" style="border-radius: 10px 0 0 10px; font-size:0.85rem; font-weight:600;">
                                    <?php echo ($promo['discount_type'] === 'percentage') ? '%' : 'Rp'; ?>
                                </span>
                                <input type="number" name="discount_amount" class="form-control-custom" style="border-radius: 0 10px 10px 0;" min="1" step="1000" required value="<?php echo htmlspecialchars($_POST['discount_amount'] ?? $promo['discount_amount']); ?>">
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Teks Banner Pengumuman (Opsional)</label>
                            <input type="text" name="banner_text" class="form-control-custom" value="<?php echo htmlspecialchars($_POST['banner_text'] ?? $promo['banner_text']); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Jangka Waktu Promo -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon emerald">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">2. Jangka Waktu Tayang Promo</h5>
                        <p class="form-section-subtitle">Batas mulai dan berakhirnya penawaran promo</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label-custom">Tanggal Mulai <span class="req">*</span></label>
                            <input type="date" name="start_date" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['start_date'] ?? $promo['start_date']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Tanggal Berakhir <span class="req">*</span></label>
                            <input type="date" name="end_date" class="form-control-custom" required value="<?php echo htmlspecialchars($_POST['end_date'] ?? $promo['end_date']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Status Promo</label>
                            <select name="status" class="form-select-custom">
                                <option value="active" <?php echo ($promo['status'] === 'active') ? 'selected' : ''; ?>>Aktif</option>
                                <option value="inactive" <?php echo ($promo['status'] === 'inactive') ? 'selected' : ''; ?>>Nonaktif / Draf</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <!-- 3. Sasaran / Target Mobil Promo -->
            <div class="form-section-card">
                <div class="form-section-header">
                    <div class="form-section-icon blue">
                        <i class="fas fa-cars"></i>
                    </div>
                    <div>
                        <h5 class="form-section-title">3. Sasaran Unit Mobil</h5>
                        <p class="form-section-subtitle">Pilih mobil mana saja yang mendapatkan promo ini</p>
                    </div>
                </div>
                <div class="form-section-body">
                    <div class="mb-3">
                        <label class="form-label-custom">Cakupan Promo <span class="req">*</span></label>
                        <select name="target_scope" id="targetScopeSelect" class="form-select-custom" onchange="toggleTargetScope()">
                            <option value="all_vehicles" <?php echo ($promo['target_scope'] === 'all_vehicles') ? 'selected' : ''; ?>>Semua Mobil yang Ready (All Stock)</option>
                            <option value="selected_vehicles" <?php echo ($promo['target_scope'] === 'selected_vehicles') ? 'selected' : ''; ?>>Pilih Mobil Tertentu Saja</option>
                            <option value="selected_brands" <?php echo ($promo['target_scope'] === 'selected_brands') ? 'selected' : ''; ?>>Pilih Merek Tertentu Saja</option>
                        </select>
                    </div>

                    <!-- Target Vehicles Selector -->
                    <div id="targetVehiclesGroup" style="display: <?php echo ($promo['target_scope'] === 'selected_vehicles') ? 'block' : 'none'; ?>;" class="mb-3">
                        <label class="form-label-custom">Pilih Unit Mobil:</label>
                        <div class="p-2 border rounded-3 bg-light-subtle" style="max-height: 220px; overflow-y: auto;">
                            <?php foreach ($availableVehicles as $av): ?>
                            <div class="form-check py-1 border-bottom border-light-subtle">
                                <input class="form-check-input" type="checkbox" name="vehicle_ids[]" value="<?php echo $av['id']; ?>" id="veh_<?php echo $av['id']; ?>" <?php echo in_array($av['id'], $selectedIds) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-semibold text-dark-emphasis" for="veh_<?php echo $av['id']; ?>">
                                    <?php echo htmlspecialchars($av['brand_name'] . ' ' . $av['model_name'] . ' (' . $av['license_plate'] . ') - ' . $av['year']); ?>
                                </label>
                                <div class="text-muted" style="font-size: 0.72rem;">Rp <?php echo number_format($av['selling_price'], 0, ',', '.'); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Target Brands Selector -->
                    <div id="targetBrandsGroup" style="display: <?php echo ($promo['target_scope'] === 'selected_brands') ? 'block' : 'none'; ?>;" class="mb-3">
                        <label class="form-label-custom">Pilih Merek Mobil:</label>
                        <div class="p-2 border rounded-3 bg-light-subtle">
                            <?php foreach ($brands as $br): ?>
                            <div class="form-check py-1">
                                <input class="form-check-input" type="checkbox" name="brand_ids[]" value="<?php echo $br['id']; ?>" id="br_<?php echo $br['id']; ?>" <?php echo in_array($br['id'], $selectedIds) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-semibold" for="br_<?php echo $br['id']; ?>">
                                    <?php echo htmlspecialchars($br['name']); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Catatan Tambahan</label>
                        <textarea name="notes" class="form-control-custom" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? $promo['notes']); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Submit Action -->
            <div class="d-flex align-items-center justify-content-end gap-3 mb-5">
                <a href="<?php echo BASE_URL; ?>promotions/" class="btn-header-secondary">Batal</a>
                <button type="submit" class="btn-submit-promo">
                    <i class="fas fa-save"></i> Simpan Perubahan Promo
                </button>
            </div>
        </div>
    </div>
</form>

<script>
function updateBadgePreview() {
    const val = document.getElementById('badgeInput').value.trim();
    document.getElementById('badgePreview').textContent = '🔥 ' + (val || 'DISKON SPESIAL');
}

function toggleDiscountPrefix() {
    const type = document.getElementById('discountTypeSelect').value;
    document.getElementById('discountPrefix').textContent = (type === 'percentage') ? '%' : 'Rp';
}

function toggleTargetScope() {
    const scope = document.getElementById('targetScopeSelect').value;
    document.getElementById('targetVehiclesGroup').style.display = (scope === 'selected_vehicles') ? 'block' : 'none';
    document.getElementById('targetBrandsGroup').style.display = (scope === 'selected_brands') ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
