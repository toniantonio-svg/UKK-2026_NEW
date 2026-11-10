<?php
require_once "config.php";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "cek_session.php";
cek_login();

$nama = $_SESSION['name'];
$role = strtolower(trim($_SESSION['role']));
$guru_id = (int)($_SESSION['guru_id'] ?? 0);

function e($val) {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

if (!in_array($role, ['admin', 'wali_kelas', 'guru'])) {
    echo "<script>alert('Anda tidak berhak mengakses halaman ini!');location.href='dashboard.php';</script>";
    exit;
}

// === HAPUS DATA ===
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $boleh_hapus = false;

    if ($role === 'guru') {
        $cek = mysqli_query($conn, "
            SELECT 1 FROM t_pelanggaran_siswa 
            WHERE id = $id_hapus 
              AND guru_id = $guru_id
            LIMIT 1
        ");
        $boleh_hapus = mysqli_num_rows($cek) > 0;
    } elseif ($role === 'wali_kelas') {
        $cek = mysqli_query($conn, "
            SELECT 1 FROM t_pelanggaran_siswa p
            LEFT JOIN t_siswa s ON p.siswa_id = s.id
            LEFT JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
            LEFT JOIN t_wali_kelas w ON ks.kelas_id = w.kelas_id
            LEFT JOIN t_guru g ON w.guru_id = g.id
            WHERE p.id = $id_hapus
              AND g.nama LIKE '%" . mysqli_real_escape_string($conn, $nama) . "%'
              AND w.status_aktif = 1
            LIMIT 1
        ");
        $boleh_hapus = mysqli_num_rows($cek) > 0;
    } else {
        $boleh_hapus = true;
    }

    if (!$boleh_hapus) {
        echo "<script>alert('Anda hanya boleh menghapus catatan yang Anda buat/kelola sendiri!');location.href='riwayat.php';</script>";
        exit;
    }

    mysqli_query($conn, "DELETE FROM t_pelanggaran_siswa WHERE id = $id_hapus");
    echo "<script>alert('Data berhasil dihapus!');location.href='riwayat.php';</script>";
    exit;
}

$kelas_id = 0;
$nama_kelas = '';
$kelas_aktif = true;

if ($role === 'wali_kelas') {
    $q = mysqli_query($conn, "
        SELECT w.kelas_id, k.nama AS nama_kelas, k.status_aktif
        FROM t_wali_kelas w
        JOIN t_kelas k ON w.kelas_id = k.id
        JOIN t_guru g ON w.guru_id = g.id
        WHERE w.status_aktif = 1
          AND g.nama LIKE '%" . mysqli_real_escape_string($conn, $nama) . "%'
        LIMIT 1
    ");
    if ($r = mysqli_fetch_assoc($q)) {
        $kelas_id = (int)$r['kelas_id'];
        $nama_kelas = $r['nama_kelas'];
        $kelas_aktif = (int)$r['status_aktif'] === 1;

        if (!$kelas_aktif) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelas Dinonaktifkan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <div class="card border-danger shadow-lg mt-5">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0 text-center">AKSES DITOLAK</h4>
                </div>
                <div class="card-body text-center p-4">
                    <h5 class="text-danger mb-3">KELAS ANDA SUDAH DINONAKTIFKAN</h5>
                    <p class="mb-2">Kelas: <strong><?= e($nama_kelas) ?></strong></p>
                    <p class="text-muted mb-4">Data riwayat tidak dapat ditampilkan.<br>Silakan hubungi Administrator untuk mengaktifkan kembali.</p>
                    <a href="dashboard.php" class="btn btn-primary">Kembali ke Beranda</a>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
<?php
        exit;
    }
}
}

// === QUERY DATA — TAMPIL SEMUA TERMASUK STATUS ===
if ($role === 'guru' && $guru_id) {
    $sql = "
        SELECT p.id, s.nis, s.nama AS nama_siswa,
               k.nama AS kategori, p.poin, p.keterangan, p.created_at AS tanggal,
               g.nama AS dicatat_oleh,
               p.tindakan_selesai
        FROM t_pelanggaran_siswa p
        LEFT JOIN t_siswa s ON p.siswa_id = s.id
        LEFT JOIN t_pelanggaran_kategori k ON p.kategori_id = k.id
        LEFT JOIN t_guru g ON p.guru_id = g.id
        WHERE p.guru_id = $guru_id
        ORDER BY p.created_at DESC, p.id DESC
    ";
} elseif ($role === 'wali_kelas' && $kelas_id) {
    $sql = "
        SELECT p.id, s.nis, s.nama AS nama_siswa,
               k.nama AS kategori, p.poin, p.keterangan, p.created_at AS tanggal,
               g.nama AS dicatat_oleh,
               p.tindakan_selesai
        FROM t_pelanggaran_siswa p
        LEFT JOIN t_siswa s ON p.siswa_id = s.id
        LEFT JOIN t_pelanggaran_kategori k ON p.kategori_id = k.id
        LEFT JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
        LEFT JOIN t_guru g ON p.guru_id = g.id
        WHERE ks.kelas_id = $kelas_id
        ORDER BY p.created_at DESC, p.id DESC
    ";
} else {
    $sql = "
        SELECT p.id, s.nis, s.nama AS nama_siswa,
               k.nama AS kategori, p.poin, p.keterangan, p.created_at AS tanggal,
               g.nama AS dicatat_oleh,
               p.tindakan_selesai
        FROM t_pelanggaran_siswa p
        LEFT JOIN t_siswa s ON p.siswa_id = s.id
        LEFT JOIN t_pelanggaran_kategori k ON p.kategori_id = k.id
        LEFT JOIN t_guru g ON p.guru_id = g.id
        ORDER BY p.created_at DESC, p.id DESC
    ";
}

$daftar_riwayat = [];
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $daftar_riwayat[] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riwayat Pelanggaran - SMK Muhammadiyah Kota Tasikmalaya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <h2 class="mb-3">Riwayat Pelanggaran</h2>
            <p>Selamat datang, <strong><?= e($nama) ?></strong>!</p>

            <?php if ($role === 'wali_kelas'): ?>
                <div class="alert alert-info mb-4">
                    <strong>Peran:</strong> Wali Kelas | Kelas: <strong><?= e($nama_kelas) ?></strong> — Menampilkan semua pelanggaran di kelas Anda
                </div>
            <?php elseif ($role === 'guru'): ?>
                <div class="alert alert-primary mb-4">
                    <strong>Peran:</strong> Guru — Menampilkan hanya catatan pelanggaran yang <strong>Anda buat</strong>
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-4">
                    <strong>Peran:</strong> Administrator — Menampilkan semua riwayat pelanggaran
                </div>
            <?php endif; ?>

            <?php if (empty($daftar_riwayat)): ?>
                <div class="alert alert-warning">
                    Belum ada riwayat pelanggaran yang tercatat
                    <?php if ($role === 'guru'): ?>
                        <a href="catat_pelanggaran.php" class="alert-link d-block mt-2">→ Catat pelanggaran baru</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="5%" class="text-center">No</th>
                                        <th width="12%">Tanggal</th>
                                        <th width="10%">NIS</th>
                                        <th width="15%">Nama Siswa</th>
                                        <th width="14%">Kategori</th>
                                        <th width="7%" class="text-center">Poin</th>
                                        <th width="10%">Status</th>
                                        <th width="14%">Dicatat Oleh</th>
                                        <th>Keterangan</th>
                                        <th width="8%" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($daftar_riwayat as $r):
                                        $poin = (int)$r['poin'];
                                        $baris = $poin >= 10 ? 'table-danger' : ($poin >= 5 ? 'table-warning' : 'table-light');
                                        $tgl = date('d/m/Y H:i', strtotime($r['tanggal']));
                                        $sudah_selesai = (int)($r['tindakan_selesai'] ?? 0) === 1;
                                    ?>
                                    <tr class="<?= $baris ?>">
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td><?= $tgl ?></td>
                                        <td><?= e($r['nis']) ?></td>
                                        <td><?= e($r['nama_siswa']) ?></td>
                                        <td><?= e($r['kategori'] ?? '-') ?></td>
                                        <td class="text-center fw-bold text-danger"><?= $poin ?></td>
                                        <td class="text-center">
                                            <?php if ($sudah_selesai): ?>
                                                <span class="badge bg-success">✅ Selesai</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">⏳ Belum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?= e($r['dicatat_oleh'] ?? '-') ?></small></td>
                                        <td><small class="text-muted"><?= e($r['keterangan'] ?? '-') ?></small></td>
                                        <td class="text-center">
                                            <a href="riwayat.php?hapus=<?= $r['id'] ?>"
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Yakin hapus? Tindakan tidak dapat dibatalkan!')">
                                                Hapus
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="laporan.php" class="btn btn-secondary">Lihat Laporan Rekap</a>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>
<?php file_exists("footer.php") && include "footer.php"; ?>
</body>
</html>