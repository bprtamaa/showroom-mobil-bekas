<?php
// database/migrate_v3_expenses_and_aging.php
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Expenses Table for Petty Cash and Overhead Operational Costs
    $sql1 = "
    CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_code VARCHAR(50) NOT NULL UNIQUE,
        expense_date DATE NOT NULL,
        category ENUM(
            'listrik_air',
            'bensin_operasional',
            'cuci_mobil',
            'konsumsi',
            'sewa_tempat',
            'gaji_karyawan',
            'marketing_iklan',
            'perawatan_gedung',
            'pajak_legalitas',
            'lainnya'
        ) NOT NULL DEFAULT 'lainnya',
        title VARCHAR(255) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        payment_method ENUM('cash', 'bank_transfer', 'petty_cash') NOT NULL DEFAULT 'cash',
        receipt_file VARCHAR(255) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_exp_date (expense_date),
        INDEX idx_exp_cat (category),
        INDEX idx_exp_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->exec($sql1);
    echo "Table 'expenses' created or verified.\n";

    // 2. Check if we need to insert sample expenses data if table is empty
    $chkStmt = $db->query("SELECT COUNT(*) FROM expenses");
    $expCount = (int)$chkStmt->fetchColumn();

    if ($expCount === 0) {
        $curYear = date('Y');
        $curMonth = date('m');
        
        $samples = [
            // Current month samples
            ["EXP-{$curYear}{$curMonth}-001", date('Y-m-01'), 'sewa_tempat', 'Sewa Lahan & Ruko Showroom', 7500000.00, 'bank_transfer', 'Pembayaran sewa bulanan ruko'],
            ["EXP-{$curYear}{$curMonth}-002", date('Y-m-03'), 'listrik_air', 'Tagihan Listrik PLN & Air PDAM', 1850000.00, 'bank_transfer', 'Tagihan rutin utilitas showroom'],
            ["EXP-{$curYear}{$curMonth}-003", date('Y-m-05'), 'cuci_mobil', 'Biaya Cuci Rutin & Snow Wash Unit Stok', 850000.00, 'cash', 'Cuci 17 unit mobil di display'],
            ["EXP-{$curYear}{$curMonth}-004", date('Y-m-08'), 'bensin_operasional', 'BBM Operasional & Test Drive', 600000.00, 'petty_cash', 'Pembelian bensin unit operasional'],
            ["EXP-{$curYear}{$curMonth}-005", date('Y-m-10'), 'marketing_iklan', 'Iklan Berbayar FB & Instagram Ads', 1250000.00, 'bank_transfer', 'Promosi listing mobil unggulan'],
            ["EXP-{$curYear}{$curMonth}-006", date('Y-m-12'), 'konsumsi', 'Konsumsi Tamu, Kopi & Snack Showroom', 450000.00, 'petty_cash', 'Belanja bulanan pantry showroom'],
            ["EXP-{$curYear}{$curMonth}-007", date('Y-m-15'), 'gaji_karyawan', 'Gaji Pokok Karyawan & Staff Showroom', 12000000.00, 'bank_transfer', 'Gaji 3 orang staff showroom']
        ];

        // Also add historical data for the previous 2 months if needed for realistic charts
        $prev1 = date('Y-m', strtotime('-1 month'));
        $prev1Y = date('Y', strtotime('-1 month'));
        $prev1M = date('m', strtotime('-1 month'));
        $samples[] = ["EXP-{$prev1Y}{$prev1M}-001", "{$prev1}-01", 'sewa_tempat', 'Sewa Lahan & Ruko Showroom', 7500000.00, 'bank_transfer', 'Sewa ruko bulan lalu'];
        $samples[] = ["EXP-{$prev1Y}{$prev1M}-002", "{$prev1}-05", 'listrik_air', 'Tagihan Listrik PLN & Air PDAM', 1720000.00, 'bank_transfer', 'Tagihan utilitas'];
        $samples[] = ["EXP-{$prev1Y}{$prev1M}-003", "{$prev1}-10", 'gaji_karyawan', 'Gaji Pokok Karyawan & Staff Showroom', 12000000.00, 'bank_transfer', 'Gaji staff'];
        $samples[] = ["EXP-{$prev1Y}{$prev1M}-004", "{$prev1}-15", 'marketing_iklan', 'Iklan Ads & Listing Marketplace', 1100000.00, 'bank_transfer', 'Iklan'];
        $samples[] = ["EXP-{$prev1Y}{$prev1M}-005", "{$prev1}-20", 'cuci_mobil', 'Biaya Cuci Rutin Unit Display', 750000.00, 'cash', 'Cuci mobil rutin'];

        $insStmt = $db->prepare("
            INSERT INTO expenses (expense_code, expense_date, category, title, amount, payment_method, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($samples as $s) {
            $insStmt->execute($s);
        }
        echo "Sample expenses data inserted successfully.\n";
    }

    echo "Migration v3 completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration v3 Error: " . $e->getMessage() . "\n";
}
