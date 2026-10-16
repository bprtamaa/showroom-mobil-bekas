<?php
// customer_requests/create.php - Tambah Permintaan / Wishlist Unit Pelanggan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

$error = '';

// Fetch Data for Dropdowns
try {
    $customers = $db->query("SELECT id, name, phone FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $models = $db->query("SELECT id, brand_id, name FROM models WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $carTypes = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = $brands = $models = $carTypes = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $customerMode = $_POST['customer_mode'] ?? 'existing';
        $customerId = null;
        $customerName = '';
        $customerPhone = '';

        if ($customerMode === 'existing' && !empty($_POST['customer_id'])) {
            $customerId = (int)$_POST['customer_id'];
            $cStmt = $db->prepare("SELECT name, phone FROM customers WHERE id = ?");
            $cStmt->execute([$customerId]);
            $cRow = $cStmt->fetch();
            if ($cRow) {
                $customerName = $cRow['name'];
                $customerPhone = $cRow['phone'];
            }
        } else {
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerPhone = trim($_POST['customer_phone'] ?? '');

            if (!empty($customerName) && !empty($customerPhone)) {
                try {
                    $chk = $db->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
                    $chk->execute([$customerPhone]);
                    $existingC = $chk->fetch();
                    if ($existingC) {
                        $customerId = $existingC['id'];
                    } else {
                        $insC = $db->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
                        $insC->execute([$customerName, $customerPhone]);
                        $customerId = (int)$db->lastInsertId();
                    }
                } catch (Exception $e) {}
            }
        }

        $brandId = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
        $modelId = !empty($_POST['model_id']) ? (int)$_POST['model_id'] : null;
        $carTypeId = !empty($_POST['car_type_id']) ? (int)$_POST['car_type_id'] : null;
        $minYear = !empty($_POST['min_year']) ? (int)$_POST['min_year'] : null;
        $maxYear = !empty($_POST['max_year']) ? (int)$_POST['max_year'] : null;
        $transmission = in_array($_POST['transmission'] ?? '', ['any', 'manual', 'automatic', 'cvt']) ? $_POST['transmission'] : 'any';
        $fuelType = in_array($_POST['fuel_type'] ?? '', ['any', 'gasoline', 'petrol', 'diesel', 'hybrid', 'electric']) ? $_POST['fuel_type'] : 'any';
        $colorPref = trim($_POST['color_preference'] ?? '');
        
        $maxBudgetRaw = preg_replace('/[^0-9]/', '', $_POST['max_budget'] ?? '');
        $maxBudget = !empty($maxBudgetRaw) ? (float)$maxBudgetRaw : null;
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if (empty($customerName)) {
            $error = 'Nama calon pembeli wajib diisi.';
        } elseif (empty($customerPhone)) {
            $error = 'Nomor telepon / WhatsApp calon pembeli wajib diisi.';
        } elseif ($minYear && $maxYear && $minYear > $maxYear) {
            $error = 'Tahun minimal tidak boleh lebih besar dari tahun maksimal.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO customer_requests (
                        customer_id, customer_name, customer_phone,
                        brand_id, model_id, car_type_id,
                        min_year, max_year, transmission, fuel_type,
                        color_preference, max_budget, notes, status
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, 'active'
                    )
                ");
                $stmt->execute([
                    $customerId, $customerName, $customerPhone,
                    $brandId, $modelId, $carTypeId,
                    $minYear, $maxYear, $transmission, $fuelType,
                    $colorPref ?: null, $maxBudget, $notes ?: null
                ]);

                showAlert('Permintaan unit baru berhasil dicatat dalam wishlist.', 'success');
                redirect('customer_requests/');
            } catch (PDOException $e) {
                $error = 'Gagal menyimpan data permintaan: ' . $e->getMessage();
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
        <h1>Catat Request Unit Pelanggan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">Request Unit</a></li>
                <li class="breadcrumb-item active" aria-current="page">Catat Baru</li>
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
        <!-- Kolom 1: Informasi Pelanggan & Budget -->
        <div class="col-lg-5">
            <div class="form-card">
                <div class="form-card-header">
                    <h3 class="form-card-title">
                        <i class="fas fa-user text-primary"></i>
                        <span>Data Calon Pembeli</span>
                    </h3>
                </div>
                <div class="form-card-body">
                    <!-- Mode Pelanggan -->
                    <div class="mb-3">
                        <label class="form-label">Tipe Pelanggan</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="customer_mode" id="modeExisting" value="existing" checked onchange="toggleCustomerMode()">
                                <label class="form-check-label small" for="modeExisting">Pilih Pelanggan Terdaftar</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="customer_mode" id="modeNew" value="new" onchange="toggleCustomerMode()">
                                <label class="form-check-label small" for="modeNew">Input Kontak Baru</label>
                            </div>
                        </div>
                    </div>

                    <!-- Dropdown Pelanggan Terdaftar -->
                    <div id="existingCustomerSection" class="mb-3">
                        <label class="form-label">Pilih Dari Kontak Showroom</label>
                        <select name="customer_id" id="customerIdSelect" class="form-select" onchange="fillExistingCustomer()">
                            <option value="">-- Cari Nama / Nomor Telepon --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name']); ?>" data-phone="<?php echo htmlspecialchars($c['phone']); ?>">
                                    <?php echo htmlspecialchars($c['name'] . ' (' . $c['phone'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Input Nama Pelanggan -->
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" id="customerNameInput" class="form-control" placeholder="Contoh: Bpk. Hendra Gunawan" required>
                    </div>

                    <!-- Input Nomor HP / WA -->
                    <div class="mb-3">
                        <label class="form-label">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fab fa-whatsapp text-success"></i></span>
                            <input type="text" name="customer_phone" id="customerPhoneInput" class="form-control" placeholder="081234567890" required>
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Digunakan untuk mengirim pemberitahuan saat unit yang cocok tiba.</div>
                    </div>

                    <!-- Budget Maksimal -->
                    <div class="mb-3">
                        <label class="form-label">Budget Maksimal Pembeli</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" name="max_budget" id="maxBudgetInput" class="form-control" placeholder="Contoh: 300.000.000" onkeyup="formatCurrency(this)">
                        </div>
                        <div class="form-text small" style="font-size: 0.72rem;">Kosongkan jika budget terbuka atau fleksibel.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kolom 2: Spesifikasi & Kriteria Kendaraan -->
        <div class="col-lg-7">
            <div class="form-card">
                <div class="form-card-header">
                    <h3 class="form-card-title">
                        <i class="fas fa-car text-success"></i>
                        <span>Kriteria Unit Mobil yang Dicari</span>
                    </h3>
                </div>
                <div class="form-card-body">
                    <div class="row g-3 mb-3">
                        <!-- Merek -->
                        <div class="col-md-6">
                            <label class="form-label">Merek Mobil</label>
                            <select name="brand_id" id="brandSelect" class="form-select" onchange="filterModelsByBrand()">
                                <option value="">-- Semua Merek --</option>
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Model -->
                        <div class="col-md-6">
                            <label class="form-label">Model Spesifik</label>
                            <select name="model_id" id="modelSelect" class="form-select">
                                <option value="">-- Semua Model --</option>
                                <?php foreach ($models as $m): ?>
                                    <option value="<?php echo $m['id']; ?>" data-brand-id="<?php echo $m['brand_id']; ?>">
                                        <?php echo htmlspecialchars($m['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Tipe Bodi -->
                        <div class="col-md-6">
                            <label class="form-label">Tipe Bodi (Jenis Mobil)</label>
                            <select name="car_type_id" class="form-select">
                                <option value="">-- Semua Tipe Bodi --</option>
                                <?php foreach ($carTypes as $ct): ?>
                                    <option value="<?php echo $ct['id']; ?>"><?php echo htmlspecialchars($ct['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Preferensi Warna -->
                        <div class="col-md-6">
                            <label class="form-label">Preferensi Warna</label>
                            <input type="text" name="color_preference" class="form-control" placeholder="Misal: Putih, Hitam, Abu-abu">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Rentang Tahun Minimal -->
                        <div class="col-md-6">
                            <label class="form-label">Tahun Minimal</label>
                            <input type="number" name="min_year" class="form-control" placeholder="Contoh: 2018" min="1990" max="<?php echo date('Y') + 1; ?>">
                        </div>

                        <!-- Rentang Tahun Maksimal -->
                        <div class="col-md-6">
                            <label class="form-label">Tahun Maksimal</label>
                            <input type="number" name="max_year" class="form-control" placeholder="Contoh: 2023" min="1990" max="<?php echo date('Y') + 1; ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Transmisi -->
                        <div class="col-md-6">
                            <label class="form-label">Transmisi</label>
                            <select name="transmission" class="form-select">
                                <option value="any">Semua Transmisi</option>
                                <option value="automatic">Automatic (A/T)</option>
                                <option value="manual">Manual (M/T)</option>
                                <option value="cvt">CVT</option>
                            </select>
                        </div>

                        <!-- Bahan Bakar -->
                        <div class="col-md-6">
                            <label class="form-label">Bahan Bakar</label>
                            <select name="fuel_type" class="form-select">
                                <option value="any">Semua Jenis BBM</option>
                                <option value="gasoline">Bensin (Gasoline)</option>
                                <option value="diesel">Diesel</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="electric">Listrik (EV)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Catatan Tambahan -->
                    <div class="mb-3">
                        <label class="form-label">Catatan Kebutuhan Khusus</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: Utamakan KM rendah (< 50.000 KM), plat nomor ganjil, tangan pertama dari baru."></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                        <a href="index.php" class="btn-header-action" style="padding: 9px 18px;">Batal</a>
                        <button type="submit" class="btn-submit-form">
                            <i class="fas fa-save"></i>
                            <span>Simpan Request Unit</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function formatCurrency(input) {
    let val = input.value.replace(/[^0-9]/g, '');
    if (val) {
        input.value = new Intl.NumberFormat('id-ID').format(parseInt(val));
    } else {
        input.value = '';
    }
}

function toggleCustomerMode() {
    const isExisting = document.getElementById('modeExisting').checked;
    const existingSection = document.getElementById('existingCustomerSection');
    const nameInput = document.getElementById('customerNameInput');
    const phoneInput = document.getElementById('customerPhoneInput');

    if (isExisting) {
        existingSection.style.display = 'block';
        fillExistingCustomer();
    } else {
        existingSection.style.display = 'none';
        document.getElementById('customerIdSelect').value = '';
        nameInput.value = '';
        phoneInput.value = '';
    }
}

function fillExistingCustomer() {
    const select = document.getElementById('customerIdSelect');
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.value) {
        document.getElementById('customerNameInput').value = selectedOption.getAttribute('data-name') || '';
        document.getElementById('customerPhoneInput').value = selectedOption.getAttribute('data-phone') || '';
    }
}

function filterModelsByBrand() {
    const brandId = document.getElementById('brandSelect').value;
    const modelSelect = document.getElementById('modelSelect');
    const options = modelSelect.querySelectorAll('option');

    options.forEach(opt => {
        if (!opt.value) {
            opt.style.display = 'block';
            return;
        }
        const optBrandId = opt.getAttribute('data-brand-id');
        if (!brandId || optBrandId === brandId) {
            opt.style.display = 'block';
        } else {
            opt.style.display = 'none';
        }
    });

    const currentSelected = modelSelect.selectedOptions[0];
    if (currentSelected && currentSelected.value && currentSelected.style.display === 'none') {
        modelSelect.value = '';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
