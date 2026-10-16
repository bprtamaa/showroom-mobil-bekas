<?php
require_once '../config/database.php';

// Include header early (sets up session, helpers)
$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';

// Require login and admin role
requireLogin();
$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if ($role !== 'admin') {
    showAlert('Anda tidak memiliki akses ke halaman ini!', 'danger');
    redirect('dashboard/');
}

$db = new Database();
$conn = $db->connect();

// Handle search
$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

// Build query
$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (full_name LIKE ? OR username LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($role_filter)) {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'ASC';

// Map frontend sort keys to database columns
$db_sort_column = $sort_column;
$db_sort_order = $sort_order;

if ($sort_column === 'created_at') {
    // Reverse logic for Time based sorting on No column
    // ASC UI = Newest First (DESC DB)
    // DESC UI = Oldest First (ASC DB)
    if ($sort_order === 'ASC') {
        $db_sort_order = 'DESC';
    } else {
        $db_sort_order = 'ASC';
    }
} else {
    $db_sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
}

$query .= " ORDER BY $db_sort_column $db_sort_order LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total for pagination
$countQuery = "SELECT COUNT(*) as total FROM users WHERE 1=1";
$countParams = [];

if (!empty($search)) {
    $countQuery .= " AND (full_name LIKE ? OR username LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

if (!empty($role_filter)) {
    $countQuery .= " AND role = ?";
    $countParams[] = $role_filter;
}

$stmt = $conn->prepare($countQuery);
$stmt->execute($countParams);
$totalFilteredRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalFilteredRecords / $limit);

// Get statistics
$stmt = $conn->query("SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
                        SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) as staff_count,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
                      FROM users");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle POST Requests (Add/Edit User)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'add_user') {
                $full_name = trim($_POST['full_name']);
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $phone = trim($_POST['phone']);
                $address = trim($_POST['address']);
                $role = $_POST['role'];
                
                // Validation
                if (empty($full_name) || empty($username) || empty($password) || empty($role)) {
                    throw new Exception("Semua field wajib diisi kecuali foto dan alamat.");
                }
                
                // Check username
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() > 0) {
                    throw new Exception("Nama pengguna sudah digunakan!");
                }
                
                // Handle Photo
                $photo_name = null;
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed) && $_FILES['photo']['size'] <= 2097152) {
                        $photo_name = uniqid() . '.' . $ext;
                        if (!file_exists('../uploads/users')) mkdir('../uploads/users', 0777, true);
                        move_uploaded_file($_FILES['photo']['tmp_name'], '../uploads/users/' . $photo_name);
                    }
                }
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, phone, address, photo, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())");
                $stmt->execute([$full_name, $username, $hashed_password, $phone, $address, $photo_name, $role]);
                
                $_SESSION['success'] = "Pengguna berhasil ditambahkan!";
                echo "<script>window.location.href='index.php';</script>";
                exit;
                
            } elseif ($action === 'edit_user') {
                $id = $_POST['id'];
                $full_name = trim($_POST['full_name']);
                $username = trim($_POST['username']);
                $phone = trim($_POST['phone']);
                $address = trim($_POST['address']);
                $role = $_POST['role'];
                $status = $_POST['status'];
                $password = $_POST['password']; // Optional
                
                // Check username uniqueness
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $id]);
                if ($stmt->rowCount() > 0) {
                    throw new Exception("Nama pengguna sudah digunakan!");
                }
                
                // Get current user data
                $stmt = $conn->prepare("SELECT photo FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $currentUser = $stmt->fetch();
                $photo_name = $currentUser['photo'];
                
                // Handle Photo
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed) && $_FILES['photo']['size'] <= 2097152) {
                        // Delete old
                        if ($photo_name && file_exists('../uploads/users/' . $photo_name)) {
                            @unlink('../uploads/users/' . $photo_name);
                        }
                        $photo_name = uniqid() . '.' . $ext;
                        move_uploaded_file($_FILES['photo']['tmp_name'], '../uploads/users/' . $photo_name);
                    }
                } elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
                    if ($photo_name && file_exists('../uploads/users/' . $photo_name)) {
                        @unlink('../uploads/users/' . $photo_name);
                    }
                    $photo_name = null;
                }
                
                // Update Query
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
                echo "<script>window.location.href='index.php';</script>";
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
}
?>

<style>
/* ========================================================
   Modern Users Management Styling
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
.dot-slate { background-color: #94a3b8; }
.dot-amber { background-color: #f59e0b; }
.dot-indigo { background-color: #6366f1; }

/* Filter Card */
.search-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: var(--stat-shadow);
}

/* Table Card */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: var(--stat-shadow);
    overflow: hidden;
}

.table-card-header {
    padding: 1.15rem 1.35rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.table-card-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    letter-spacing: -0.01em;
}

/* Custom Table Styles */
.user-avatar-img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid #e2e8f0;
}

.user-avatar-initial {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 700;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e0e7ff;
    flex-shrink: 0;
}

