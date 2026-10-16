<?php
// transactions/delete.php
// Use shared header to initialize session, helpers and DB ($db)
include_once __DIR__ . '/../includes/header.php';
requireLogin();

// Only staff or admin can delete transactions
requireStaffOrAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode request tidak valid', 'danger');
    redirect('transactions/');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('transactions/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID transaksi tidak valid', 'danger');
    redirect('transactions/');
}

try {
    $db->beginTransaction();
    
    // Get transaction data
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        throw new Exception('Transaksi tidak ditemukan');
    }
    
    // Reset vehicle status
    if (!empty($transaction['vehicle_id'])) {
        $stmt = $db->prepare("UPDATE vehicles SET status = 'available', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$transaction['vehicle_id']]);
    }
    
    // Delete transaction
    $stmt = $db->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    
    $db->commit();
    
    showAlert("Transaksi '{$transaction['transaction_code']}' berhasil dihapus. Status mobil dikembalikan menjadi tersedia.", 'success');
    
} catch (Exception $e) {
    $db->rollBack();
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('transactions/');
?>