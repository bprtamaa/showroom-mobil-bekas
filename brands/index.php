<?php
// brands/index.php
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard/');
    exit();
}
include '../includes/header.php';
requireLogin();

// Country translation mapping
// Defines for upload path if not exists
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', dirname(__DIR__) . '/uploads/');
}

// Handle Add Brand POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_brand') {
    $formData = [
        'name' => sanitizeInput($_POST['name'] ?? ''),
        'country' => sanitizeInput($_POST['country'] ?? ''),
        'status' => $_POST['status'] ?? 'active'
    ];
    
    // Validation
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF)';
    } elseif (empty($formData['name'])) {
        $error = 'Nama merek harus diisi';
    } else {
        try {
            // Check if brand name already exists
            $stmt = $db->prepare("SELECT id FROM brands WHERE name = ?");
            $stmt->execute([$formData['name']]);
            if ($stmt->fetch()) {
                $error = 'Nama merek sudah ada';
            } else {
                // Handle logo upload
                $logoFileName = '';
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = UPLOADS_PATH . 'brands/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $logoFileName = uniqid() . '.' . $fileExtension;
                        $uploadPath = $uploadDir . $logoFileName;
                        
                        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                            $error = 'Gagal mengupload logo';
                        }
                    } else {
                        $error = 'Format logo harus JPG, JPEG, PNG, atau GIF';
                    }
                }
                
                if (empty($error)) {
                    // Insert brand
                    $stmt = $db->prepare("
                        INSERT INTO brands (name, country, logo, status, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    
                    if ($stmt->execute([
                        $formData['name'],
                        $formData['country'],
                        $logoFileName,
                        $formData['status']
                    ])) {
                        showAlert('Merek berhasil ditambahkan', 'success');
                        redirect('brands/');
                    } else {
                        $error = 'Gagal menyimpan data merek';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle Edit Brand POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_brand') {
    $id = (int)($_POST['id'] ?? 0);
    $formData = [
        'name' => sanitizeInput($_POST['name'] ?? ''),
        'country' => sanitizeInput($_POST['country'] ?? ''),
        'status' => $_POST['status'] ?? 'active'
    ];

    // Validation
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF)';
    } elseif (empty($id)) {
        $error = 'ID merek tidak valid';
    } elseif (empty($formData['name'])) {
        $error = 'Nama merek harus diisi';
    } else {
        try {
            // Check if brand name already exists (excluding self)
            $stmt = $db->prepare("SELECT id FROM brands WHERE name = ? AND id != ?");
            $stmt->execute([$formData['name'], $id]);
            if ($stmt->fetch()) {
                $error = 'Nama merek sudah digunakan';
            } else {
                // Get current data for logo handling
                $stmt = $db->prepare("SELECT logo FROM brands WHERE id = ?");
                $stmt->execute([$id]);
                $currentBrand = $stmt->fetch();
                $logoFileName = $currentBrand['logo'];

                // Handle Logo Delete via Checkbox
                if (isset($_POST['delete_logo']) && $_POST['delete_logo'] == '1') {
                     $uploadDir = UPLOADS_PATH . 'brands/';
                     if ($logoFileName && file_exists($uploadDir . $logoFileName)) {
                         @unlink($uploadDir . $logoFileName);
                     }
                     $logoFileName = '';
                }

                // Handle Logo Upload
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = UPLOADS_PATH . 'brands/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        // Delete old logo if exists (and not already deleted/replaced above)
                        // Note: If we uploaded a new one, we replace the old one regardless.
                         if ($logoFileName && file_exists($uploadDir . $logoFileName) && $logoFileName !== $currentBrand['logo']) {
                            // Already deleted or changed? 
                            // Actually, just delete the old one if it's still there
                         } elseif ($logoFileName && file_exists($uploadDir . $logoFileName)) {
                             @unlink($uploadDir . $logoFileName);
                         }
                        
                        $newFileName = uniqid() . '.' . $fileExtension;
                        $uploadPath = $uploadDir . $newFileName;
                        
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                            $logoFileName = $newFileName;
                        } else {
                            $error = 'Gagal mengupload logo';
                        }
                    } else {
                        $error = 'Format logo harus JPG, JPEG, PNG, atau GIF';
                    }
                }

                if (empty($error)) {
                    $stmt = $db->prepare("
                        UPDATE brands 
                        SET name = ?, country = ?, logo = ?, status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([
                        $formData['name'],
                        $formData['country'],
                        $logoFileName,
                        $formData['status'],
                        $id
                    ])) {
                        showAlert('Merek berhasil diperbarui', 'success');
                        redirect('brands/');
                    } else {
                        $error = 'Gagal memperbarui data merek';
                    }
                }
            }
        } catch (PDOException $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$countryTranslations = [
    'Japan' => 'Jepang',
    'South Korea' => 'Korea Selatan',
    'Germany' => 'Jerman',
    'USA' => 'Amerika Serikat',
    'United States' => 'Amerika Serikat',
    'Italy' => 'Italia',
    'France' => 'Perancis',
    'United Kingdom' => 'Inggris',
    'UK' => 'Inggris',
    'China' => 'Tiongkok',
    'India' => 'India',
    'Malaysia' => 'Malaysia',
    'Thailand' => 'Thailand',
    'Sweden' => 'Swedia',
    'Indonesia' => 'Indonesia',
    'Netherlands' => 'Belanda',
    'Spain' => 'Spanyol',
    'Brazil' => 'Brasil',
    'Canada' => 'Kanada',
    'Australia' => 'Australia'
];

// Function to translate country name
function translateCountry($country, $translations) {
    if (empty($country)) {
        return '-';
    }
    // Check if translation exists
    if (isset($translations[$country])) {
        return $translations[$country];
    }
    // Return original if no translation found
    return $country;
}

// Function to find English country names from Indonesian search term
function findEnglishCountries($searchTerm, $translations) {
    $englishCountries = [];
    $searchTermLower = strtolower(trim($searchTerm));
    
    foreach ($translations as $english => $indonesian) {
        if (strtolower($indonesian) === $searchTermLower) {
            $englishCountries[] = $english;
        }
    }
    
    return $englishCountries;
}


// Handle search, pagination, and sorting
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';

// Custom sorting logic for "No" column
// Request: "urutan menaik itu huruf pertama alphabet, kalo urutan menurun sebaliknya"
// This means we should sort by NAME, not ID or Created At for the default "No" column sort controls
if ($sort_column === 'created_at') {
    // Override created_at to name for "No" column if that's what was linked
    $sort_column = 'name';
}

$allowed_sort_cols = ['name', 'id', 'created_at', 'country', 'status'];

if (!in_array($sort_column, $allowed_sort_cols)) {
    $sort_column = 'name';
}
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

try {
    // Build query
    $whereClause = '';
    $params = [];
    
    if (!empty($search)) {
        // Find English country names that match Indonesian search term
        $englishCountries = findEnglishCountries($search, $countryTranslations);
        
        // Build WHERE clause for brand name search
        $whereClause = "WHERE name LIKE ?";
        $params = ["%$search%"];
        
        // Add country search conditions
        if (!empty($englishCountries)) {
            // If we found matching English countries, include them in search
            $countryPlaceholders = str_repeat('?,', count($englishCountries) - 1) . '?';
            $whereClause .= " OR country IN ($countryPlaceholders)";
            $params = array_merge($params, $englishCountries);
        } else {
            // If no Indonesian match, also search by English country name
            $whereClause .= " OR country LIKE ?";
            $params[] = "%$search%";
        }
    }
    
    // Get total records for pagination
    $countSql = "SELECT COUNT(*) as total FROM brands $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $countRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRecords = $countRow['total'] ?? 0;
    $totalPages = ceil($totalRecords / $limit);
    
    // Get brands data
    $sql = "SELECT * FROM brands $whereClause ORDER BY $sort_column $sort_order LIMIT $limit OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    // Ensure we fetch associative arrays so keys like 'name', 'country' exist
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error loading brands: " . $e->getMessage();
}
?>

<style>
    /* ===== Brands Page Specific Styles ===== */
    .brands-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .brands-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .brands-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .btn-add-brand {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s, transform 0.15s;
        white-space: nowrap;
    }
    .btn-add-brand:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-add-brand i { font-size: 0.75rem; }

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
        transform: none;
    }
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
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
        transform: none;
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
    .brands-table {
        width: 100%;
        border-collapse: collapse;
    }
    .brands-table thead th {
        padding: 10px 16px;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .brands-table tbody td {
        padding: 12px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .brands-table tbody tr {
        transition: background 0.15s;
    }
    .brands-table tbody tr:hover {
        background: #f8fafc;
    }
    .brands-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Brand Name Cell */
    .brand-name-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .brand-logo-thumb {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 3px;
        object-fit: contain;
        flex-shrink: 0;
    }
    .brand-logo-placeholder {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .brand-logo-placeholder i { font-size: 0.7rem; color: #cbd5e1; }
    .brand-name-text { font-weight: 700; color: #1e293b; }

    /* Sort Controls */
    .sort-controls {
        display: inline-flex;
        flex-direction: column;
        margin-left: 6px;
        gap: 0;
        vertical-align: middle;
    }
    .sort-controls a {
        display: block;
        line-height: 1;
        font-size: 0.65rem;
        color: #cbd5e1;
        transition: color 0.15s;
    }
    .sort-controls a:hover { color: #64748b; }
    .sort-controls a.active-sort { color: #2d3436; }

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }

    /* Date Text */
    .date-text { font-size: 0.78rem; color: #94a3b8; }

    /* No Column */
    .col-no { width: 52px; text-align: center; }
    .col-no-val { color: #94a3b8; font-weight: 500; font-size: 0.8rem; text-align: center; }

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
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #dbeafe; border-color: #93c5fd; color: #2563eb; }
    .action-btn-edit:hover { background: #e0e7ff; border-color: #a5b4fc; color: #4f46e5; }
    .action-btn-delete:hover { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }

    /* Col visibility */
    .col-hide-sm { display: none; }
    .col-hide-md { display: none; }

    @media (min-width: 768px) { .col-hide-md { display: table-cell; } }
    @media (min-width: 1024px) { .col-hide-sm { display: table-cell; } }

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

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 48px 24px;
    }
    .empty-state-icon {
        font-size: 2.5rem;
        color: #e2e8f0;
        margin-bottom: 12px;
    }
    .empty-state h5 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 4px;
    }
    .empty-state p {
        font-size: 0.82rem;
        color: #94a3b8;
        margin-bottom: 16px;
    }

    /* Modal Improvements */
    .modal-custom .modal-content {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .modal-custom .modal-header {
        padding: 18px 22px 14px;
        border-bottom: none;
    }
    .modal-custom .modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }
    .modal-custom .modal-title i { color: #64748b; }
    .modal-custom .modal-body {
        padding: 8px 22px 22px;
    }
    .modal-custom .modal-footer {
        padding: 14px 22px;
        background: #fafbfc;
        border-top: 1px solid #f1f5f9;
    }
    .form-label-sm {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 5px;
    }
    .form-hint {
        font-size: 0.72rem;
        color: #94a3b8;
        margin-top: 3px;
    }
    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 20px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-save:hover { background: #1e272e; color: #fff; }
    .btn-cancel {
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-cancel:hover { background: #f1f5f9; color: #334155; }
    .logo-preview-box {
        display: none;
        margin-top: 8px;
        text-align: center;
        padding: 10px;
        background: #f8fafc;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
    }
    .logo-preview-box img { max-height: 80px; border-radius: 6px; }
    .current-logo-row {
        display: none;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        background: #f8fafc;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        margin-bottom: 10px;
    }
    .current-logo-row img {
        width: 44px;
        height: 44px;
        object-fit: contain;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        padding: 3px;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .brands-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-brand { background: #3b82f6; }
    [data-theme="dark"] .btn-add-brand:hover { background: #2563eb; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .brands-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .brands-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .brands-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .brand-name-text { color: #f1f5f9; }
    [data-theme="dark"] .brand-logo-thumb { border-color: #334155; background: #0f172a; }
    [data-theme="dark"] .brand-logo-placeholder { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .brand-logo-placeholder i { color: #475569; }
    [data-theme="dark"] .status-active { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-inactive { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #60a5fa; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.3); color: #a5b4fc; }
    [data-theme="dark"] .action-btn-delete:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
    [data-theme="dark"] .pagination-list li a,
    [data-theme="dark"] .pagination-list li span { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .pagination-list li a:hover { background: #334155; }
    [data-theme="dark"] .pagination-list li.active a { background: #3b82f6; border-color: #3b82f6; }
    [data-theme="dark"] .pagination-list li.disabled a { background: #0f172a; border-color: #1e293b; color: #475569; }
    [data-theme="dark"] .sort-controls a.active-sort { color: #60a5fa; }
    [data-theme="dark"] .col-no-val { color: #64748b; }
    [data-theme="dark"] .empty-state-icon { color: #334155; }
    [data-theme="dark"] .empty-state h5 { color: #e2e8f0; }
    [data-theme="dark"] .empty-state p { color: #94a3b8; }
    [data-theme="dark"] .modal-custom .modal-title { color: #f1f5f9; }
    [data-theme="dark"] .modal-custom .modal-title i { color: #94a3b8; }
    [data-theme="dark"] .modal-custom .modal-footer { background: #162032; border-top-color: #334155; }
    [data-theme="dark"] .form-label-sm { color: #cbd5e1; }
    [data-theme="dark"] .form-hint { color: #64748b; }
    [data-theme="dark"] .btn-save { background: #3b82f6; }
    [data-theme="dark"] .btn-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .logo-preview-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .current-logo-row { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .current-logo-row img { border-color: #334155; background: #1e293b; }
    [data-theme="dark"] .record-count { background-color: rgba(59,130,246,0.15) !important; color: #93c5fd !important; }

    /* Responsive */
    @media (max-width: 576px) {
        .brands-page-header {
            flex-direction: column;
            gap: 10px;
        }
        .btn-add-brand { width: 100%; justify-content: center; }
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .brands-table thead th,
        .brands-table tbody td { padding: 10px 10px; }
        .action-btn { width: 28px; height: 28px; font-size: 0.68rem; }
    }
</style>

<!-- Page Header -->
<div class="brands-page-header">
    <div>
        <h1>Merek Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Merek Mobil</li>
            </ol>
        </nav>
    </div>
    <button type="button" class="btn-add-brand" data-bs-toggle="modal" data-bs-target="#addBrandModal">
        <i class="fas fa-plus"></i> Tambah Merek
    </button>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Search -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari merek atau negara..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search)): ?>
                <a href="?" class="btn-reset-search" title="Reset pencarian">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Brands Table -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Merek Mobil</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Merek</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($brands)): ?>
        <div class="table-responsive">
            <table class="brands-table">
                <thead>
                    <tr>
                        <th class="col-no">
                            <span>No</span>
                            <span class="sort-controls">
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=name&order=ASC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'ASC') ? 'active-sort' : ''; ?>"
                                   title="A-Z"><i class="fas fa-caret-up"></i></a>
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=name&order=DESC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'DESC') ? 'active-sort' : ''; ?>"
                                   title="Z-A"><i class="fas fa-caret-down"></i></a>
                            </span>
                        </th>
                        <th>Nama Merek</th>
                        <th class="col-hide-md">Negara</th>
                        <th>Status</th>
                        <th class="col-hide-sm">Dibuat</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brands as $index => $brand): ?>
                    <tr>
                        <td class="col-no-val"><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <div class="brand-name-cell">
                                <?php if ($brand['logo']): ?>
                                <img src="<?php echo UPLOADS_URL . 'brands/' . $brand['logo']; ?>" alt="Logo" class="brand-logo-thumb">
                                <?php else: ?>
                                <div class="brand-logo-placeholder">
                                    <i class="fas fa-image"></i>
                                </div>
                                <?php endif; ?>
                                <span class="brand-name-text"><?php echo htmlspecialchars($brand['name']); ?></span>
                            </div>
                        </td>
                        <td class="col-hide-md" style="color:#64748b"><?php echo htmlspecialchars(translateCountry($brand['country'], $countryTranslations)); ?></td>
                        <td>
                            <?php
                            $bstatus = strtolower((string)($brand['status'] ?? ''));
                            $statusLabel = $bstatus === 'active' ? 'Aktif' : ($bstatus === 'inactive' ? 'Tidak Aktif' : ucfirst($brand['status']));
                            $statusClass = $bstatus === 'active' ? 'status-active' : 'status-inactive';
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </td>
                        <td class="col-hide-sm">
                            <span class="date-text"><?php echo formatDate($brand['created_at']); ?></span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $brand['id']; ?>" class="action-btn action-btn-view" data-bs-toggle="tooltip" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-edit" 
                                        onclick='openEditModal(<?php echo htmlspecialchars(json_encode($brand), ENT_QUOTES, "UTF-8"); ?>)'
                                        data-bs-toggle="tooltip" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="action-btn action-btn-delete" 
                                        onclick="deleteBrand(<?php echo $brand['id']; ?>)" 
                                        data-bs-toggle="tooltip" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <ul class="pagination-list">
                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-tags"></i></div>
            <h5><?php echo !empty($search) ? 'Merek Tidak Ditemukan' : 'Tidak ada data merek'; ?></h5>
            <p>
                <?php echo !empty($search) 
                    ? 'Tidak ada merek yang cocok dengan pencarian "<strong>' . htmlspecialchars($search) . '</strong>".' 
                    : 'Belum ada merek yang ditambahkan ke sistem.'; ?>
            </p>
            <?php if (!empty($search)): ?>
            <a href="?" class="btn-cancel">
                <i class="fas fa-redo-alt me-1"></i>Reset Pencarian
            </a>
            <?php else: ?>
            <button type="button" class="btn-add-brand" data-bs-toggle="modal" data-bs-target="#addBrandModal">
                <i class="fas fa-plus"></i> Tambah Merek Pertama
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function deleteBrand(id) {
    if (confirmDelete('Apakah Anda yakin ingin menghapus merek ini? Semua model dan mobil terkait juga akan terhapus.')) {
        // Create form and submit
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

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<!-- Add Brand Modal -->
<div class="modal fade modal-custom" id="addBrandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Merek Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="addBrandForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_brand">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label form-label-sm">Nama Merek <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" placeholder="Masukkan nama merek" required>
                        <div class="form-hint">Nama merek harus unik</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="country" class="form-label form-label-sm">Negara Asal</label>
                        <select class="form-select" id="country" name="country">
                            <option value="">-- Pilih Negara --</option>
                            <?php 
                            // Use array_unique to prevent duplicate display names (e.g. USA/United States)
                            $uniqueCountries = array_unique($countryTranslations);
                            foreach ($uniqueCountries as $code => $name): 
                            ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="logo" class="form-label form-label-sm">Logo Merek</label>
                        <input type="file" class="form-control" id="logo" name="logo" accept="image/*">
                        <div class="form-hint">Format: JPG, PNG, GIF. Maks 2MB</div>
                        <div class="logo-preview-box" id="logoPreviewContainer">
                            <img id="logoPreview" src="#" alt="Preview">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label form-label-sm">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" selected>Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Merek
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Global variables for Choices instances
let editCountryChoices;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Add Modal Country Select
    const countrySelect = document.getElementById('country');
    if (countrySelect) {
        new Choices(countrySelect, {
            itemSelectText: '',
            searchEnabled: true,
            shouldSort: false,
            position: 'bottom' // Force dropdown to open downwards
        });
    }

    // Initialize Edit Modal Country Select
    const editCountrySelect = document.getElementById('edit_country');
    if (editCountrySelect) {
        editCountryChoices = new Choices(editCountrySelect, {
            itemSelectText: '',
            searchEnabled: true,
            shouldSort: false,
            position: 'bottom' // Force dropdown to open downwards
        });
    }
});

// Modal Logo Preview
document.getElementById('logo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const container = document.getElementById('logoPreviewContainer');
    const preview = document.getElementById('logoPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        container.style.display = 'none';
    }
});

// Edit Modal functionality
function openEditModal(brand) {
    const modal = document.getElementById('editBrandModal');
    const form = document.getElementById('editBrandForm');
    
    // Set form values
    form.querySelector('[name="id"]').value = brand.id;
    form.querySelector('[name="name"]').value = brand.name;
    
    // Set country using Choices.js if available
    if (editCountryChoices) {
        editCountryChoices.setChoiceByValue(brand.country);
    } else {
        form.querySelector('[name="country"]').value = brand.country;
    }

    form.querySelector('[name="status"]').value = brand.status;
    
    // Handle logo preview
    const currentLogoContainer = document.getElementById('currentLogoContainer');
    const currentLogo = document.getElementById('currentLogo');
    
    if (brand.logo) {
        currentLogo.src = '<?php echo UPLOADS_URL . "brands/"; ?>' + brand.logo;
        currentLogoContainer.style.display = 'flex';
    } else {
        currentLogoContainer.style.display = 'none';
    }
    
    // Reset file input and delete checkbox
    form.querySelector('[name="logo"]').value = '';
    document.getElementById('editLogoPreviewContainer').style.display = 'none';
    document.getElementById('delete_logo').checked = false;
    
    // Open modal
    new bootstrap.Modal(modal).show();
}

// Edit Modal Logo Preview
document.getElementById('edit_logo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const container = document.getElementById('editLogoPreviewContainer');
    const preview = document.getElementById('editLogoPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        container.style.display = 'none';
    }
});
</script>

<!-- Edit Brand Modal -->
<div class="modal fade modal-custom" id="editBrandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Merek</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editBrandForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_brand">
                    <input type="hidden" name="id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label form-label-sm">Nama Merek <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_country" class="form-label form-label-sm">Negara Asal</label>
                        <select class="form-select" id="edit_country" name="country">
                            <option value="">-- Pilih Negara --</option>
                            <?php 
                            foreach ($uniqueCountries as $code => $name): 
                            ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Logo Saat Ini</label>
                        <div id="currentLogoContainer" class="current-logo-row">
                            <img id="currentLogo" src="#" alt="Current Logo">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="delete_logo" name="delete_logo" value="1">
                                <label class="form-check-label text-danger" for="delete_logo" style="font-size:0.8rem">
                                    Hapus logo ini
                                </label>
                            </div>
                        </div>
                        
                        <label for="edit_logo" class="form-label form-label-sm">Ganti Logo</label>
                        <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                        <div class="form-hint">Format: JPG, PNG, GIF. Maks 2MB</div>
                        <div class="logo-preview-box" id="editLogoPreviewContainer">
                            <img id="editLogoPreview" src="#" alt="Preview">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label form-label-sm">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