.username-tag {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.725rem;
    color: #64748b;
    background: #f1f5f9;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    display: inline-block;
    margin-top: 2px;
}

/* Role Badges */
.badge-role-admin {
    background: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.badge-role-staff {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Action Buttons */
.btn-action-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-action-icon:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
}

.btn-action-icon-danger:hover {
    background: #e11d48;
    color: #ffffff;
    border-color: #e11d48;
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

[data-theme="dark"] .search-card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .table-card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .table-card-header {
    border-bottom-color: #334155;
}

[data-theme="dark"] .table-card-title {
    color: #f1f5f9;
}

[data-theme="dark"] .user-avatar-initial {
    background: rgba(79, 70, 229, 0.15);
    color: #a5b4fc;
    border-color: rgba(79, 70, 229, 0.3);
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

[data-theme="dark"] .btn-action-icon {
    border-color: #334155;
    background: #1e293b;
    color: #94a3b8;
}

[data-theme="dark"] .btn-action-icon:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
}

[data-theme="dark"] .btn-action-icon-danger:hover {
    background: #e11d48;
    color: #ffffff;
    border-color: #e11d48;
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
        <h1 class="page-title">Kelola Pengguna</h1>
        <p class="page-subtitle">Daftar akun staf dan administrator sistem showroom</p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal" style="border-radius: 8px; font-weight: 500;">
            <i class="fas fa-plus me-1.5"></i>Tambah Pengguna
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

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i>
            <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Statistics KPI Cards -->
<div class="row g-3 g-xl-4 mb-4">
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Total Pengguna</span>
                    <span class="summary-sublabel">Seluruh akun terdaftar</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['total']); ?></span>
                    <span class="summary-unit">orang</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Terdaftar di sistem
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Administrator</span>
                    <span class="summary-sublabel">Akses penuh sistem</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['admin_count']); ?></span>
                    <span class="summary-unit">akun</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-indigo"></span> Hak akses Admin
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Staf Operasional</span>
                    <span class="summary-sublabel">Petugas showroom</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['staff_count']); ?></span>
                    <span class="summary-unit">akun</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-slate"></span> Hak akses Staf
                </span>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Akun Aktif</span>
                    <span class="summary-sublabel">Dapat masuk sistem</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-circle-check"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($stats['active_count']); ?></span>
                    <span class="summary-unit">akun</span>
                </div>
            </div>
            <div class="summary-footer">
                <span class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span> Status aktif
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter -->
<div class="search-card p-3 mb-4">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 bg-light" name="search" 
                       placeholder="Cari nama lengkap atau nama pengguna..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="role" class="form-select" style="cursor: pointer;">
                <option value="">Semua Peran (Role)</option>
                <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Staff</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary w-50" style="border-radius: 8px;">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-light border w-50" style="border-radius: 8px;">
                    <i class="fas fa-redo me-1"></i>Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Pengguna</h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.75rem;"><?php echo $totalFilteredRecords; ?> Pengguna</span>
            <select class="form-select form-select-sm" style="width: 75px; cursor: pointer;" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (count($users) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="usersTable">
                    <thead class="table-light">
                        <tr style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em;">
                            <th class="ps-4" style="width: 60px;">No</th>
                            <th>Pengguna</th>
                            <th>No. Telepon</th>
                            <th>Peran</th>
                            <th>Status</th>
                            <th>Terdaftar</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($users as $user): 
                        ?>
                            <tr>
                                <td class="ps-4 text-muted small" style="font-variant-numeric: tabular-nums;"><?php echo $offset + $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($user['photo']) && file_exists('../uploads/users/' . $user['photo'])): ?>
                                            <img src="../uploads/users/<?php echo htmlspecialchars($user['photo']); ?>" 
                                                 alt="Photo" class="user-avatar-img">
                                        <?php else: ?>
                                            <div class="user-avatar-initial">
                                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-semibold text-dark" style="font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                    <span class="badge bg-light text-primary border ms-1" style="font-size: 0.65rem; font-weight: 500;">Anda</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="username-tag">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="font-size: 0.85rem;">
                                    <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php if ($user['role'] == 'admin'): ?>
                                        <span class="badge-role-admin"><i class="fas fa-shield-alt"></i> Admin</span>
                                    <?php else: ?>
                                        <span class="badge-role-staff"><i class="fas fa-user"></i> Staff</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['status'] == 'active'): ?>
                                        <span class="d-inline-flex align-items-center gap-1.5 small text-emerald fw-medium">
                                            <span class="stat-dot dot-emerald"></span> Aktif
                                        </span>
                                    <?php else: ?>
                                        <span class="d-inline-flex align-items-center gap-1.5 small text-muted fw-medium">
                                            <span class="stat-dot dot-slate"></span> Nonaktif
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small" style="font-variant-numeric: tabular-nums;">
                                    <?php echo date('d/m/Y', strtotime($user['created_at'])); ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-1">
                                        <a href="view.php?id=<?php echo $user['id']; ?>" 
                                           class="btn-action-icon" data-bs-toggle="tooltip" title="Lihat Profil">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" class="btn-action-icon" 
                                                data-bs-toggle="tooltip" title="Edit Akun"
                                                data-user="<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="openEditModal(this)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button type="button" class="btn-action-icon btn-action-icon-danger" 
                                                    onclick="deleteUser(<?php echo $user['id']; ?>)" 
                                                    data-bs-toggle="tooltip" title="Hapus Akun">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-4 text-center text-muted">
                <i class="fas fa-info-circle me-1"></i> Tidak ada data pengguna yang ditemukan.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Pagination" class="mt-4">
    <ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>
        
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                <?php echo $i; ?>
            </a>
        </li>
        <?php endfor; ?>
        
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<script>
function deleteUser(userId) {
    if (confirm('Apakah Anda yakin ingin menghapus akun pengguna ini?')) {
        window.location.href = 'delete.php?id=' + userId;
    }
}

