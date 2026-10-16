<?php
// profile/index.php - Profil Pengguna & Performa Sales
session_start();
require_once '../config/database.php';

$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();

$db = new Database();
$conn = $db->connect();

$user_id = $_SESSION['user_id'];

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "Data pengguna tidak ditemukan!";
    header('Location: ../dashboard/index.php');
    exit();
}

// Get user statistics - Only PAID transactions
$stmt = $conn->prepare("SELECT 
                            COUNT(*) as total_transactions,
                            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as completed_transactions,
                            SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as total_revenue
                        FROM transactions 
                        WHERE user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activities (last 5 transactions)
$stmt = $conn->prepare("SELECT 
                            t.*, 
                            t.payment_status AS status,
                            t.total_amount AS total_price,
                            c.name as customer_name,
                            CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle,
                            v.license_plate
                        FROM transactions t
                        JOIN customers c ON t.customer_id = c.id
                        LEFT JOIN vehicles v ON t.vehicle_id = v.id
                        LEFT JOIN models m ON v.model_id = m.id
                        LEFT JOIN brands b ON m.brand_id = b.id
                        WHERE t.user_id = ?
                        ORDER BY t.transaction_date DESC, t.created_at DESC
                        LIMIT 5");
$stmt->execute([$user_id]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly performance (last 6 months) - Only PAID transactions
$stmt = $conn->prepare("SELECT 
                            DATE_FORMAT(transaction_date, '%Y-%m') as month,
                            COUNT(*) as total_sales,
                            COALESCE(SUM(total_amount), 0) as revenue
                        FROM transactions 
                        WHERE user_id = ? 
                        AND payment_status = 'paid'
                        AND transaction_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
                        ORDER BY month DESC");
$stmt->execute([$user_id]);
$performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success_msg = '';

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'update_profile') {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        
        if (empty($full_name)) {
            $errors[] = "Nama lengkap wajib diisi!";
        }

        if (empty($username)) {
            $errors[] = "Nama pengguna wajib diisi!";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "Nama pengguna sudah digunakan!";
            }
        }
        
        $photo_name = $user['photo'] ?? null;
        
        if (!empty($_POST['remove_photo'])) {
            if (!empty($user['photo']) && file_exists('../uploads/users/' . $user['photo'])) {
                @unlink('../uploads/users/' . $user['photo']);
            }
            $photo_name = null;
        }
        
        if (empty($_POST['remove_photo']) && isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $filename = $_FILES['photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                if ($_FILES['photo']['size'] <= 2097152) { // 2MB
                    if (!empty($user['photo'] ?? '') && file_exists('../uploads/users/' . $user['photo'])) {
                        @unlink('../uploads/users/' . $user['photo']);
                    }
                    
                    $photo_name = uniqid() . '.' . $ext;
                    $upload_path = '../uploads/users/' . $photo_name;
                    
                    if (!file_exists('../uploads/users')) {
                        mkdir('../uploads/users', 0777, true);
                    }
                    
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $errors[] = "Gagal mengupload foto profil!";
                        $photo_name = $user['photo'];
                    }
                } else {
                    $errors[] = "Ukuran foto maksimal 2MB!";
                }
            } else {
                $errors[] = "Format foto tidak valid! (jpg, jpeg, png, gif, webp)";
            }
        }
        
        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, phone = ?, photo = ?, address = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$full_name, $username, $phone, $photo_name, $address ?: null, $user_id]);

                $_SESSION['full_name'] = $full_name;
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_username'] = $username;
                if ($photo_name !== null) {
                    $_SESSION['user_photo'] = $photo_name;
                } else {
                    unset($_SESSION['user_photo']);
                }

                $_SESSION['success'] = "Profil berhasil diperbarui!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            } catch (PDOException $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
        
    } elseif ($action == 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password)) {
            $errors[] = "Kata sandi lama wajib diisi!";
        } elseif (!password_verify($current_password, $user['password'])) {
            $errors[] = "Kata sandi lama tidak sesuai!";
        }
        
        if (empty($new_password)) {
            $errors[] = "Kata sandi baru wajib diisi!";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "Kata sandi baru minimal 6 karakter!";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "Konfirmasi kata sandi tidak cocok!";
        }
        
        if (empty($errors)) {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                $_SESSION['success'] = "Kata sandi berhasil diubah!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            } catch (PDOException $e) {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
}
?>

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
    .vehicles-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
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

    /* ===== Summary Cards ===== */
    .summary-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: all 0.2s;
        height: 100%;
    }
    .summary-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transform: translateY(-1px);
    }
    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .summary-icon.slate { background: #f1f5f9; color: #475569; }
    .summary-icon.emerald { background: #dcfce7; color: #166534; }
    .summary-icon.blue { background: #eff6ff; color: #2563eb; }
    .summary-icon.amber { background: #fef3c7; color: #b45309; }
    .summary-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .summary-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 2px;
    }

    /* ===== Profile Card Section ===== */
    .profile-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        padding: 24px 20px;
        text-align: center;
    }
    .profile-avatar-container {
        position: relative;
        width: 110px;
        height: 110px;
        margin: 0 auto 16px;
    }
    .profile-avatar-img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #ffffff;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .profile-avatar-placeholder {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: #2563eb;
        color: #ffffff;
        font-size: 2.2rem;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3px solid #ffffff;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .profile-status-online {
        position: absolute;
        bottom: 4px;
        right: 4px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #10b981;
        border: 2.5px solid #ffffff;
    }
    .profile-user-name {
        font-size: 1.2rem;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 2px;
        letter-spacing: -0.02em;
    }
    .profile-user-handle {
        font-size: 0.82rem;
        color: #64748b;
        margin-bottom: 12px;
    }
    .profile-role-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 20px;
    }
    .profile-role-admin { background: #dbeafe; color: #1e40af; }
    .profile-role-staff { background: #f3e8ff; color: #7e22ce; }

    .profile-action-btns {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-bottom: 24px;
    }
    .btn-profile-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        text-decoration: none;
    }
    .btn-profile-action:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .btn-profile-action.primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }
    .btn-profile-action.primary:hover {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #ffffff;
    }

    .profile-info-section {
        border-top: 1px solid #f1f5f9;
        padding-top: 20px;
        text-align: left;
    }
    .profile-info-title {
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 12px;
    }
    .profile-info-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f8fafc;
    }
    .profile-info-row:last-child {
        border-bottom: none;
    }
    .profile-info-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #f1f5f9;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    .profile-info-label {
        font-size: 0.72rem;
        color: #64748b;
        margin-bottom: 2px;
        font-weight: 500;
    }
    .profile-info-value {
        font-size: 0.86rem;
        font-weight: 600;
        color: #1e293b;
        word-break: break-word;
    }

    /* ===== Table Card & Vehicles Table ===== */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 24px;
    }
    .table-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .table-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 12px 14px;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .vehicles-table tbody td {
        padding: 12px 14px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
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
    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-installment { background: #fef3c7; color: #92400e; }
    .status-pending { background: #fee2e2; color: #991b1b; }

    /* Modals Styling */
    .modal-content {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 20px 40px -15px rgba(0,0,0,0.15);
    }
    .modal-header {
        padding: 20px 24px 14px;
        border-bottom: 1px solid #f1f5f9;
    }
    .modal-body {
        padding: 20px 24px;
    }
    .form-label-modern {
        font-size: 0.75rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
    }
    .form-control-modern {
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        font-size: 0.88rem;
        color: #1e293b;
        background: #f8fafc;
        transition: all 0.2s;
    }
    .form-control-modern:focus {
        background: #fff;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        outline: none;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .profile-card,
    [data-theme="dark"] .table-card,
    [data-theme="dark"] .modal-content { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value,
    [data-theme="dark"] .profile-user-name,
    [data-theme="dark"] .profile-info-value,
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .profile-avatar-img,
    [data-theme="dark"] .profile-avatar-placeholder { border-color: #1e293b; }
    [data-theme="dark"] .profile-status-online { border-color: #1e293b; }
    [data-theme="dark"] .btn-profile-action:not(.primary) { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .profile-info-section { border-top-color: #334155; }
    [data-theme="dark"] .profile-info-row { border-bottom-color: #243044; }
    [data-theme="dark"] .profile-info-icon { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .modal-header { border-bottom-color: #334155; }
    [data-theme="dark"] .form-label-modern { color: #cbd5e1; }
    [data-theme="dark"] .form-control-modern { background: #0f172a; border-color: #334155; color: #f1f5f9; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Profil Saya</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Profil Saya</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>dashboard/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show mb-4 rounded-3" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-2"></i>
            <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4 rounded-3" role="alert">
        <div class="d-flex align-items-start">
            <i class="fas fa-exclamation-triangle mt-1 me-2"></i>
            <div>
                <strong>Perhatian!</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Left Column: Profile Card -->
    <div class="col-lg-4">
        <div class="profile-card">
            <div class="profile-avatar-container">
                <?php if (!empty($user['photo'])): ?>
                    <img src="../uploads/users/<?php echo htmlspecialchars($user['photo']); ?>" alt="Profile" class="profile-avatar-img">
                <?php else: ?>
                    <div class="profile-avatar-placeholder">
                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <span class="profile-status-online"></span>
            </div>
            
            <div class="profile-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
            <div class="profile-user-handle">@<?php echo htmlspecialchars($user['username']); ?></div>
            
            <div>
                <?php if ($user['role'] == 'admin'): ?>
                    <span class="profile-role-badge profile-role-admin">
                        <i class="fas fa-shield-alt"></i> Administrator
                    </span>
                <?php else: ?>
                    <span class="profile-role-badge profile-role-staff">
                        <i class="fas fa-user-tie"></i> Staf Showroom
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="profile-action-btns">
                <button type="button" class="btn-profile-action primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    <i class="fas fa-user-edit"></i> Edit Profil
                </button>
                <button type="button" class="btn-profile-action" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fas fa-key text-warning"></i> Kata Sandi
                </button>
            </div>
            
            <div class="profile-info-section">
                <div class="profile-info-title">Informasi Akun & Kontak</div>
                
                <div class="profile-info-row">
                    <div class="profile-info-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div>
                        <div class="profile-info-label">Nomor Telepon</div>
                        <div class="profile-info-value"><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '-'; ?></div>
                    </div>
                </div>
                
                <div class="profile-info-row">
                    <div class="profile-info-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <div class="profile-info-label">Alamat</div>
                        <div class="profile-info-value"><?php echo !empty($user['address']) ? htmlspecialchars($user['address']) : '-'; ?></div>
                    </div>
                </div>
                
                <div class="profile-info-row">
                    <div class="profile-info-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <div class="profile-info-label">Bergabung Sejak</div>
                        <div class="profile-info-value">
                            <?php 
                            $created = strtotime($user['created_at']);
                            $indonesianMonths = [
                                'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                                'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                            ];
                            echo date('d', $created) . ' ' . $indonesianMonths[date('n', $created) - 1] . ' ' . date('Y', $created); 
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Stats & Performance Chart -->
    <div class="col-lg-8">
        <!-- 2 KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <div class="summary-card">
                    <div class="summary-icon slate">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div>
                        <div class="summary-value"><?php echo number_format($stats['total_transactions']); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Unit</span></div>
                        <div class="summary-label">Total Transaksi</div>
                        <small class="text-success fw-bold" style="font-size: 0.72rem;"><?php echo number_format($stats['completed_transactions']); ?> Transaksi Selesai</small>
                    </div>
                </div>
            </div>
            
            <div class="col-sm-6">
                <div class="summary-card">
                    <div class="summary-icon emerald">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div>
                        <div class="summary-value" style="font-size: 1.15rem;"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                        <div class="summary-label">Total Pendapatan</div>
                        <small class="text-muted" style="font-size: 0.72rem;">Dari transaksi selesai</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart Card -->
        <?php if (count($performance) > 0): ?>
        <div class="table-card mb-0">
            <div class="table-card-header">
                <h5 class="table-card-title">
                    <i class="fas fa-chart-line text-primary"></i> Performa Penjualan 6 Bulan Terakhir
                </h5>
            </div>
            <div class="card-body p-4">
                <canvas id="performanceChart" height="110"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Full Width: Recent Activity Table -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">
            <i class="fas fa-history text-primary"></i> Aktivitas Transaksi Terakhir
        </h5>
        <a href="<?php echo BASE_URL; ?>transactions/index.php" class="btn-header-secondary" style="padding: 5px 12px; font-size: 0.78rem;">
            Lihat Semua <i class="fas fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (count($activities) > 0): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="min-width: 130px;">TANGGAL</th>
                        <th style="min-width: 140px;">KODE TRANSAKSI</th>
                        <th style="min-width: 160px;">PELANGGAN</th>
                        <th style="min-width: 180px;">UNIT MOBIL</th>
                        <th style="min-width: 140px;">TOTAL HARGA</th>
                        <th style="min-width: 110px;" class="text-center">STATUS</th>
                        <th style="width: 70px;" class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $act): 
                        $statusClass = 'status-completed';
                        $statusLabel = 'Lunas';
                        if ($act['status'] == 'installment' || $act['payment_method'] == 'installment') {
                            $statusClass = 'status-installment';
                            $statusLabel = 'Cicilan';
                        } elseif ($act['status'] == 'pending') {
                            $statusClass = 'status-pending';
                            $statusLabel = 'Pending';
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo date('d M Y', strtotime($act['transaction_date'])); ?></div>
                        </td>
                        <td>
                            <span class="plate-badge font-monospace"><?php echo htmlspecialchars($act['transaction_code']); ?></span>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($act['customer_name']); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($act['vehicle'] ?: 'Unit Mobil'); ?></div>
                            <?php if (!empty($act['license_plate'])): ?>
                            <span class="text-muted small"><?php echo htmlspecialchars($act['license_plate']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($act['total_price']); ?></div>
                        </td>
                        <td class="text-center">
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $statusLabel; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="../transactions/view.php?id=<?php echo $act['id']; ?>" class="btn btn-sm btn-light border rounded-2" title="Lihat Detail">
                                <i class="fas fa-eye text-muted"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-clipboard-list fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Belum ada aktivitas transaksi yang tercatat.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="font-size: 1.05rem;"><i class="fas fa-user-edit text-primary me-2"></i>Edit Informasi Profil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="editProfileForm">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row g-4 align-items-center mb-4">
                        <div class="col-md-4 text-center">
                            <div class="position-relative d-inline-block">
                                <?php if (!empty($user['photo'])): ?>
                                    <img src="../uploads/users/<?php echo htmlspecialchars($user['photo']); ?>" 
                                         alt="Photo" class="rounded-circle mb-2" 
                                         style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #e2e8f0;" id="preview">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary d-inline-flex align-items-center justify-content-center mb-2" 
                                         style="width: 120px; height: 120px; color: white; font-size: 48px; border: 3px solid #e2e8f0;" id="preview">
                                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <label for="photoInput" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle" style="width: 36px; height: 36px; padding: 0; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                                    <i class="fas fa-camera"></i>
                                </label>
                            </div>
                            
                            <input type="file" class="d-none" name="photo" accept="image/*" onchange="previewImage(event)" id="photoInput">
                            
                            <?php if (!empty($user['photo'])): ?>
                            <div class="mt-2">
                                <div class="form-check d-inline-block">
                                    <input class="form-check-input" type="checkbox" name="remove_photo" 
                                           id="removePhoto" value="1" onchange="handleRemovePhoto()">
                                    <label class="form-check-label text-danger small fw-semibold" for="removePhoto">
                                        Hapus foto profil
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label form-label-modern">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-modern" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label form-label-modern">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-modern" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label form-label-modern">Nomor Telepon</label>
                                <input type="text" class="form-control form-control-modern" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-modern">Alamat Lengkap</label>
                        <textarea class="form-control form-control-modern" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn-header-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-profile-action primary">
                            <i class="fas fa-save me-1"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="font-size: 1.05rem;"><i class="fas fa-key text-warning me-2"></i>Ubah Kata Sandi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label class="form-label form-label-modern">Kata Sandi Lama <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-modern" name="current_password" id="currentPassword" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('currentPassword')">
                                <i class="fas fa-eye" id="currentPassword-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-modern">Kata Sandi Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-modern" name="new_password" id="newPassword" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword')">
                                <i class="fas fa-eye" id="newPassword-icon"></i>
                            </button>
                        </div>
                        <small class="text-muted" style="font-size: 0.75rem;">Minimal 6 karakter kombinasi</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-modern">Konfirmasi Kata Sandi Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-modern" name="confirm_password" id="confirmPassword" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword')">
                                <i class="fas fa-eye" id="confirmPassword-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn-header-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning fw-bold px-4">
                            <i class="fas fa-key me-1"></i> Update Kata Sandi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const performanceData = <?php echo json_encode(array_reverse($performance)); ?>;
    
    if (performanceData && performanceData.length > 0) {
        const labels = performanceData.map(item => {
            const [year, month] = item.month.split('-');
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            return months[parseInt(month) - 1] + ' ' + year;
        });
        
        const salesData = performanceData.map(item => parseInt(item.total_sales));
        const revenueData = performanceData.map(item => parseFloat(item.revenue));
        
        const ctx = document.getElementById('performanceChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Jumlah Penjualan (Unit)',
                            data: salesData,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.08)',
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.35,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Total Pendapatan (Rp)',
                            data: revenueData,
                            borderColor: '#16a34a',
                            backgroundColor: 'rgba(22, 163, 74, 0.08)',
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.35,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 8,
                                font: { size: 11, weight: '600' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: { display: true, text: 'Unit Terjual' }
                        },
                        y1: {
                            type: 'linear',
                            display: false,
                            position: 'right'
                        }
                    }
                }
            });
        }
    }

    // Preview image
    function previewImage(event) {
        const preview = document.getElementById('preview');
        const file = event.target.files[0];
        const removeCheckbox = document.getElementById('removePhoto');
        
        if (removeCheckbox) removeCheckbox.checked = false;
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                if (preview.tagName === 'IMG') {
                    preview.src = e.target.result;
                } else {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'rounded-circle mb-2';
                    img.style.width = '120px';
                    img.style.height = '120px';
                    img.style.objectFit = 'cover';
                    img.style.border = '3px solid #e2e8f0';
                    img.id = 'preview';
                    preview.parentNode.replaceChild(img, preview);
                }
            }
            reader.readAsDataURL(file);
        }
    }

    function handleRemovePhoto() {
        const removeCheckbox = document.getElementById('removePhoto');
        const photoInput = document.getElementById('photoInput');
        const preview = document.getElementById('preview');
        
        if (removeCheckbox && removeCheckbox.checked) {
            if (photoInput) photoInput.value = '';
            if (preview) {
                const userName = '<?php echo addslashes($user['full_name']); ?>';
                const initial = userName ? userName.charAt(0).toUpperCase() : '?';
                
                if (preview.tagName === 'IMG') {
                    const placeholder = document.createElement('div');
                    placeholder.className = 'rounded-circle bg-primary d-inline-flex align-items-center justify-content-center mb-2';
                    placeholder.style.width = '120px';
                    placeholder.style.height = '120px';
                    placeholder.style.color = 'white';
                    placeholder.style.fontSize = '48px';
                    placeholder.style.border = '3px solid #e2e8f0';
                    placeholder.id = 'preview';
                    placeholder.textContent = initial;
                    preview.parentNode.replaceChild(placeholder, preview);
                }
            }
        }
    }

    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + '-icon');
        
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>

<?php include '../includes/footer.php'; ?>