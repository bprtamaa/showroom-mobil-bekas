<?php
// expenses/delete.php - Hapus Catatan Pengeluaran Kas
require_once '../config/config.php';
require_once '../includes/header.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid. Silakan coba lagi.', 'danger');
        redirect('expenses/');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        showAlert('ID pengeluaran tidak valid.', 'danger');
        redirect('expenses/');
    }

    try {
        // Fetch receipt file to unlink
        $stmt = $db->prepare("SELECT receipt_file FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();

        if (!empty($file)) {
            $filePath = __DIR__ . '/../uploads/expenses/' . $file;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $delStmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
        $delStmt->execute([$id]);

        showAlert('Catatan pengeluaran berhasil dihapus.', 'success');
    } catch (PDOException $e) {
        showAlert('Gagal menghapus pengeluaran: ' . $e->getMessage(), 'danger');
    }
}

redirect('expenses/');