function openEditModal(element) {
    const data = JSON.parse(element.getAttribute('data-user'));
    const modal = document.getElementById('editUserModal');
    const form = document.getElementById('editUserForm');
    
    // Set values
    form.querySelector('[name="id"]').value = data.id;
    form.querySelector('[name="full_name"]').value = data.full_name;
    form.querySelector('[name="username"]').value = data.username;
    form.querySelector('[name="phone"]').value = data.phone || '';
    form.querySelector('[name="address"]').value = data.address || '';
    form.querySelector('[name="role"]').value = data.role;
    form.querySelector('[name="status"]').value = data.status;
    
    // Handle photo preview
    const previewContainer = document.getElementById('editPhotoPreview');
    if (data.photo) {
        previewContainer.innerHTML = `<img src="../uploads/users/${data.photo}" class="rounded-circle mb-3" style="width: 90px; height: 90px; object-fit: cover; border: 2px solid #e2e8f0;">
        <div class="form-check text-start d-inline-block ms-3">
            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhotoEdit">
            <label class="form-check-label text-danger small" for="removePhotoEdit">Hapus Foto</label>
        </div>`;
    } else {
        const initial = data.full_name.charAt(0).toUpperCase();
        previewContainer.innerHTML = `<div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center mb-3" style="width: 90px; height: 90px; color: white; font-size: 32px; font-weight: bold;">${initial}</div>`;
    }
    
    new bootstrap.Modal(modal).show();
}

function previewImage(input, previewId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById(previewId).src = e.target.result;
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus text-primary me-2"></i>Tambah Pengguna Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="mb-3">
                                <label class="form-label d-block fw-semibold small text-muted">Foto Profil</label>
                                <img src="../assets/img/default-avatar.png" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'50\' fill=\'%23cbd5e1\'/></svg>'" class="rounded-circle mb-2" style="width: 90px; height: 90px; object-fit: cover; border: 2px solid #e2e8f0;" id="addPreview">
                                <input type="file" class="form-control form-control-sm mt-2" name="photo" accept="image/*" onchange="previewImage(this, 'addPreview')">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" placeholder="cth: Ahmad Fauzi" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Pengguna <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="username" placeholder="cth: fauzi" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Kata Sandi <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="addPassword" required minlength="6" placeholder="Minimal 6 karakter">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('addPassword')">
                                        <i class="fas fa-eye" id="addPassword-icon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Peran (Role) <span class="text-danger">*</span></label>
                                    <select class="form-select" name="role" required>
                                        <option value="staff">Staff</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">No. Telepon</label>
                                    <input type="text" class="form-control" name="phone" placeholder="cth: 08123456789">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Alamat</label>
                                <textarea class="form-control" name="address" rows="2" placeholder="Alamat domisili staf..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-primary" style="border-radius: 8px; font-weight: 500;">
                            <i class="fas fa-save me-1.5"></i>Simpan Pengguna
                        </button>
                    </div>
                </form>
            </div>
        </div>
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
                <form method="POST" enctype="multipart/form-data" id="editUserForm">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="id" value="">
                    
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="mb-3">
                                <label class="form-label d-block fw-semibold small text-muted">Foto Profil</label>
                                <div id="editPhotoPreview"></div>
                                <input type="file" class="form-control form-control-sm mt-2" name="photo">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Lengkap <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Nama Pengguna <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="username" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Kata Sandi Baru <small class="text-muted fw-normal">(Kosongkan jika tidak ubah)</small></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="editPassword" minlength="6" placeholder="Masukkan kata sandi baru...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('editPassword')">
                                        <i class="fas fa-eye" id="editPassword-icon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Peran (Role) <span class="text-danger">*</span></label>
                                    <select class="form-select" name="role" required>
                                        <option value="staff">Staff</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold small">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <option value="active">Aktif</option>
                                        <option value="inactive">Nonaktif</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">No. Telepon</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Alamat</label>
                                <textarea class="form-control" name="address" rows="2"></textarea>
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

<?php include '../includes/footer.php'; ?>