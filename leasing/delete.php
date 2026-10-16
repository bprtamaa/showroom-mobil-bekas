<?php
// leasing_companies/delete.php
$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();

// Only admin can delete leasing companies
requireAdmin();

// Get leasing company ID
$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid', 'danger');
        redirect('leasing/');
    }
    $id = (int)($_POST['id'] ?? 0);
} else {
    $id = (int)($_GET['id'] ?? 0);
}

if (!$id) {
    showAlert('ID perusahaan leasing tidak valid', 'danger');
    redirect('leasing/');
}

try {
    // Check if leasing company exists
    $stmt = $db->prepare("SELECT name FROM leasing WHERE id = ?");
    $stmt->execute([$id]);
    $leasing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$leasing) {
        showAlert('Perusahaan leasing tidak ditemukan', 'danger');
        redirect('leasing/');
    }
    
    // Check if leasing company is used in transactions
    // First check if column exists
    $colExists = false;
    try {
        $colStmt = $db->prepare("SHOW COLUMNS FROM transactions LIKE 'leasing_company_id'");
        $colStmt->execute();
        $colExists = $colStmt->fetch() !== false;
    } catch (Exception $e) {
        // Column might not exist, ignore
    }
    
    if ($colExists) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE leasing_company_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            showAlert("Tidak dapat menghapus perusahaan leasing '{$leasing['name']}' karena masih digunakan dalam {$result['count']} transaksi!", 'danger');
            redirect('leasing/');
        }
    }
    
    // Delete leasing company
    $stmt = $db->prepare("DELETE FROM leasing WHERE id = ?");
    if ($stmt->execute([$id])) {
        showAlert("Perusahaan leasing '{$leasing['name']}' berhasil dihapus!", 'success');
    } else {
        showAlert('Gagal menghapus perusahaan leasing', 'danger');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('leasing/');
?>
