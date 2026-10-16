<?php
// maintenance/index.php - Daftar & Manajemen Perawatan Mobil
require_once '../config/config.php';
require_once '../includes/header.php';
requireLogin();

// Fetch vehicles for dropdowns (Available or Sold with active warranty)
try {
    $vStmt = $db->query("
        SELECT DISTINCT v.id, b.name as brand_name, m.name as model_name, v.license_plate, v.status,
               v.warranty_type, v.warranty_period,
               t.transaction_date,
               CASE 
                   WHEN v.status = 'available' THEN 1
                   WHEN v.status = 'sold' AND v.warranty_type != 'none' AND v.warranty_period > 0 THEN
                       CASE 
                           WHEN DATE_ADD(t.transaction_date, INTERVAL v.warranty_period MONTH) >= CURDATE() THEN 1
                           ELSE 0
                       END
                   ELSE 0
               END as is_eligible
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN transactions t ON v.id = t.vehicle_id AND v.status = 'sold'
        HAVING is_eligible = 1
        ORDER BY b.name, m.name
    ");
    $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vehicles = [];
}

// Ensure notes column exists
try {
    $colCheck = $db->query("SHOW COLUMNS FROM maintenance LIKE 'description'");
    if ($colCheck->rowCount() > 0) {
        $db->exec("ALTER TABLE maintenance CHANGE COLUMN description notes TEXT");
    } else {
        $notesCheck = $db->query("SHOW COLUMNS FROM maintenance LIKE 'notes'");
        if ($notesCheck->rowCount() === 0) {
             $db->exec("ALTER TABLE maintenance ADD COLUMN notes TEXT");
        }
    }
} catch (PDOException $e) {}

// Ensure service_type column exists
try {
    $colCheck = $db->query("SHOW COLUMNS FROM maintenance LIKE 'service_type'");
    if ($colCheck->rowCount() === 0) {
        $db->exec("ALTER TABLE maintenance ADD COLUMN service_type VARCHAR(50) DEFAULT 'routine' AFTER vehicle_id");
    }
} catch (PDOException $e) {}

// Handle Form Submissions (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = "Token keamanan tidak valid (CSRF). Silakan muat ulang halaman.";
        } else {
            $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
            $start_date = $_POST['start_date'] ?? date('Y-m-d');
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $cost = (float)str_replace(['Rp', '.', ' '], '', $_POST['cost'] ?? '0');
            $status = $_POST['status'] ?? 'scheduled';
            $service_type = $_POST['service_type'] ?? 'routine';
            $notes = sanitizeInput($_POST['notes'] ?? '');
            
            if (empty($vehicle_id) || empty($start_date)) {
                $error = "Mohon lengkapi data kendaraan dan tanggal mulai.";
            } else {
                try {
                    if ($_POST['action'] === 'add_maintenance') {
                        $sql = "INSERT INTO maintenance (vehicle_id, service_type, start_date, end_date, cost, status, notes, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$vehicle_id, $service_type, $start_date, $end_date, $cost, $status, $notes]);
                        
                        $needsMaintenanceStatus = false;
                        if (!empty($end_date) && !empty($start_date)) {
                            $startDateTime = new DateTime($start_date);
                            $endDateTime = new DateTime($end_date);
                            $interval = $startDateTime->diff($endDateTime);
                            if ($interval->days > 1) {
                                $needsMaintenanceStatus = true;
                            }
                        }
                        
                        if ($status === 'in_progress' || $needsMaintenanceStatus) {
                            $checkV = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
                            $checkV->execute([$vehicle_id]);
                            $vInfo = $checkV->fetch();
                            if ($vInfo && $vInfo['status'] !== 'sold') {
                                $updateV = $db->prepare("UPDATE vehicles SET status = 'maintenance' WHERE id = ?");
                                $updateV->execute([$vehicle_id]);
                            }
                        }
                        
                        showAlert('Data perawatan berhasil ditambahkan ke sistem.', 'success');
                        redirect('maintenance/');
                        
                    } elseif ($_POST['action'] === 'edit_maintenance') {
                        $id = (int)($_POST['id'] ?? 0);
                        
                        $oldLogStmt = $db->prepare("SELECT status, vehicle_id FROM maintenance WHERE id = ?");
                        $oldLogStmt->execute([$id]);
                        $oldLog = $oldLogStmt->fetch();
                        $old_status = $oldLog['status'] ?? '';
                        
                        $sql = "UPDATE maintenance SET 
                                vehicle_id = ?, service_type = ?, start_date = ?, end_date = ?, 
                                cost = ?, status = ?, notes = ?, updated_at = NOW() 
                                WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$vehicle_id, $service_type, $start_date, $end_date, $cost, $status, $notes, $id]);
                        
                        $needsMaintenanceStatus = false;
                        if (!empty($end_date) && !empty($start_date)) {
                            $startDateTime = new DateTime($start_date);
                            $endDateTime = new DateTime($end_date);
                            $interval = $startDateTime->diff($endDateTime);
                            if ($interval->days > 1) {
                                $needsMaintenanceStatus = true;
                            }
                        }
                        
                        if ($status !== $old_status || $needsMaintenanceStatus) {
                            if ($status === 'in_progress' || $needsMaintenanceStatus) {
                                $checkV = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
                                $checkV->execute([$vehicle_id]);
                                $vInfo = $checkV->fetch();
                                if ($vInfo && $vInfo['status'] !== 'sold') {
                                    $updateVehicle = $db->prepare("UPDATE vehicles SET status = 'maintenance' WHERE id = ?");
                                    $updateVehicle->execute([$vehicle_id]);
                                }
                            } else if ($status === 'completed' && $old_status !== 'completed') {
                                $checkV = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
                                $checkV->execute([$vehicle_id]);
                                $vStat = $checkV->fetchColumn();
                                if ($vStat === 'maintenance') {
                                    $updateVehicle = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                                    $updateVehicle->execute([$vehicle_id]);
                                }
                            }
                        }
                        
                        showAlert('Data perawatan berhasil diperbarui.', 'success');
                        redirect('maintenance/');
                    }
                } catch (PDOException $e) {
                    $error = "Gagal memproses data perawatan: " . $e->getMessage();
                }
            }
        }
    }
}

