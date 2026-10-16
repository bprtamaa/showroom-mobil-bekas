<?php
// maintenance/delete.php
// Use shared header to initialize session, helpers and DB ($db)
include_once __DIR__ . '/../includes/header.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token validasi tidak valid.', 'danger');
    } else {
        $id = (int)$_POST['id'];
        
        try {
            // Get vehicle ID before deleting
            $stmt = $db->prepare("SELECT vehicle_id, status FROM maintenance WHERE id = ?");
            $stmt->execute([$id]);
            $log = $stmt->fetch();
            
            if ($log) {
                $vehicle_id = $log['vehicle_id'];
                
                // Delete log
                $del = $db->prepare("DELETE FROM maintenance WHERE id = ?");
                $del->execute([$id]);
                
                // Check if vehicle is currently in maintenance status
                $checkVehicle = $db->prepare("SELECT status FROM vehicles WHERE id = ?");
                $checkVehicle->execute([$vehicle_id]);
                $vehicleStatus = $checkVehicle->fetchColumn();
                
                // If vehicle is in maintenance status, check if there are any other active maintenance records
                if ($vehicleStatus === 'maintenance') {
                    // Check for other active maintenance records (in_progress or scheduled)
                    $checkOtherMaintenance = $db->prepare(
                        "SELECT COUNT(*) FROM maintenance 
                         WHERE vehicle_id = ? 
                         AND status IN ('in_progress', 'scheduled')"
                    );
                    $checkOtherMaintenance->execute([$vehicle_id]);
                    $otherMaintenanceCount = $checkOtherMaintenance->fetchColumn();
                    
                    // If no other active maintenance, set vehicle status back to available
                    if ($otherMaintenanceCount == 0) {
                        $updateVehicle = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
                        $updateVehicle->execute([$vehicle_id]);
                    }
                }
                
                showAlert('Data perawatan berhasil dihapus.', 'success');
            } else {
                 showAlert('Data tidak ditemukan.', 'danger');
            }
        } catch (PDOException $e) {
            showAlert('Gagal menghapus data: ' . $e->getMessage(), 'danger');
        }
    }
}

redirect('maintenance/index.php');
?>
