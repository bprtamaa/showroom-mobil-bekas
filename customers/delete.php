<?php
// customers/delete.php - Hapus Pelanggan
require_once '../includes/header.php';
require_once '../config/config.php';
requireLogin();

// Allow staff and admin to delete customers
requireStaffOrAdmin();

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode request tidak valid', 'danger');
    redirect('customers/');
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('customers/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID pelanggan tidak valid', 'danger');
    redirect('customers/');
}

try {
    // Get customer data
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        showAlert('Pelanggan tidak ditemukan', 'danger');
        redirect('customers/');
    }
    
    // Check if customer has transactions
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM transactions WHERE customer_id = ?");
    $stmt->execute([$id]);
    $transactionCount = $stmt->fetch()['total'];
    
    if ($transactionCount > 0) {
        showAlert("Tidak dapat menghapus pelanggan '{$customer['name']}' karena memiliki {$transactionCount} transaksi terkait. Hapus transaksi terlebih dahulu atau biarkan data pelanggan tetap ada.", 'danger');
        redirect('customers/');
    }
    
    // Delete customer
    $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
    
    if ($stmt->execute([$id])) {
        showAlert("Pelanggan '{$customer['name']}' berhasil dihapus dari sistem", 'success');
    } else {
        showAlert('Gagal menghapus pelanggan. Silakan coba lagi.', 'danger');
    }
    
} catch (PDOException $e) {
    // Log error for debugging (optional)
    error_log("Delete Customer Error: " . $e->getMessage());
    
    showAlert('Terjadi kesalahan sistem. Silakan hubungi administrator.', 'danger');
}

redirect('customers/');
?>