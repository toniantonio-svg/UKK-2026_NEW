<?php
require_once "config.php";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "cek_session.php";
cek_login();

$nama = $_SESSION['name'];
$role = strtolower(trim($_SESSION['role']));

function e($val) {
    return htmlspecialchars((string)($val ?? '-'), ENT_QUOTES, 'UTF-8');
}

// === Hak Akses ===
if (!in_array($role, ['guru', 'wali_kelas', 'admin'])) {
    echo "<script>alert('Anda tidak berhak!');location.href='dashboard.php';</script>";
    exit;
}

// === Data Kelas Wali ===
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
    }

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
                    <p class="text-muted mb-4">Data siswa tidak dapat ditampilkan.<br>Silakan hubungi Administrator untuk mengaktifkan kembali.</p>
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

// ✅ PROSES TANDAI SELESAI — Admin > Wali Kelas > Guru
if (isset($_GET['selesai']) && is_numeric($_GET['selesai'])) {
    $siswa_id = (int)$_GET['selesai'];
    $boleh = false;

    // 1. ADMIN = Boleh SEMUA
    if ($role === 'admin') {
        $boleh = true;
    }
    // 2. WALI KELAS = Boleh siswa di kelasnya saja
    elseif ($role === 'wali_kelas' && $kelas_id > 0) {
        $cek = mysqli_query($conn, "
            SELECT 1 FROM t_kelas_siswa 
            WHERE siswa_id = $siswa_id AND kelas_id = $kelas_id 
            LIMIT 1
        ");
        $boleh = (mysqli_num_rows($cek) > 0);
    }
    // 3. GURU = Boleh semua
    elseif ($role === 'guru') {
        $boleh = true;
    }

    if ($boleh) {
        mysqli_query($conn, "
            UPDATE t_pelanggaran_siswa 
            SET tindakan_selesai = 1 
            WHERE siswa_id = $siswa_id 
              AND tindakan_selesai = 0
        ");
        echo "<script>alert('✅ Tindakan ditandai selesai!');location.href='tindakan.php';</script>";
    } else {
        echo "<script>alert('❌ Tidak berhak! Role Anda: $role');location.href='tindakan.php';</script>";
    }
    exit;
}

// === Fungsi Tindakan ===
function getTindakan($poin) {
    if ($poin >= 110) return ['label' => 'WAJIB DIKELUARKAN', 'class' => 'bg-danger text-white fw-bold'];
    if ($poin >= 90)  return ['label' => 'Surat Peringatan (SP) 3', 'class' => 'bg-danger text-white'];
    if ($poin >= 70)  return ['label' => 'Surat Peringatan (SP) 2', 'class' => 'bg-warning text-dark'];
    if ($poin >= 50)  return ['label' => 'Surat Peringatan (SP) 1', 'class' => 'bg-primary text-white'];
    if ($poin >= 30)  return ['label' => 'Panggil ke BK', 'class' => 'bg-info text-white'];
    return null;
}

// === Query Data ===
if ($role === 'wali_kelas' && $kelas_id) {
    $q_siswa = mysqli_query($conn, "
        SELECT s.id, s.nama, s.nis,
               COALESCE(SUM(CASE WHEN p.tindakan_selesai = 0 THEN p.poin ELSE 0 END), 0) AS total_poin,
               k.nama AS nama_kelas,
               g.nama AS wali_kelas
        FROM t_siswa s
        INNER JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
        LEFT JOIN t_kelas k ON ks.kelas_id = k.id
        LEFT JOIN t_wali_kelas wk ON k.id = wk.kelas_id AND wk.status_aktif = 1
        LEFT JOIN t_guru g ON wk.guru_id = g.id
        LEFT JOIN t_pelanggaran_siswa p ON s.id = p.siswa_id
        WHERE ks.kelas_id = $kelas_id
        GROUP BY s.id
        HAVING total_poin >= 30
        ORDER BY total_poin DESC, s.nama ASC
    ");
} else {
    // Admin & Guru tampil semua
    $q_siswa = mysqli_query($conn, "
        SELECT s.id, s.nama, s.nis,
               COALESCE(SUM(CASE WHEN p.tindakan_selesai = 0 THEN p.poin ELSE 0 END), 0) AS total_poin,
               k.nama AS nama_kelas,
               g.nama AS wali_kelas
        FROM t_siswa s
        LEFT JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
        LEFT JOIN t_kelas k ON ks.kelas_id = k.id
        LEFT JOIN t_wali_kelas wk ON k.id = wk.kelas_id AND wk.status_aktif = 1
        LEFT JOIN t_guru g ON wk.guru_id = g.id
        LEFT JOIN t_pelanggaran_siswa p ON s.id = p.siswa_id
        GROUP BY s.id
        HAVING total_poin >= 30
        ORDER BY total_poin DESC, s.nama ASC
    ");
}

$daftar_siswa = [];
if ($q_siswa) {
    while ($row = mysqli_fetch_assoc($q_siswa)) {
        $daftar_siswa[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tindakan - SMK Muhammadiyah Kota Tasikmalaya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <h2 class="mb-3">Tindakan Siswa</h2>
            <p>Selamat datang, <strong><?= e($nama) ?></strong>! <small class="text-muted">(Role: <?= e($role) ?>)</small></p>

            <?php if ($role === 'wali_kelas'): ?>
                <div class="alert alert-info mb-4">
                    Anda melihat data siswa kelas: <strong><?= e($nama_kelas) ?></strong>
                </div>
            <?php endif; ?>

            <!-- Aturan Tindakan -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h5 class="text-muted fs-6 mb-3">ATURAN TINDAKAN</h5>
                    <div class="d-grid gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-info px-3 py-2">30 – 49</span>
                            <span>Panggil ke BK</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary px-3 py-2">50 – 69</span>
                            <span>Surat Peringatan (SP) 1</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning text-dark px-3 py-2">70 – 89</span>
                            <span>Surat Peringatan (SP) 2</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-danger px-3 py-2">90 – 109</span>
                            <span>Surat Peringatan (SP) 3</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-dark px-3 py-2">≥ 110</span>
                            <span class="fw-bold text-danger">WAJIB DIKELUARKAN</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Daftar Siswa -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="mb-3 fw-semibold">Daftar Siswa & Tindakan</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-primary text-white">
                                <tr>
                                    <th width="4%" class="text-center">No</th>
                                    <th width="10%">NIS</th>
                                    <th>Nama Siswa</th>
                                    <th width="12%">Kelas</th>
                                    <th width="15%">Wali Kelas</th>
                                    <th width="8%" class="text-center">Poin</th>
                                    <th width="20%" class="text-center">Tindakan</th>
                                    <th width="12%" class="text-center">Opsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_siswa)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            ✅ Semua siswa sudah ditindak lanjuti.<br>
                                            Belum ada siswa yang memerlukan tindakan baru.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = 1; foreach ($daftar_siswa as $s):
                                        $tindakan = getTindakan((int)$s['total_poin']);
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td><?= e($s['nis']) ?></td>
                                        <td><?= e($s['nama']) ?></td>
                                        <td><?= e($s['nama_kelas'] ?? '-') ?></td>
                                        <td><?= e($s['wali_kelas'] ?? '-') ?></td>
                                        <td class="text-center fw-bold text-danger"><?= (int)$s['total_poin'] ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= $tindakan['class'] ?> fs-6 px-3 py-2">
                                                <?= $tindakan['label'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="tindakan.php?selesai=<?= $s['id'] ?>"
                                               class="btn btn-success btn-sm"
                                               onclick="return confirm('Tandai selesai? Hilang dari sini, tetap ada di Riwayat.')">
                                                ✅ Selesai
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<?php file_exists("footer.php") && include "footer.php"; ?>
</body>
</html>