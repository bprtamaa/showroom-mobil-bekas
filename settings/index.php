<?php
session_start();
require_once '../config/database.php';


$page_Title = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();
requireAdmin(); // Only admin can access settings

$db = new Database();
$conn = $db->connect();

// Get current settings, create table + default row if table doesn't exist
try {
    $stmt = $conn->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If settings table does not exist (SQLSTATE[42S02]) create the table and insert defaults
    if ($e->getCode() === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false) {
        try {
            $createSql = "CREATE TABLE IF NOT EXISTS settings (
                id INT PRIMARY KEY,
                company_name VARCHAR(255) DEFAULT '',
                company_address TEXT,
                company_phone VARCHAR(50),
                company_email VARCHAR(100),
                company_website VARCHAR(255),
                company_logo VARCHAR(255),

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            $conn->exec($createSql);

            // Ensure updated_at column exists (some older installs may lack it)
            $colCheck = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name = 'updated_at'");
            $colCheck->execute();
            $hasUpdatedAt = (int)$colCheck->fetchColumn();
            if (!$hasUpdatedAt) {
                try {
                    $conn->exec("ALTER TABLE settings ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL");
                } catch (PDOException $e3) {
                    // ignore, we'll still have defaults and avoid fatal later
                }
            }

            $ins = $conn->prepare("INSERT INTO settings (id, company_name, company_address, company_phone, company_email, company_website, company_logo) VALUES (1, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                'Showroom Mobil Bekas', '', '', '', '', ''
            ]);

            $stmt = $conn->query("SELECT * FROM settings WHERE id = 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // If creating the table fails, set an error so the page can show a message instead of crashing
            $error = 'Gagal membuat tabel pengaturan: ' . $e2->getMessage();
            $settings = [];
        }
    } else {
        // Re-throw unexpected exceptions so they'll be visible during development
        throw $e;
    }
}

// If no settings exist after the attempt above, ensure there's at least a default array to avoid undefined index warnings
if (empty($settings)) {
    $settings = [
        'company_name' => 'Showroom Mobil Bekas',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'company_website' => '',
        'company_logo' => '',

        'login_background' => '',

    ];
}

