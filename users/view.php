<?php
session_start();
require_once '../config/database.php';

require_once '../config/database.php';

$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';

requireLogin();
$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if ($role !== 'admin') {
    showAlert('Anda tidak memiliki akses ke halaman ini!', 'danger');
    redirect('dashboard/');
}

$db = new Database();
$conn = $db->connect();

$user_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Use showAlert + redirect helper so we avoid raw header() after output
    if (function_exists('showAlert')) {
        showAlert('Pengguna tidak ditemukan!', 'danger');
    } else {
        $_SESSION['error'] = "Pengguna tidak ditemukan!";
    }
    redirect('index.php');
    // redirect() will exit internally
}

// Get user statistics
$stmt = $conn->prepare("SELECT 
                            COUNT(*) as total_sales,
                            COALESCE(SUM(total_amount), 0) as total_revenue
                        FROM transactions 
                        WHERE user_id = ?
                        AND COALESCE(payment_status, '') NOT IN ('cancelled','canceled')");
$stmt->execute([$user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent transactions
$stmt = $conn->prepare("SELECT 
                        t.*, 
                        t.total_amount AS total_price, 
                        t.payment_status AS status, 
                        t.payment_method,
                        c.name as customer_name,
                        CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle,
                        v.license_plate
                    FROM transactions t
                    JOIN customers c ON t.customer_id = c.id
                    JOIN vehicles v ON t.vehicle_id = v.id
                    JOIN models m ON v.model_id = m.id
                    JOIN brands b ON m.brand_id = b.id
                    WHERE t.user_id = ?
                    ORDER BY t.transaction_date DESC, t.created_at DESC
                    LIMIT 5");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST Logic (Edit User)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $id = $_POST['id'];
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $password = $_POST['password']; // Optional
    
    try {
        // Check username uniqueness (exclude self)
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Nama pengguna sudah digunakan!");
        }
        
        $photo_name = $user['photo'];
        
        // Handle Photo Upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $_FILES['photo']['size'] <= 2097152) {
                // Delete old
                if ($photo_name && file_exists('../uploads/users/' . $photo_name)) {
                    @unlink('../uploads/users/' . $photo_name);
                }
                $photo_name = uniqid() . '.' . $ext;
                if (!file_exists('../uploads/users')) mkdir('../uploads/users', 0777, true);
                move_uploaded_file($_FILES['photo']['tmp_name'], '../uploads/users/' . $photo_name);
            }
        } elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
            if ($photo_name && file_exists('../uploads/users/' . $photo_name)) {
                @unlink('../uploads/users/' . $photo_name);
            }
            $photo_name = null;
        }
        
        // Update DB
        $sql = "UPDATE users SET full_name = ?, username = ?, phone = ?, address = ?, photo = ?, role = ?, status = ?, updated_at = NOW()";
        $params = [$full_name, $username, $phone, $address, $photo_name, $role, $status];
        
        if (!empty($password)) {
            $sql .= ", password = ?";
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $_SESSION['success'] = "Data pengguna berhasil diperbarui!";
        echo "<script>window.location.href='view.php?id=" . $id . "';</script>";
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<style>
/* ========================================================
   Modern User Detail Page Styling
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

.page-title {
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

/* Executive Cards */
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

/* Profile Elements */
.profile-avatar-lg {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

.profile-initials-lg {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4f46e5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 2.25rem;
    font-weight: 700;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
}

.username-tag {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.75rem;
    color: #64748b;
    background: #f1f5f9;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    display: inline-block;
}

/* Role Badges */
.badge-role-admin {
    background: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.25rem 0.65rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.badge-role-staff {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.25rem 0.65rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

/* Mini KPI Stats in Profile */
.stat-box-clean {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    text-align: center;
}

.stat-box-clean .stat-num {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    font-variant-numeric: tabular-nums;
    line-height: 1.2;
}

.stat-box-clean .stat-desc {
    font-size: 0.72rem;
    color: #64748b;
    margin-top: 2px;
}

/* Detail Info Rows */
.user-info-row {
    display: flex;
    align-items: center;
    padding: 0.95rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.user-info-row:first-child {
    padding-top: 0;
}

.user-info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.user-info-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    flex-shrink: 0;
    font-size: 0.875rem;
}

.user-info-label {
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.15rem;
}

.user-info-val {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1e293b;
}

.stat-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.dot-emerald { background-color: #10b981; }
.dot-slate { background-color: #94a3b8; }
.dot-amber { background-color: #f59e0b; }
.dot-rose { background-color: #f43f5e; }

/* Status Badges */
.badge-status-paid {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
}

.badge-status-pending {
    background: #fff1f2;
    color: #9f1239;
    border: 1px solid #fecdd3;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
}

.badge-status-installment {
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fde68a;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
}

/* Dark Mode Overrides */
[data-theme="dark"] .page-title {
    color: #f1f5f9;
}

[data-theme="dark"] .page-subtitle {
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

[data-theme="dark"] .profile-initials-lg {
    background: rgba(79, 70, 229, 0.15);
    color: #a5b4fc;
    border-color: #334155;
}

[data-theme="dark"] .profile-avatar-lg {
    border-color: #334155;
}

[data-theme="dark"] .username-tag {
    background: #334155;
    color: #cbd5e1;
}

[data-theme="dark"] .badge-role-admin {
    background: rgba(79, 70, 229, 0.15);
    color: #a5b4fc;
    border-color: rgba(79, 70, 229, 0.3);
}

[data-theme="dark"] .badge-role-staff {
    background: #334155;
    color: #e2e8f0;
    border-color: #475569;
}

[data-theme="dark"] .stat-box-clean {
    background: #0f172a;
    border-color: #334155;
}

[data-theme="dark"] .stat-box-clean .stat-num {
    color: #f1f5f9;
}

[data-theme="dark"] .user-info-row {
    border-bottom-color: #334155;
}

[data-theme="dark"] .user-info-icon {
    background: #0f172a;
    border-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] .user-info-val {
    color: #f1f5f9;
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

[data-theme="dark"] .form-control,
[data-theme="dark"] .form-select {
    background-color: #0f172a;
    border-color: #334155;
    color: #f1f5f9;
}

[data-theme="dark"] .form-control:focus,
[data-theme="dark"] .form-select:focus {
    background-color: #0f172a;
    border-color: #4f46e5;
    color: #f1f5f9;
}

[data-theme="dark"] .input-group-text {
    background-color: #162032;
    border-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] table thead.table-light th {
    background-color: #162032 !important;
    color: #94a3b8 !important;
    border-bottom-color: #334155 !important;
}

[data-theme="dark"] table tbody td {
    color: #e2e8f0;
    border-bottom-color: #334155;
}
</style>

<div class="vehicles-page-header">
    <div>
        <h1 class="page-title">Detail Pengguna</h1>
        <p class="page-subtitle">Informasi profil, hak akses akun, dan riwayat aktivitas transaksi</p>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>users/" class="btn btn-outline-secondary" style="border-radius: 8px;">
            <i class="fas fa-arrow-left me-1.5"></i>Kembali
        </a>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal" style="border-radius: 8px; font-weight: 500;">
            <i class="fas fa-edit me-1.5"></i>Edit Akun
        </button>
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

<?php if (isset($error)): ?>
    <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i>
            <div><?php echo $error; ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Left Column: Profile Card -->
    <div class="col-lg-4">
        <div class="card-executive h-100">
            <div class="card-executive-body text-center pt-4">
                <div class="mb-3">
                    <?php if (!empty($user['photo']) && file_exists('../uploads/users/' . $user['photo'])): ?>
                        <img src="../uploads/users/<?php echo htmlspecialchars($user['photo']); ?>" 
                             alt="Photo" class="profile-avatar-lg">
                    <?php else: ?>
                        <div class="profile-initials-lg">
                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h5 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                <div class="username-tag mb-3">@<?php echo htmlspecialchars($user['username']); ?></div>
                
                <div class="d-flex justify-content-center gap-2 mb-4">
                    <?php if ($user['role'] == 'admin'): ?>
                        <span class="badge-role-admin">
                            <i class="fas fa-shield-alt"></i> Administrator
                        </span>
                    <?php else: ?>
                        <span class="badge-role-staff">
                            <i class="fas fa-user"></i> Staf Showroom
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($user['status'] == 'active'): ?>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1" style="font-size: 0.75rem; border-radius: 6px;">
                            <span class="stat-dot dot-emerald"></span> Aktif
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2.5 py-1" style="font-size: 0.75rem; border-radius: 6px;">
                            <span class="stat-dot dot-slate"></span> Nonaktif
                        </span>
                    <?php endif; ?>
                </div>

                <div class="border-top pt-3 text-start">
                    <div class="small fw-semibold text-muted text-uppercase mb-2" style="font-size: 0.7rem; letter-spacing: 0.05em;">Performa Transaksi</div>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="stat-box-clean">
                                <div class="stat-num"><?php echo number_format($stats['total_sales']); ?></div>
                                <div class="stat-desc">Transaksi Penjualan</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-box-clean">
                                <div class="stat-num text-success">Rp <?php echo number_format($stats['total_revenue'] / 1000000, 1, ',', '.'); ?>M</div>
                                <div class="stat-desc">Akumulasi Omset</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Detail Information -->
    <div class="col-lg-8">
        <div class="card-executive h-100">
            <div class="card-executive-header">
                <h5 class="card-executive-title">Informasi Pribadi & Akun</h5>
            </div>
            <div class="card-executive-body">
                <div class="user-info-row">
                    <div class="user-info-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div>
                        <div class="user-info-label">Nomor Telepon</div>
                        <div class="user-info-val">
                            <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-muted fw-normal fst-italic">Belum dicatat</span>'; ?>
                        </div>
                    </div>
                </div>

                <div class="user-info-row">
                    <div class="user-info-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <div class="user-info-label">Alamat Lengkap</div>
                        <div class="user-info-val">
                            <?php echo !empty($user['address']) ? nl2br(htmlspecialchars($user['address'])) : '<span class="text-muted fw-normal fst-italic">Belum dicatat</span>'; ?>
                        </div>
                    </div>
                </div>

                <div class="user-info-row">
                    <div class="user-info-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div>
                        <div class="user-info-label">Tanggal Terdaftar</div>
                        <div class="user-info-val">
                            <?php echo date('d F Y', strtotime($user['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <div class="user-info-row">
                    <div class="user-info-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="user-info-label">Terakhir Diperbarui</div>
                        <div class="user-info-val">
                            <?php echo date('d F Y, H:i', strtotime($user['updated_at'])); ?> WIB
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sales Transaction History Table -->
<div class="card-executive mb-4">
    <div class="card-executive-header">
        <h5 class="card-executive-title">Riwayat Transaksi Penjualan</h5>
        <?php if (count($transactions) > 0): ?>
            <a href="<?php echo BASE_URL; ?>transactions/?user_id=<?php echo urlencode((string)$user['id']); ?>" 
               class="btn btn-sm btn-outline-primary" style="border-radius: 6px; font-size: 0.8rem;">
                Lihat di Laporan Transaksi
            </a>
        <?php endif; ?>
    </div>
    
    <div class="card-body p-0">
        <?php if (count($transactions) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em;">
                        <th class="ps-4">Tanggal</th>
                        <th>Kode Transaksi</th>
                        <th>Unit Mobil</th>
                        <th class="text-end">Total Harga</th>
                        <th class="text-end pe-4">Status Pembayaran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td class="ps-4 text-muted small" style="font-variant-numeric: tabular-nums;">
                                <?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($trans['transaction_code']); ?>
                                </span>
                            </td>
                            <td class="fw-semibold text-dark" style="font-size: 0.875rem;">
                                <?php echo htmlspecialchars($trans['vehicle']); ?>
                            </td>
                            <td class="text-end fw-semibold text-dark" style="font-variant-numeric: tabular-nums;">
                                Rp <?php echo number_format($trans['total_price'], 0, ',', '.'); ?>
                            </td>
                            <td class="text-end pe-4">
                                <?php 
                                $statusKey = $trans['status'] ?? 'pending';
                                if ($statusKey == 'paid') {
                                    echo '<span class="badge-status-paid"><span class="stat-dot dot-emerald"></span> Lunas</span>';
                                } elseif ($statusKey == 'installment' || $statusKey == 'partial' || $trans['payment_method'] == 'installment') {
                                    echo '<span class="badge-status-installment"><span class="stat-dot dot-amber"></span> Cicilan</span>';
                                } elseif ($statusKey == 'cancelled') {
                                    echo '<span class="badge bg-light text-muted border"><span class="stat-dot dot-slate"></span> Dibatalkan</span>';
                                } else {
                                    echo '<span class="badge-status-pending"><span class="stat-dot dot-rose"></span> Pending</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="text-center p-5 text-muted">
                <i class="fas fa-receipt fa-2x mb-2 opacity-25"></i>
                <p class="mb-0 small">Belum ada transaksi penjualan yang tercatat atas nama pengguna ini.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-edit text-primary me-2"></i>Edit Pengguna</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                    
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="mb-3">
                                <label class="form-label d-block fw-semibold small text-muted">Foto Profil</label>
                                <?php if (!empty($user['photo']) && file_exists('../uploads/users/' . $user['photo'])): ?>
                                    <img src="../uploads/users/<?php echo htmlspecialchars($user['photo']); ?>" class="rounded-circle mb-3" style="width: 90px; height: 90px; object-fit: cover; border: 2px solid #e2e8f0;" id="preview">
                                    <div class="form-check text-start d-inline-block ms-3">
                                        <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhoto">
                                        <label class="form-check-label text-danger small" for="removePhoto">Hapus Foto</label>
                                    </div>
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center mb-3" style="width: 90px; height: 90px; color: white; font-size: 32px; font-weight: bold;" id="preview">
                                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <input type="file" class="form-control form-control-sm mt-2" name="photo" id="photoInput" onchange="previewImage(this)">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Pengguna <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Kata Sandi Baru <small class="text-muted fw-normal">(Kosongkan jika tidak ubah)</small></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="viewEditPassword" minlength="6" placeholder="Masukkan kata sandi baru...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('viewEditPassword')">
                                        <i class="fas fa-eye" id="viewEditPassword-icon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Peran (Role) <span class="text-danger">*</span></label>
                                    <select class="form-select" name="role" required>
                                        <option value="staff" <?php echo $user['role'] == 'staff' ? 'selected' : ''; ?>>Staff</option>
                                        <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="inactive" <?php echo $user['status'] == 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">No. Telepon</label>
                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Alamat</label>
                                <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($user['address']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-primary" style="border-radius: 8px; font-weight: 500;">
                            <i class="fas fa-save me-1.5"></i>Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('preview');
            if (preview.tagName === 'IMG') {
                preview.src = e.target.result;
            } else {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'rounded-circle mb-3';
                img.style.width = '90px';
                img.style.height = '90px';
                img.style.objectFit = 'cover';
                img.style.border = '2px solid #e2e8f0';
                img.id = 'preview';
                preview.parentNode.replaceChild(img, preview);
            }
        }
        reader.readAsDataURL(input.files[0]);
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