// Auto-update overdue records
try {
    $today = date('Y-m-d');
    $autoUpdateSql = "SELECT m.id, m.vehicle_id, m.status 
                      FROM maintenance m 
                      WHERE m.status IN ('in_progress', 'scheduled') 
                      AND m.end_date IS NOT NULL 
                      AND m.end_date < ?";
    $autoUpdateStmt = $db->prepare($autoUpdateSql);
    $autoUpdateStmt->execute([$today]);
    $overdueRecords = $autoUpdateStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($overdueRecords)) {
        foreach ($overdueRecords as $record) {
            $updateMaintenance = $db->prepare("UPDATE maintenance SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $updateMaintenance->execute([$record['id']]);
            
            $checkVehicle = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
            $checkVehicle->execute([$record['vehicle_id']]);
            if ($checkVehicle->fetchColumn() === 'maintenance') {
                $updateVehicle = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                $updateVehicle->execute([$record['vehicle_id']]);
            }
        }
    }
} catch (PDOException $e) {}

// Stats KPI Summary Query
try {
    $statsQuery = $db->query("
        SELECT 
            COUNT(*) as total_maintenance,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as total_in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
            SUM(cost) as total_cost
        FROM maintenance
    ");
    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_maintenance' => 0, 'total_in_progress' => 0, 'total_completed' => 0, 'total_cost' => 0];
}

// Search, pagination & sorting
$search = trim($_GET['search'] ?? '');
$service_type_filter = trim($_GET['service_type'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

$sort_column = $_GET['sort'] ?? 'start_date';
$sort_order = $_GET['order'] ?? 'ASC';

$allowed_sorts = ['start_date', 'cost', 'status', 'service_type'];
if (!in_array($sort_column, $allowed_sorts)) {
    $sort_column = 'start_date';
}
$db_sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Reverse logic for time-based start_date
if ($sort_column === 'start_date') {
    $db_sort_order = ($sort_order === 'ASC') ? 'DESC' : 'ASC';
}

try {
    $whereClauses = [];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(b.name LIKE ? OR mo.name LIKE ? OR v.license_plate LIKE ? OR m.notes LIKE ?)";
        $sParam = "%$search%";
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
    }
    
    if (!empty($service_type_filter)) {
        $whereClauses[] = "m.service_type = ?";
        $params[] = $service_type_filter;
    }

    if (!empty($status_filter)) {
        $whereClauses[] = "m.status = ?";
        $params[] = $status_filter;
    }
    
    $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // Count total
    $countSql = "SELECT COUNT(*) FROM maintenance m
                 JOIN vehicles v ON m.vehicle_id = v.id
                 JOIN models mo ON v.model_id = mo.id
                 JOIN brands b ON mo.brand_id = b.id
                 $whereClause";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Fetch logs
    $sql = "SELECT m.*, v.license_plate, v.year, v.color, mo.name as model_name, b.name as brand_name
            FROM maintenance m
            JOIN vehicles v ON m.vehicle_id = v.id
            JOIN models mo ON v.model_id = mo.id
            JOIN brands b ON mo.brand_id = b.id
            $whereClause
            ORDER BY m.$sort_column $db_sort_order
            LIMIT ? OFFSET ?";
            
    $stmt = $db->prepare($sql);
    $paramIndex = 1;
    foreach ($params as $value) {
        $stmt->bindValue($paramIndex++, $value);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
    $logs = [];
    $totalRecords = 0;
    $totalPages = 0;
}

$serviceTypeLabels = [
    'routine' => 'Servis Rutin',
    'oil_change' => 'Ganti Oli',
    'tire_change' => 'Ganti Ban',
    'repair' => 'Perbaikan',
    'body_paint' => 'Body & Cat',
    'detailing' => 'Detailing',
    'inspection' => 'Inspeksi',
    'other' => 'Lainnya'
];
?>

<style>
    /* ===== Consistent Master Data Page Styles ===== */
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
    .btn-add-vehicle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.15s, transform 0.1s;
        text-decoration: none;
    }
    .btn-add-vehicle:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }

    /* ===== Summary Cards Grid ===== */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .summary-icon.slate { background: #f1f5f9; color: #475569; }
    .summary-icon.amber { background: #fef3c7; color: #d97706; }
    .summary-icon.emerald { background: #dcfce7; color: #16a34a; }
    .summary-icon.blue { background: #e0f2fe; color: #0284c7; }
    .summary-value {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .summary-label {
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-top: 2px;
    }

    /* Search Card */
    .search-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
    }
    .search-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    .search-input-group {
        flex: 1;
        position: relative;
    }
    .search-input-group i {
        position: absolute;
        left: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.8rem;
        pointer-events: none;
    }
    .search-input-group input {
        width: 100%;
        padding: 9px 14px 9px 36px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .search-input-group input:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }
    .filter-select {
        width: 155px;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
    }
    .filter-select:focus {
        border-color: #94a3b8;
        background: #fff;
        outline: none;
    }
    .btn-search {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #475569;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .btn-search:hover { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }
    .btn-reset-search {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-reset-search:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

    /* Table Card */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .table-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
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
    }
    .table-card-controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .record-count {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
    }
    .limit-select {
        width: 64px;
        padding: 5px 8px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.15s;
    }
    .limit-select:focus { border-color: #94a3b8; outline: none; }

    /* Table Styling */
    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 10px 14px;
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
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tbody tr:last-child td {
        border-bottom: none;
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
    }
    .service-tag {
        display: inline-block;
        padding: 3px 8px;
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.76rem;
        font-weight: 600;
    }
    .price-text {
        font-weight: 700;
        color: #1e293b;
    }

    /* Badges matching Stok Mobil */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-in_progress { background: #fef3c7; color: #92400e; }
    .status-scheduled { background: #e0f2fe; color: #075985; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }

    /* Action Buttons */
    .action-btns {
        display: flex;
        align-items: center;
        gap: 4px;
        justify-content: flex-end;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
    .action-btn-delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    /* Pagination */
    .pagination-wrapper {
        display: flex;
        justify-content: center;
        padding: 16px 20px 20px;
    }
    .pagination-list {
        display: flex;
        align-items: center;
        gap: 4px;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .pagination-list li a,
    .pagination-list li span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        background: #fff;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        transition: all 0.15s;
    }
    .pagination-list li a:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .pagination-list li.active a,
    .pagination-list li.active span {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }
    .pagination-list li.disabled a,
    .pagination-list li.disabled span {
        color: #cbd5e1;
        pointer-events: none;
        background: #f8fafc;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { border-color: #60a5fa; background: #162032; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge,
    [data-theme="dark"] .service-tag { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .pagination-list li a,
    [data-theme="dark"] .pagination-list li span { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .pagination-list li.active a,
    [data-theme="dark"] .pagination-list li.active span { background: #3b82f6; border-color: #3b82f6; color: #fff; }
</style>

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Perawatan Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Perawatan Mobil</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <button type="button" class="btn-add-vehicle" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
            <i class="fas fa-plus"></i> Tambah Perawatan
        </button>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-tools"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['total_maintenance'] ?? 0); ?></div>
            <div class="summary-label">Total Riwayat Perawatan</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-spinner"></i>
        </div>
        <div>
            <div class="summary-value text-warning"><?php echo number_format($stats['total_in_progress'] ?? 0); ?></div>
            <div class="summary-label">Sedang Dikerjakan</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo number_format($stats['total_completed'] ?? 0); ?></div>
            <div class="summary-label">Perawatan Selesai</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="summary-value text-primary"><?php echo formatCurrency($stats['total_cost'] ?? 0); ?></div>
            <div class="summary-label">Akumulasi Biaya</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card (Identical with Stok Mobil) -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari mobil, no polisi, catatan..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="service_type" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Jenis</option>
                    <?php foreach ($serviceTypeLabels as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $service_type_filter === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Terjadwal</option>
                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>Pengerjaan</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($service_type_filter) || !empty($status_filter)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table Card (Identical with Stok Mobil) -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Rekam Perawatan Kendaraan</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Data</span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&service_type=<?php echo urlencode($service_type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="vehicles-table">
            <thead>
                <tr>
                    <th style="width: 50px;">NO</th>
                    <th>UNIT KENDARAAN</th>
                    <th>JENIS PERAWATAN</th>
                    <th>TANGGAL MULAI</th>
                    <th>ESTIMASI SELESAI</th>
                    <th>BIAYA</th>
                    <th>STATUS</th>
                    <th class="text-end" style="width: 120px;">AKSI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-tools fa-2x text-muted mb-2 d-block"></i>
                            <div class="fw-semibold">Belum ada data perawatan yang tercatat.</div>
                            <small class="text-muted">Klik tombol "Tambah Perawatan" untuk mencatat rekam baru.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = $offset + 1;
                    foreach ($logs as $log): 
                    ?>
                        <tr>
                            <td class="text-muted fw-bold"><?php echo $no++; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['brand_name'] . ' ' . $log['model_name']); ?></div>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <span class="plate-badge"><?php echo htmlspecialchars($log['license_plate'] ?: 'No Plat'); ?></span>
                                    <?php if (!empty($log['year'])): ?>
                                        <span class="text-muted small">• <?php echo $log['year']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="service-tag">
                                    <?php echo $serviceTypeLabels[$log['service_type'] ?? 'routine'] ?? ucfirst($log['service_type'] ?? 'Servis'); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($log['start_date'], 'd M Y'); ?></td>
                            <td>
                                <?php if (!empty($log['end_date'])): ?>
                                    <span><?php echo formatDate($log['end_date'], 'd M Y'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="price-text"><?php echo formatCurrency($log['cost']); ?></td>
                            <td>
                                <?php
                                $statusKey = $log['status'] ?? 'scheduled';
                                $statusClass = 'status-scheduled';
                                $statusText = 'Terjadwal';
                                switch($statusKey) {
                                    case 'scheduled': $statusClass = 'status-scheduled'; $statusText = 'Terjadwal'; break;
                                    case 'in_progress': $statusClass = 'status-in_progress'; $statusText = 'Pengerjaan'; break;
                                    case 'completed': $statusClass = 'status-completed'; $statusText = 'Selesai'; break;
                                    case 'cancelled': $statusClass = 'status-cancelled'; $statusText = 'Dibatalkan'; break;
                                }
                                ?>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="view.php?id=<?php echo $log['id']; ?>" class="action-btn action-btn-view" title="Lihat Detail">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="action-btn action-btn-edit" 
                                            onclick='openEditModal(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES, "UTF-8"); ?>)' 
                                            title="Edit Perawatan">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="action-btn action-btn-delete" 
                                            onclick="deleteMaintenance(<?php echo $log['id']; ?>)" 
                                            title="Hapus Data">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <ul class="pagination-list">
                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&service_type=<?php echo urlencode($service_type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="<?php echo $page == $i ? 'active' : ''; ?>">
                        <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&service_type=<?php echo urlencode($service_type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&service_type=<?php echo urlencode($service_type_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Add Maintenance Modal -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-title-icon">
                        <i class="fas fa-plus"></i>
                    </div>
                    <h5 class="modal-title fw-bold fs-6">Tambah Perawatan Baru</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_maintenance">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="vehicle_id" class="form-label fw-bold small text-secondary">Pilih Unit Mobil <span class="text-danger">*</span></label>
                        <select id="vehicle_id" name="vehicle_id" required>
                            <option value="">Pilih Mobil...</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>">
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' - ' . ($v['license_plate'] ?: 'No Plat')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="service_type" class="form-label fw-bold small text-secondary">Jenis Layanan Perawatan</label>
                        <select class="form-select" id="service_type" name="service_type" style="border-radius:9px;">
                            <?php foreach ($serviceTypeLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label fw-bold small text-secondary">Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>" style="border-radius:9px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label fw-bold small text-secondary">Estimasi Tanggal Selesai</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" style="border-radius:9px;">
                        </div>
                    </div>
    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="cost" class="form-label fw-bold small text-secondary">Biaya Perawatan</label>
                            <div class="input-group">
                                <span class="input-group-text" style="border-radius:9px 0 0 9px;">Rp</span>
                                <input type="text" class="form-control currency-input" id="cost" name="cost" placeholder="0" style="border-radius:0 9px 9px 0;">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label fw-bold small text-secondary">Status Pengerjaan</label>
                            <select class="form-select" id="status" name="status" style="border-radius:9px;">
                                <option value="scheduled">Terjadwal</option>
                                <option value="in_progress" selected>Sedang Dikerjakan</option>
                                <option value="completed">Selesai</option>
                                <option value="cancelled">Dibatalkan</option>
                            </select>
                        </div>
                    </div>
    
                    <div class="mb-3">
                        <label for="notes" class="form-label fw-bold small text-secondary">Catatan & Rincian Perbaikan</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Rincian suku cadang yang diganti, nama bengkel rekanan..." style="border-radius:9px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i>Simpan Perawatan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Maintenance Modal -->
<div class="modal fade" id="editMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-title-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <h5 class="modal-title fw-bold fs-6">Edit Rekam Perawatan</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_maintenance">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="mb-3">
                        <label for="edit_vehicle_id" class="form-label fw-bold small text-secondary">Pilih Unit Mobil <span class="text-danger">*</span></label>
                        <select id="edit_vehicle_id" name="vehicle_id" required>
                            <option value="">Pilih Mobil...</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>">
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' - ' . ($v['license_plate'] ?: 'No Plat')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_service_type" class="form-label fw-bold small text-secondary">Jenis Layanan Perawatan</label>
                        <select class="form-select" id="edit_service_type" name="service_type" style="border-radius:9px;">
                            <?php foreach ($serviceTypeLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_start_date" class="form-label fw-bold small text-secondary">Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date" required style="border-radius:9px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_end_date" class="form-label fw-bold small text-secondary">Estimasi Tanggal Selesai</label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date" style="border-radius:9px;">
                        </div>
                    </div>
    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_cost" class="form-label fw-bold small text-secondary">Biaya Perawatan</label>
                            <div class="input-group">
                                <span class="input-group-text" style="border-radius:9px 0 0 9px;">Rp</span>
                                <input type="text" class="form-control currency-input" id="edit_cost" name="cost" placeholder="0" style="border-radius:0 9px 9px 0;">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_status" class="form-label fw-bold small text-secondary">Status Pengerjaan</label>
                            <select class="form-select" id="edit_status" name="status" style="border-radius:9px;">
                                <option value="scheduled">Terjadwal</option>
                                <option value="in_progress">Sedang Dikerjakan</option>
                                <option value="completed">Selesai</option>
                                <option value="cancelled">Dibatalkan</option>
                            </select>
                        </div>
                    </div>
    
                    <div class="mb-3">
                        <label for="edit_notes" class="form-label fw-bold small text-secondary">Catatan & Rincian Perbaikan</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="3" style="border-radius:9px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Choices.js JS -->
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
var vehicleChoices = null;
var editVehicleChoices = null;

function deleteMaintenance(id) {
    if (confirm('Apakah Anda yakin ingin menghapus data rekam perawatan ini?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        
        form.appendChild(idInput);
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    }
}

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_service_type').value = data.service_type || 'routine';
    document.getElementById('edit_start_date').value = data.start_date;
    document.getElementById('edit_end_date').value = data.end_date || '';
    document.getElementById('edit_notes').value = data.notes || data.description || '';
    document.getElementById('edit_status').value = data.status;
    
    const cost = data.cost || 0;
    document.getElementById('edit_cost').value = new Intl.NumberFormat('id-ID').format(parseInt(cost));
    
    if (editVehicleChoices) {
        editVehicleChoices.setChoiceByValue(data.vehicle_id.toString());
    } else {
        document.getElementById('edit_vehicle_id').value = data.vehicle_id;
    }
    
    var modal = new bootstrap.Modal(document.getElementById('editMaintenanceModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('vehicle_id')) {
        vehicleChoices = new Choices('#vehicle_id', {
            searchEnabled: true,
            searchPlaceholderValue: 'Cari mobil...',
            noResultsText: 'Mobil tidak ditemukan',
            itemSelectText: '',
            shouldSort: false,
            removeItemButton: false
        });
    }
    
    if (document.getElementById('edit_vehicle_id')) {
        editVehicleChoices = new Choices('#edit_vehicle_id', {
            searchEnabled: true,
            searchPlaceholderValue: 'Cari mobil...',
            noResultsText: 'Mobil tidak ditemukan',
            itemSelectText: '',
            shouldSort: false,
            removeItemButton: false
        });
    }

    // Currency formatting
    document.querySelectorAll('.currency-input').forEach(function(input) {
        input.addEventListener('input', function(e) {
            let val = e.target.value.replace(/[^\d]/g, '');
            if (val) {
                e.target.value = new Intl.NumberFormat('id-ID').format(val);
            } else {
                e.target.value = '';
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>
