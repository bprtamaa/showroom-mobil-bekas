<?php
// transactions/update_status.php
session_start();
require_once '../config/database.php';

// Check login
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Check CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid CSRF token';
    header('Location: ./');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$newStatus = $_POST['payment_status'] ?? '';

if (!$id || !in_array($newStatus, ['pending', 'paid', 'installment'])) {
    $_SESSION['error'] = 'Data tidak valid';
    header('Location: view.php?id=' . $id);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Get current transaction
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        $_SESSION['error'] = 'Transaksi tidak ditemukan';
        header('Location: ./');
        exit;
    }
    
    $oldStatus = $transaction['payment_status'];
    
    // Start transaction
    $conn->beginTransaction();
    
    // Update payment status and clear leasing_company_id if changing from installment to paid/pending
    if ($oldStatus === 'installment' && ($newStatus === 'paid' || $newStatus === 'pending')) {
        // Clear leasing company when changing from installment to paid/pending
        $stmt = $conn->prepare("UPDATE transactions SET payment_status = ?, leasing_company_id = NULL WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
    } else {
        // Normal status update
        $stmt = $conn->prepare("UPDATE transactions SET payment_status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
    }
    
    // Update vehicle status based on new payment status
    $vehicleId = $transaction['vehicle_id'];
    
    if ($newStatus === 'paid') {
        // Only mark vehicle as sold when payment is FULLY PAID
        $stmt = $conn->prepare("UPDATE vehicles SET status = 'sold' WHERE id = ?");
        $stmt->execute([$vehicleId]);
    } else {
        // If pending or installment, keep vehicle as available
        $stmt = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
        $stmt->execute([$vehicleId]);
    }
    
    $conn->commit();
    
    // Success message
    $statusLabels = [
        'pending' => 'Pending',
        'paid' => 'Lunas',
        'installment' => 'Cicilan'
    ];
    
    $_SESSION['success'] = 'Status pembayaran berhasil diubah menjadi: ' . $statusLabels[$newStatus];
    header('Location: view.php?id=' . $id);
    exit;
    
} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    $_SESSION['error'] = 'Error: ' . $e->getMessage();
    header('Location: view.php?id=' . $id);
    exit;
}
