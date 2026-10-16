<?php
// models/delete.php
$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();

// Only staff or admin may delete models
requireStaffOrAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode request tidak valid', 'danger');
    redirect('models/');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('models/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID model tidak valid', 'danger');
    redirect('models/');
}

try {
    // Get model data
    $stmt = $db->prepare("
        SELECT m.*, b.name as brand_name
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    $model = $stmt->fetch();
    
    if (!$model) {
        showAlert('Model tidak ditemukan', 'danger');
        redirect('models/');
    }
    
    // Check if has vehicles
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM vehicles WHERE model_id = ?");
    $stmt->execute([$id]);
    $vehicleCount = $stmt->fetch()['total'];
    
    if ($vehicleCount > 0) {
        showAlert("Tidak dapat menghapus model '{$model['brand_name']} {$model['name']}' karena masih memiliki {$vehicleCount} mobil terkait. Hapus semua mobil terlebih dahulu.", 'danger');
        redirect('models/');
    }
    
    // Delete model
    $stmt = $db->prepare("DELETE FROM models WHERE id = ?");
    if ($stmt->execute([$id])) {
        showAlert("Model '{$model['brand_name']} {$model['name']}' berhasil dihapus", 'success');
    } else {
        showAlert('Gagal menghapus model', 'danger');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('models/');
?>