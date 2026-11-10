<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "config.php";
require_once "cek_session.php";
cek_admin();

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];
    $transaksi = false;

    try {
        mysqli_begin_transaction($conn);
        $transaksi = true;

        // LANGKAH 1: HAPUS DULU SEMUA DATA SISWA YANG PAKAI KATEGORI INI
        $stmt = $conn->prepare("DELETE FROM t_pelanggaran_siswa WHERE kategori_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $jumlah_dihapus = $stmt->affected_rows;

        // LANGKAH 2: BARU HAPUS KATEGORINYA
        $stmt = $conn->prepare("DELETE FROM t_pelanggaran_kategori WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        mysqli_commit($conn);

        $pesan = "Kategori berhasil dihapus!";
        if ($jumlah_dihapus > 0) {
            $pesan .= " Termasuk $jumlah_dihapus data pelanggaran siswa terkait.";
        }

        header("Location: kelola_kategori_pelanggaran.php?berhasil=1&pesan=" . urlencode($pesan));
        exit;

    } catch (Throwable $e) {
        if ($transaksi) mysqli_rollback($conn);
        $pesan = "Gagal: " . $e->getMessage();
        header("Location: kelola_kategori_pelanggaran.php?gagal=1&pesan=" . urlencode($pesan));
        exit;
    }
}

header("Location: kelola_kategori_pelanggaran.php");
exit;
?>