// Get system statistics
$stmt = $conn->query("SELECT 
                        (SELECT COUNT(*) FROM users) as total_users,
                        (SELECT COUNT(*) FROM brands) as total_brands,
                        (SELECT COUNT(*) FROM models) as total_models,
                        (SELECT COUNT(*) FROM vehicles) as total_vehicles,
                        (SELECT COUNT(*) FROM customers) as total_customers,
                        (SELECT COUNT(*) FROM transactions) as total_transactions");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get database size
$stmt = $conn->query("SELECT 
                        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                      FROM information_schema.TABLES 
                      WHERE table_schema = DATABASE()");
$dbSize = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $update_section = $_POST['section'] ?? '';
    $errors = [];
    
    if ($update_section == 'company') {
        // Update Company Information
        $company_name = trim($_POST['company_name']);
        $company_address = trim($_POST['company_address']);
        $company_phone = trim($_POST['company_phone']);
        $company_email = trim($_POST['company_email']);
        $company_website = trim($_POST['company_website']);
        
        if (empty($company_name)) {
            $errors[] = "Nama perusahaan wajib diisi!";
        }
        
        if (!empty($company_email) && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Format email tidak valid!";
        }
        
        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("UPDATE settings SET 
                                        company_name = ?,
                                        company_address = ?,
                                        company_phone = ?,
                                        company_email = ?,
                                        company_website = ?,
                                        updated_at = NOW()
                                        WHERE id = 1");
                $stmt->execute([
                    $company_name,
                    $company_address,
                    $company_phone,
                    $company_email,
                    $company_website
                ]);
                
                $_SESSION['success'] = "Informasi perusahaan berhasil diperbarui!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            } catch (PDOException $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
        
    } elseif ($update_section == 'logo') {
        // Update Logo
        if (isset($_POST['delete_logo']) && $_POST['delete_logo'] == '1') {
            if (!empty($settings['company_logo']) && file_exists('../uploads/settings/' . $settings['company_logo'])) {
                @unlink('../uploads/settings/' . $settings['company_logo']);
            }
            try {
                $stmt = $conn->prepare("UPDATE settings SET company_logo = ?, updated_at = NOW() WHERE id = 1");
                $stmt->execute(['']);
                $_SESSION['success'] = "Logo berhasil dihapus!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            } catch (PDOException $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
        
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                if ($_FILES['logo']['size'] <= 2097152) { // 2MB
                    // Delete old logo
                    if (!empty($settings['company_logo']) && file_exists('../uploads/settings/' . $settings['company_logo'])) {
                        @unlink('../uploads/settings/' . $settings['company_logo']);
                    }

                    $logo_name = 'logo_' . time() . '.' . $ext;
                    $upload_path = '../uploads/settings/' . $logo_name;
                    
                    if (!file_exists('../uploads/settings')) {
                        mkdir('../uploads/settings', 0777, true);
                    }
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                        try {
                            $stmt = $conn->prepare("UPDATE settings SET company_logo = ?, updated_at = NOW() WHERE id = 1");
                            $stmt->execute([$logo_name]);
                            
                            $_SESSION['success'] = "Logo berhasil diupload!";
                            echo "<script>window.location.href='index.php';</script>";
                            exit;
                        } catch (PDOException $e) {
                            $errors[] = "Error: " . $e->getMessage();
                        }
                    } else {
                        $errors[] = "Gagal mengupload logo!";
                    }
                } else {
                    $errors[] = "Ukuran logo maksimal 2MB!";
                }
            } else {
                $errors[] = "Format logo tidak valid! (jpg, jpeg, png, gif)";
            }
        } elseif (!isset($_POST['delete_logo'])) {
             // Only error if we aren't deleting and no file was uploaded
             $errors[] = "Silahkan pilih file logo!";
        }
        
    } elseif ($update_section == 'login_bg') {
        // Update Login Background
         if (isset($_POST['delete_login_bg']) && $_POST['delete_login_bg'] == '1') {
            if (!empty($settings['login_background']) && file_exists('../uploads/settings/' . $settings['login_background'])) {
                @unlink('../uploads/settings/' . $settings['login_background']);
            }
            try {
                $stmt = $conn->prepare("UPDATE settings SET login_background = ?, updated_at = NOW() WHERE id = 1");
                $stmt->execute(['']);
                $_SESSION['success'] = "Background login berhasil dihapus!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            } catch (PDOException $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
        
        if (isset($_FILES['login_background']) && $_FILES['login_background']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['login_background']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                if ($_FILES['login_background']['size'] <= 5242880) { // 5MB
                    if (!empty($settings['login_background']) && file_exists('../uploads/settings/' . $settings['login_background'])) {
                        @unlink('../uploads/settings/' . $settings['login_background']);
                    }
                    $bg_name = 'login_bg_' . time() . '.' . $ext;
                    $upload_path = '../uploads/settings/' . $bg_name;
                    if (!file_exists('../uploads/settings')) { mkdir('../uploads/settings', 0777, true); }
                    
                    if (move_uploaded_file($_FILES['login_background']['tmp_name'], $upload_path)) {
                        try {
                            $stmt = $conn->prepare("UPDATE settings SET login_background = ?, updated_at = NOW() WHERE id = 1");
                            $stmt->execute([$bg_name]);
                            $_SESSION['success'] = "Background login berhasil diupload!";
                            echo "<script>window.location.href='index.php';</script>";
                            exit;
                        } catch (PDOException $e) {
                            $errors[] = "Error: " . $e->getMessage();
                        }
                    } else {
                        $errors[] = "Gagal mengupload background login!";
                    }
                } else {
                    $errors[] = "Ukuran background maksimal 5MB!";
                }
            } else {
                $errors[] = "Format background tidak valid! (jpg, jpeg, png, gif)";
            }
        } elseif (!isset($_POST['delete_login_bg'])) {
            $errors[] = "Silahkan pilih file background!";
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}
?>

<style>
/* ========================================================
   Modern Settings Page Styling
   ======================================================== */
:root {
    --stat-bg: #ffffff;
    --stat-border: #e2e8f0;
    --stat-border-hover: #cbd5e1;
    --stat-text-title: #64748b;
    --stat-text-val: #0f172a;
    --stat-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.02);
    --stat-shadow-hover: 0 8px 16px -4px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.03);
}

.vehicles-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.vehicles-page-header .page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin-bottom: 0.25rem;
}

.page-subtitle {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 0;
}

/* Summary Cards */
.summary-card {
    background: var(--stat-bg);
    border: 1px solid var(--stat-border);
    border-radius: 12px;
    padding: 1.15rem 1.25rem;
    box-shadow: var(--stat-shadow);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--stat-shadow-hover);
    border-color: var(--stat-border-hover);
}

.summary-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
}

.summary-title-group {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.summary-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--stat-text-title);
    letter-spacing: 0.01em;
    margin: 0;
    line-height: 1.3;
}

.summary-sublabel {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 2px;
    line-height: 1.2;
}

