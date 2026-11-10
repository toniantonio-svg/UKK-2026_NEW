<?php
require_once "config.php";
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "cek_session.php";
cek_login();

$nama = $_SESSION['name'];
$role = strtolower(trim($_SESSION['role']));

function e($val) {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

// === Hak Akses ===
if (!in_array($role, ['guru', 'wali_kelas'])) {
    echo "<script>alert('Anda tidak berhak mengakses halaman ini!');location.href='dashboard.php';</script>";
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

        if (!$kelas_aktif) {
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap Poin Pelanggaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <div class="alert alert-danger">
                <strong>Kelas Anda sudah dinonaktifkan!</strong> Hubungi Admin untuk mengaktifkan kembali.
                <a href="dashboard.php" class="btn btn-primary ms-3">Kembali</a>
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

// === Fungsi Tindakan ===
function getTindakan($poin) {
    if ($poin >= 110) return ['label' => 'WAJIB DIKELUARKAN', 'class' => 'btn-dark text-white'];
    if ($poin >= 90)  return ['label' => 'Surat Peringatan (SP) 3', 'class' => 'btn-danger text-white'];
    if ($poin >= 70)  return ['label' => 'Surat Peringatan (SP) 2', 'class' => 'btn-warning text-dark'];
    if ($poin >= 50)  return ['label' => 'Surat Peringatan (SP) 1', 'class' => 'btn-primary text-white'];
    if ($poin >= 30)  return ['label' => 'Panggil ke BK', 'class' => 'btn-info text-dark'];
    return ['label' => 'Perhatian', 'class' => 'btn-secondary text-white'];
}

// === Query Data — Sesuai Nama Tabel & Kolom di Database ===
if ($role === 'wali_kelas' && $kelas_id) {
    $sql = "
        SELECT s.id, s.nis, s.nama, k.nama AS nama_kelas,
               COALESCE(SUM(p.poin), 0) AS total_poin,
               g.nama AS wali_kelas,
               GROUP_CONCAT(DISTINCT c.nama SEPARATOR ', ') AS kategori_pelanggaran
        FROM t_siswa s
        INNER JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
        LEFT JOIN t_kelas k ON ks.kelas_id = k.id
        LEFT JOIN t_wali_kelas wk ON k.id = wk.kelas_id AND wk.status_aktif = 1
        LEFT JOIN t_guru g ON wk.guru_id = g.id
        LEFT JOIN t_pelanggaran_siswa p ON s.id = p.siswa_id
        LEFT JOIN t_pelanggaran_kategori c ON p.pelanggaran_kategori_id = c.id
        WHERE ks.kelas_id = $kelas_id
        GROUP BY s.id
        HAVING total_poin > 0
        ORDER BY total_poin DESC, s.nama ASC
    ";
} else {
    $sql = "
        SELECT s.id, s.nis, s.nama, k.nama AS nama_kelas,
               COALESCE(SUM(p.poin), 0) AS total_poin,
               g.nama AS wali_kelas,
               GROUP_CONCAT(DISTINCT c.nama SEPARATOR ', ') AS kategori_pelanggaran
        FROM t_siswa s
        LEFT JOIN t_kelas_siswa ks ON s.id = ks.siswa_id
        LEFT JOIN t_kelas k ON ks.kelas_id = k.id
        LEFT JOIN t_wali_kelas wk ON k.id = wk.kelas_id AND wk.status_aktif = 1
        LEFT JOIN t_guru g ON wk.guru_id = g.id
        LEFT JOIN t_pelanggaran_siswa p ON s.id = p.siswa_id
        LEFT JOIN t_pelanggaran_kategori c ON p.pelanggaran_kategori_id = c.id
        GROUP BY s.id
        HAVING total_poin > 0
        ORDER BY total_poin DESC, s.nama ASC
    ";
}

$daftar_siswa = [];
$result = mysqli_query($conn, $sql);
if (!$result) {
    die("<div class='alert alert-danger m-3'>Error: " . mysqli_error($conn) . "</div>");
}
while ($row = mysqli_fetch_assoc($result)) {
    $daftar_siswa[] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rekap Poin Pelanggaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        table th { background-color: #cfe2ff; border-color: #dee2e6; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <h4 class="mb-3">Daftar Siswa & Tindakan</h4>

            <!-- Kotak Pencarian -->
            <div class="mb-3">
                <input type="text" id="cari" class="form-control" placeholder="🔍 Cari NIS, Nama, atau Kategori...">
            </div>

            <?php if (empty($daftar_siswa)): ?>
                <div class="alert alert-success">✅ Semua siswa dalam keadaan tertib.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="tabelData">
                        <thead>
                            <tr>
                                <th width="5%" class="text-center">No</th>
                                <th width="10%">NIS</th>
                                <th>Nama Siswa</th>
                                <th width="18%">Kategori Pelanggaran</th>
                                <th width="12%">Kelas</th>
                                <th width="15%">Wali Kelas</th>
                                <th width="7%" class="text-center">Poin</th>
                                <th width="20%">Tindakan</th>
                                <th width="10%" class="text-center">Opsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($daftar_siswa as $s):
                                $poin = (int)$s['total_poin'];
                                $tindakan = getTindakan($poin);
                                $kategori = $s['kategori_pelanggaran'] ?: '-';
                            ?>
                            <tr>
                                <td class="text-center nomor"><?= $no++ ?></td>
                                <td class="nis"><?= e($s['nis']) ?></td>
                                <td class="nama"><?= e($s['nama']) ?></td>
                                <td class="kategori"><?= e($kategori) ?></td>
                                <td class="kelas"><?= e($s['nama_kelas'] ?? '-') ?></td>
                                <td class="wali"><?= e($s['wali_kelas'] ?? '-') ?></td>
                                <td class="text-center fw-bold text-danger"><?= $poin ?></td>
                                <td>
                                    <span class="btn <?= $tindakan['class'] ?> btn-sm py-0 px-2">
                                        <?= $tindakan['label'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="riwayat.php" class="btn btn-success btn-sm">Detail</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
const input = document.getElementById('cari');
const tbody = document.querySelector('#tabelData tbody');
if (input && tbody) {
    input.addEventListener('input', function() {
        const k = this.value.toLowerCase();
        let n = 1;
        tbody.querySelectorAll('tr').forEach(tr => {
            const nis = tr.querySelector('.nis')?.textContent.toLowerCase() || '';
            const nama = tr.querySelector('.nama')?.textContent.toLowerCase() || '';
            const kategori = tr.querySelector('.kategori')?.textContent.toLowerCase() || '';
            const ok = !k || nis.includes(k) || nama.includes(k) || kategori.includes(k);
            tr.style.display = ok ? '' : 'none';
            if (ok) tr.querySelector('.nomor').textContent = n++;
        });
    });
}
</script>
<?php file_exists("footer.php") && include "footer.php"; ?>
</body>
</html>