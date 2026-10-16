<?php
// vehicles/delete.php
require_once '../config/config.php';
// Load shared helpers (requireLogin, verifyCSRFToken, showAlert, redirect, role helpers)
require_once __DIR__ . '/../includes/header.php';
requireLogin();

// Allow staff and admin to delete vehicles
requireStaffOrAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode request tidak valid', 'danger');
    redirect('vehicles/');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('vehicles/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID mobil tidak valid', 'danger');
    redirect('vehicles/');
}

try {
    // Get vehicle data
    $stmt = $db->prepare("
        SELECT v.*, m.name as model_name, b.name as brand_name
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        WHERE v.id = ?
    ");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        showAlert('Mobil tidak ditemukan', 'danger');
        redirect('vehicles/');
    }
    
    // Check if vehicle is sold
    if ($vehicle['status'] == 'sold') {
        showAlert('Tidak dapat menghapus mobil yang sudah terjual. Hapus transaksi terkait terlebih dahulu.', 'danger');
        redirect('vehicles/');
    }
    
    // Check if has transactions
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM transactions WHERE vehicle_id = ?");
    $stmt->execute([$id]);
    $transactionCount = $stmt->fetch()['total'];
    
    if ($transactionCount > 0) {
        showAlert("Tidak dapat menghapus mobil '{$vehicle['brand_name']} {$vehicle['model_name']}' karena memiliki {$transactionCount} transaksi terkait.", 'danger');
        redirect('vehicles/');
    }
    
    // Delete vehicle
    $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
    if ($stmt->execute([$id])) {
        showAlert("Mobil '{$vehicle['brand_name']} {$vehicle['model_name']}' dengan nomor polisi {$vehicle['license_plate']} berhasil dihapus", 'success');
    } else {
        showAlert('Gagal menghapus mobil', 'danger');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('vehicles/');
?>