.summary-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.summary-card:hover .summary-icon {
    color: #0f172a;
    border-color: #cbd5e1;
    background: #f1f5f9;
}

.summary-body {
    margin: 0.65rem 0 0.35rem;
}

.summary-value-wrap {
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--stat-text-val);
    line-height: 1.15;
    letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums;
}

.summary-unit {
    font-size: 0.8rem;
    font-weight: 500;
    color: #64748b;
}

.summary-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 0.65rem;
    margin-top: 0.4rem;
    font-size: 0.75rem;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.summary-meta-text {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #64748b;
    font-size: 0.75rem;
}

.stat-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.dot-emerald { background-color: #10b981; }

/* Content Cards */
.card-executive {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: var(--stat-shadow);
    overflow: hidden;
}

.card-executive-header {
    padding: 1.15rem 1.35rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-executive-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    letter-spacing: -0.01em;
}

.card-executive-body {
    padding: 1.25rem 1.35rem;
}

/* Info Rows */
.settings-info-row {
    display: flex;
    align-items: flex-start;
    padding: 0.85rem 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
}

.settings-info-row:first-child {
    padding-top: 0;
}

.settings-info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.settings-info-label {
    width: 150px;
    font-weight: 600;
    color: #64748b;
    flex-shrink: 0;
}

.settings-info-val {
    flex: 1;
    color: #1e293b;
}

/* Upload Preview Box */
.settings-preview-box {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 1.5rem;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 110px;
}

/* Dark Mode Overrides */
[data-theme="dark"] .vehicles-page-header .page-title {
    color: #f1f5f9;
}

[data-theme="dark"] .page-subtitle {
    color: #94a3b8;
}

[data-theme="dark"] .summary-card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .summary-card:hover {
    border-color: #475569;
}

[data-theme="dark"] .summary-label {
    color: #94a3b8;
}

[data-theme="dark"] .summary-sublabel {
    color: #64748b;
}

[data-theme="dark"] .summary-value {
    color: #f1f5f9;
}

[data-theme="dark"] .summary-unit {
    color: #94a3b8;
}

[data-theme="dark"] .summary-icon {
    background: #0f172a;
    border-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] .summary-card:hover .summary-icon {
    color: #f1f5f9;
    border-color: #475569;
    background: #1e293b;
}

[data-theme="dark"] .summary-footer {
    border-top-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] .summary-meta-text {
    color: #94a3b8;
}

[data-theme="dark"] .card-executive {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .card-executive-header {
    border-bottom-color: #334155;
}

[data-theme="dark"] .card-executive-title {
    color: #f1f5f9;
}

[data-theme="dark"] .settings-info-row {
    border-bottom-color: #334155;
}

[data-theme="dark"] .settings-info-label {
    color: #94a3b8;
}

[data-theme="dark"] .settings-info-val {
    color: #f1f5f9;
}

[data-theme="dark"] .settings-preview-box {
    background: #0f172a;
    border-color: #334155;
}

[data-theme="dark"] .modal-content {
    background-color: #1e293b;
    border-color: #334155;
    color: #f1f5f9;
}

[data-theme="dark"] .modal-header,
[data-theme="dark"] .modal-footer {
    border-color: #334155;
}

[data-theme="dark"] .form-control {
    background-color: #0f172a;
    border-color: #334155;
    color: #f1f5f9;
}

[data-theme="dark"] .form-control:focus {
    background-color: #0f172a;
    border-color: #4f46e5;
    color: #f1f5f9;
}
</style>

<div class="vehicles-page-header">
    <div>
        <h1 class="page-title">Pengaturan</h1>
        <p class="page-subtitle">Konfigurasi identitas showroom, profil perusahaan, dan preferensi sistem</p>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>dashboard/" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1.5"></i>Kembali
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-2"></i>
            <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i>
            <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- System KPI Summary Cards -->
<div class="row g-3 g-xl-4 mb-4">
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Database</span>
                    <span class="summary-sublabel">Ukuran penyimpanan</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-database"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo $dbSize['size_mb']; ?></span>
                    <span class="summary-unit">MB</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Terhubung & Aktif
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Pengguna</span>
                    <span class="summary-sublabel">Total akun terdaftar</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['total_users']); ?></span>
                    <span class="summary-unit">akun</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Admin & Staff
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Total Unit</span>
                    <span class="summary-sublabel">Inventaris kendaraan</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-car"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['total_vehicles']); ?></span>
                    <span class="summary-unit">unit</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Seluruh mobil
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Transaksi</span>
                    <span class="summary-sublabel">Akumulasi penjualan</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['total_transactions']); ?></span>
                    <span class="summary-unit">transaksi</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Riwayat terekam
                </span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Company Information -->
    <div class="col-lg-6">
        <div class="card-executive h-100">
            <div class="card-executive-header">
                <h5 class="card-executive-title">Profil Perusahaan</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCompanyModal" style="border-radius: 8px; font-size: 0.8rem; font-weight: 500;">
                    <i class="fas fa-edit me-1.5"></i>Edit Profil
                </button>
            </div>
            <div class="card-executive-body">
                <div class="settings-info-row">
                    <div class="settings-info-label">Nama Perusahaan</div>
                    <div class="settings-info-val fw-semibold"><?php echo htmlspecialchars($settings['company_name']); ?></div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-label">Alamat</div>
                    <div class="settings-info-val"><?php echo !empty($settings['company_address']) ? nl2br(htmlspecialchars($settings['company_address'])) : '<span class="text-muted fst-italic">Belum diatur</span>'; ?></div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-label">Telepon</div>
                    <div class="settings-info-val"><?php echo !empty($settings['company_phone']) ? htmlspecialchars($settings['company_phone']) : '<span class="text-muted fst-italic">Belum diatur</span>'; ?></div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-label">Email</div>
                    <div class="settings-info-val"><?php echo !empty($settings['company_email']) ? htmlspecialchars($settings['company_email']) : '<span class="text-muted fst-italic">Belum diatur</span>'; ?></div>
                </div>
                <div class="settings-info-row">
                    <div class="settings-info-label">Website</div>
                    <div class="settings-info-val"><?php echo !empty($settings['company_website']) ? htmlspecialchars($settings['company_website']) : '<span class="text-muted fst-italic">Belum diatur</span>'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Branding & Assets -->
    <div class="col-lg-6">
        <div class="d-flex flex-column gap-4">
            <!-- Logo Card -->
            <div class="card-executive">
                <div class="card-executive-header">
                    <h5 class="card-executive-title">Logo Aplikasi</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLogoModal" style="border-radius: 8px; font-size: 0.8rem; font-weight: 500;">
                        <i class="fas fa-camera me-1.5"></i>Ubah Logo
                    </button>
                </div>
                <div class="card-executive-body">
                    <div class="settings-preview-box mb-3">
                        <?php if (!empty($settings['company_logo']) && file_exists('../uploads/settings/' . $settings['company_logo'])): ?>
                            <img src="../uploads/settings/<?php echo htmlspecialchars($settings['company_logo']); ?>" 
                                 alt="Company Logo" class="img-fluid" style="max-height: 70px; object-fit: contain;">
                        <?php else: ?>
                            <span class="text-muted small">Belum ada logo terpasang</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Logo ditampilkan pada navbar header, laporan invoice cetak, dan identitas aplikasi.</p>
                </div>
            </div>

            <!-- Login Background Card -->
            <div class="card-executive">
                <div class="card-executive-header">
                    <h5 class="card-executive-title">Background Cover Login</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLoginBgModal" style="border-radius: 8px; font-size: 0.8rem; font-weight: 500;">
                        <i class="fas fa-image me-1.5"></i>Ubah Cover
                    </button>
                </div>
                <div class="card-executive-body">
                    <div class="settings-preview-box p-0 overflow-hidden mb-3" style="height: 110px;">
                        <?php if (!empty($settings['login_background']) && file_exists('../uploads/settings/' . $settings['login_background'])): ?>
                            <div style="background-image: url('../uploads/settings/<?php echo htmlspecialchars($settings['login_background']); ?>'); 
                                        background-size: cover; background-position: center; width: 100%; height: 100%;"></div>
                        <?php else: ?>
                            <span class="text-muted small">Default Showroom Background</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Gambar cover latar belakang pada portal login pengguna.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function clearCache() {
    if (confirm('Apakah Anda yakin ingin membersihkan cache?')) {
        alert('Fitur ini akan segera tersedia');
    }
}

function optimizeDatabase() {
    if (confirm('Apakah Anda yakin ingin mengoptimasi database?')) {
        alert('Fitur ini akan segera tersedia');
    }
}

function backupDatabase() {
    if (confirm('Apakah Anda yakin ingin membuat backup database?')) {
        window.location.href = 'backup.php';
    }
}

function viewLogs() {
    alert('Fitur log sistem akan segera tersedia');
}

function previewLogo(event) {
    const preview = document.getElementById('logoPreview');
    const file = event.target.files[0];
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (preview.tagName === 'IMG') {
                preview.src = e.target.result;
            } else {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'img-fluid mb-3';
                img.style.maxHeight = '200px';
                img.id = 'logoPreview';
                preview.parentNode.replaceChild(img, preview);
            }
        }
        reader.readAsDataURL(file);
    }
}

function previewLoginBg(event) {
    const preview = document.getElementById('loginBgPreview');
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (preview.tagName === 'IMG') {
                preview.src = e.target.result;
            } else {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'img-fluid mb-3';
                img.style.maxHeight = '240px';
                img.id = 'loginBgPreview';
                preview.parentNode.replaceChild(img, preview);
            }
        }
        reader.readAsDataURL(file);
    }
}

function postDeleteLogo() {
    if(!confirm('Hapus logo perusahaan?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    const sec = document.createElement('input');
    sec.type = 'hidden'; sec.name = 'section'; sec.value = 'logo'; form.appendChild(sec);
    const del = document.createElement('input');
    del.type = 'hidden'; del.name = 'delete_logo'; del.value = '1'; form.appendChild(del);
    document.body.appendChild(form);
    form.submit();
}

function postDeleteLoginBg() {
    if(!confirm('Hapus background login?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    const sec = document.createElement('input');
    sec.type = 'hidden'; sec.name = 'section'; sec.value = 'login_bg'; form.appendChild(sec);
    const del = document.createElement('input');
    del.type = 'hidden'; del.name = 'delete_login_bg'; del.value = '1'; form.appendChild(del);
    document.body.appendChild(form);
    form.submit();
}
</script>

<!-- Edit Company Details Modal -->
<div class="modal fade" id="editCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-building text-primary me-2"></i>Edit Informasi Perusahaan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-4">
                <form method="POST">
                    <input type="hidden" name="section" value="company">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Perusahaan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="company_name" 
                               value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Alamat</label>
                        <textarea class="form-control" name="company_address" rows="3"><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Telepon</label>
                                <input type="text" class="form-control" name="company_phone" 
                                       value="<?php echo htmlspecialchars($settings['company_phone']); ?>" 
                                       placeholder="(0266) 123456">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" class="form-control" name="company_email" 
                                       value="<?php echo htmlspecialchars($settings['company_email']); ?>" 
                                       placeholder="info@showroom.com">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Website</label>
                        <input type="url" class="form-control" name="company_website" 
                               value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>" 
                               placeholder="https://www.showroom.com">
                    </div>

                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-primary text-white shadow-sm">
                            <i class="fas fa-save me-2"></i>Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Logo Modal -->
<div class="modal fade" id="editLogoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-image text-success me-2"></i>Upload Logo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="section" value="logo">
                    
                    <div class="text-center mb-4">
                        <?php if (!empty($settings['company_logo'])): ?>
                            <img src="../uploads/settings/<?php echo htmlspecialchars($settings['company_logo']); ?>" 
                                 alt="Current Logo" class="img-fluid mb-3" 
                                 style="max-height: 150px;" id="logoPreview">
                            <div class="d-flex justify-content-center gap-2">
                                <p class="text-muted mb-0">Logo Saat Ini</p>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="postDeleteLogo()">
                                    <i class="fas fa-trash me-1"></i>Hapus
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" id="logoPreview">
                                <i class="fas fa-info-circle"></i> Belum ada logo
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Logo Baru <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="logo" accept="image/*" 
                               required onchange="previewLogo(event)">
                        <small class="text-muted d-block mt-1">Format: JPG, JPEG, PNG, GIF. Maksimal 2MB.</small>
                    </div>

                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-success shadow-sm">
                            <i class="fas fa-upload me-2"></i>Upload Logo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Login Background Modal -->
<div class="modal fade" id="editLoginBgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-images text-primary me-2"></i>Background Login</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="section" value="login_bg">
                    
                    <div class="text-center mb-4">
                        <?php if (!empty($settings['login_background'])): ?>
                            <img src="../uploads/settings/<?php echo htmlspecialchars($settings['login_background']); ?>" 
                                 alt="Current Background" class="img-fluid mb-3" style="max-height: 200px;" id="loginBgPreview">
                            <div class="d-flex justify-content-center gap-2">
                                <p class="text-muted mb-0">Background Saat Ini</p>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="postDeleteLoginBg()">
                                    <i class="fas fa-trash me-1"></i>Hapus
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" id="loginBgPreview">Belum ada background</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Background Baru <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="login_background" accept="image/*" required onchange="previewLoginBg(event)">
                        <small class="text-muted d-block mt-1">Format: JPG, JPEG, PNG, GIF. Maksimal 5MB.</small>
                    </div>

                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-primary shadow-sm">
                            <i class="fas fa-upload me-2"></i>Upload Background
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
