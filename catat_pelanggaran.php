<?php
require_once "config.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "cek_session.php";
cek_login();

function e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$nama = $_SESSION['name'];
$role = strtolower(trim($_SESSION['role'] ?? ''));
$guru_id = (int)($_SESSION['guru_id'] ?? 0);

if (!in_array($role, ['guru', 'wali_kelas', 'admin'])) {
    echo "<script>alert('Anda tidak berhak!');location.href='dashboard.php';</script>";
    exit;
}

$kelas_id = 0;
$nama_kelas = '';
$kelas_aktif = true;
$blokir = false;
$daftar_kelas = [];

if ($role === 'wali_kelas') {
    $s = $conn->prepare("SELECT w.kelas_id, k.nama, k.status_aktif FROM t_wali_kelas w JOIN t_kelas k ON w.kelas_id = k.id JOIN t_guru g ON w.guru_id = g.id WHERE w.status_aktif = 1 AND g.nama = ? LIMIT 1");
    $s->bind_param("s", $nama);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    if ($r) {
        $kelas_id = (int)$r['kelas_id'];
        $nama_kelas = $r['nama'];
        $kelas_aktif = (int)$r['status_aktif'] === 1;
        if (!$kelas_aktif) {
            $blokir = true;
        }
        $daftar_kelas[] = ['id' => $kelas_id, 'nama' => $nama_kelas];
    }
} else {
    $q = mysqli_query($conn, "SELECT id, nama FROM t_kelas WHERE status_aktif = 1 ORDER BY nama");
    while ($r = mysqli_fetch_assoc($q)) {
        $daftar_kelas[] = $r;
    }
}

if ($blokir) {
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
                    <p class="text-muted mb-4">Tidak dapat mencatat pelanggaran.<br>Silakan hubungi Administrator untuk mengaktifkan kembali.</p>
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

// === AMBIL SEMUA SISWA ===
$semua_siswa = [];
if ($role === 'wali_kelas' && $kelas_id > 0) {
    $q_s = mysqli_query($conn, "SELECT s.id, s.nama, s.nis, ks.kelas_id 
                                FROM t_siswa s 
                                INNER JOIN t_kelas_siswa ks ON s.id = ks.siswa_id 
                                WHERE ks.kelas_id = $kelas_id 
                                ORDER BY s.nama");
} else {
    $q_s = mysqli_query($conn, "SELECT s.id, s.nama, s.nis, ks.kelas_id 
                                FROM t_siswa s 
                                INNER JOIN t_kelas_siswa ks ON s.id = ks.siswa_id 
                                ORDER BY ks.kelas_id, s.nama");
}
if ($q_s) {
    while ($r = mysqli_fetch_assoc($q_s)) {
        $semua_siswa[] = $r;
    }
}

$daftar_k = [];
$q_k = mysqli_query($conn, "SELECT id, nama, poin FROM t_pelanggaran_kategori WHERE status_aktif = 1 ORDER BY poin, nama");
if ($q_k) {
    while ($r = mysqli_fetch_assoc($q_k)) {
        $daftar_k[] = $r;
    }
}

$pesan = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['siswa_id'])) {
    $sid = (int)$_POST['siswa_id'];
    $kid = (int)($_POST['kategori_id'] ?? 0);
    $ket = trim($_POST['keterangan'] ?? '');
    $poin = 0;

    if ($kid > 0) {
        $s = $conn->prepare("SELECT poin FROM t_pelanggaran_kategori WHERE id = ? AND status_aktif = 1");
        $s->bind_param("i", $kid);
        $s->execute();
        $p = $s->get_result()->fetch_assoc();
        if (!$p) {
            $pesan = '<div class="alert alert-danger">Kategori tidak ditemukan!</div>';
        } else {
            $poin = (int)$p['poin'];
        }
    } else {
        $pesan = '<div class="alert alert-danger">Pilih kategori pelanggaran!</div>';
    }

    if (!$pesan && $role === 'wali_kelas') {
        if ($kelas_id <= 0) {
            $pesan = '<div class="alert alert-danger">Belum punya kelas wali!</div>';
        } else {
            $s = $conn->prepare("SELECT 1 FROM t_kelas_siswa WHERE siswa_id = ? AND kelas_id = ? LIMIT 1");
            $s->bind_param("ii", $sid, $kelas_id);
            $s->execute();
            if (!$s->get_result()->num_rows) {
                $pesan = '<div class="alert alert-danger">Siswa bukan di kelas Anda!</div>';
            }
        }
    }

    if (!$pesan) {
        // === SIMPAN DENGAN guru_id ===
        $s = $conn->prepare("INSERT INTO t_pelanggaran_siswa 
            (siswa_id, kategori_id, keterangan, poin, guru_id, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $s->bind_param("iisii", $sid, $kid, $ket, $poin, $guru_id);
        
        if ($s->execute()) {
            $pesan = '<div class="alert alert-success">✅ Berhasil disimpan! Poin: ' . $poin . ' — Data tercatat atas nama Anda.</div>';
        } else {
            $pesan = '<div class="alert alert-danger">Gagal simpan: ' . e($s->error) . '</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catat Pelanggaran - SMK Muhammadiyah Kota Tasikmalaya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .siswa-list {
            position: absolute;
            z-index: 100;
            width: 100%;
            max-height: 240px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ced4da;
            border-top: none;
            border-radius: 0 0 .375rem .375rem;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
            display: none;
        }
        .siswa-item {
            padding: .5rem .75rem;
            cursor: pointer;
        }
        .siswa-item:hover {
            background: #e9ecef;
        }
        .siswa-item.selected {
            background: #0d6efd;
            color: #fff;
        }
        .input-wrapper {
            position: relative;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include "menu.php"; ?>
        <main class="col-md-9 col-lg-10 p-4">
            <h2 class="mb-3">Catat Pelanggaran Siswa</h2>
            <p>Selamat datang, <strong><?= e($nama) ?></strong>!</p>

            <?php if ($role === 'wali_kelas'): ?>
                <div class="alert alert-info mb-4">
                    Anda mencatat di kelas: <strong><?= e($nama_kelas) ?></strong>
                </div>
            <?php endif; ?>

            <?= $pesan ?>

            <?php if (!$daftar_k): ?>
                <div class="alert alert-warning">Kategori belum ada!</div>
            <?php endif; ?>

            <form method="post" class="card shadow-sm p-4">
                <!-- PILIH KELAS -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Kelas</label>
                    <select name="kelas_id" id="pilihKelas" class="form-select form-select-lg" required onchange="filterSiswaByKelas()">
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ($daftar_kelas as $k): ?>
                            <option value="<?= $k['id'] ?>" 
                                <?= ($role === 'wali_kelas' && $k['id'] == $kelas_id) ? 'selected' : '' ?>>
                                <?= e($k['nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- NAMA SISWA -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Nama Siswa</label>
                    <div class="input-wrapper">
                        <input type="text" id="cariS" class="form-control" 
                               placeholder="Pilih kelas terlebih dahulu..." autocomplete="off" disabled>
                        <div id="daftarSiswa" class="siswa-list"></div>
                    </div>
                    <input type="hidden" name="siswa_id" id="siswa_id" required>
                    <div id="namaTerpilih" class="mt-2 small text-success" style="display:none">
                        Terpilih: <strong id="labelSiswa"></strong>
                    </div>
                </div>

                <!-- KATEGORI -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Kategori Pelanggaran</label>
                    <select name="kategori_id" id="kat" class="form-select form-select-lg" 
                            required onchange="setPoin()">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($daftar_k as $k): ?>
                            <option value="<?= $k['id'] ?>" data-poin="<?= $k['poin'] ?>">
                                <?= e($k['nama']) ?> — Poin: <?= $k['poin'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- KETERANGAN -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Keterangan Tambahan</label>
                    <textarea name="keterangan" class="form-control" rows="2" 
                              placeholder="Tulis jika ada..."></textarea>
                </div>

                <!-- POIN -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Poin</label>
                    <input type="number" id="poin" class="form-control form-select-lg" 
                           min="1" readonly placeholder="Muncul otomatis">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100" 
                    <?= !$daftar_k ? 'disabled' : '' ?>>
                    Simpan Pelanggaran
                </button>
            </form>
        </main>
    </div>
</div>

<script>
    const semuaSiswa = <?= json_encode($semua_siswa) ?>;
    
    const pilihKelasEl = document.getElementById('pilihKelas');
    const cariSEl = document.getElementById('cariS');
    const daftarSiswaEl = document.getElementById('daftarSiswa');
    const siswaIdEl = document.getElementById('siswa_id');
    const namaTerpilihEl = document.getElementById('namaTerpilih');
    const labelSiswaEl = document.getElementById('labelSiswa');
    
    let terpilih = null;
    let siswaTerpilihKelas = [];

    // Muat otomatis jika wali kelas (sudah terpilih)
    window.onload = function() {
        if (pilihKelasEl.value) {
            filterSiswaByKelas();
        }
    };

    function filterSiswaByKelas() {
        const kelasId = parseInt(pilihKelasEl.value);
        
        // Reset pilihan siswa
        siswaIdEl.value = '';
        cariSEl.value = '';
        cariSEl.disabled = !kelasId;
        namaTerpilihEl.style.display = 'none';
        terpilih = null;
        daftarSiswaEl.style.display = 'none';
        
        if (!kelasId) {
            siswaTerpilihKelas = [];
            return;
        }
        
        // Tampilkan hanya siswa dari kelas yang dipilih
        siswaTerpilihKelas = semuaSiswa.filter(s => parseInt(s.kelas_id) === kelasId);
        
        // Tampilkan langsung daftar siswa
        if (siswaTerpilihKelas.length > 0) {
            render(siswaTerpilihKelas);
        }
    }

    function render(daftar) {
        daftarSiswaEl.innerHTML = '';
        if (!daftar.length) {
            daftarSiswaEl.innerHTML = '<div class="p-2 text-muted">Tidak ada siswa di kelas ini</div>';
            daftarSiswaEl.style.display = 'block';
            return;
        }
        daftar.forEach(s => {
            const d = document.createElement('div');
            d.className = 'siswa-item' + (terpilih && terpilih.id === s.id ? ' selected' : '');
            d.textContent = s.nama + ' — NIS: ' + s.nis;
            d.onclick = () => pilihSiswa(s);
            daftarSiswaEl.appendChild(d);
        });
        daftarSiswaEl.style.display = 'block';
    }

    function pilihSiswa(s) {
        terpilih = s;
        siswaIdEl.value = s.id;
        cariSEl.value = s.nama;
        labelSiswaEl.textContent = s.nama + ' — NIS: ' + s.nis;
        namaTerpilihEl.style.display = 'block';
        daftarSiswaEl.style.display = 'none';
    }

    function filterSiswa() {
        const k = cariSEl.value.trim().toLowerCase();
        if (!k) {
            render(siswaTerpilihKelas);
            return;
        }
        const hasil = siswaTerpilihKelas.filter(s => 
            s.nama.toLowerCase().includes(k) || s.nis.includes(k)
        );
        render(hasil);
    }

    // Tutup daftar jika klik di luar
    document.addEventListener('click', e => {
        if (!cariSEl.contains(e.target) && !daftarSiswaEl.contains(e.target)) {
            daftarSiswaEl.style.display = 'none';
        }
    });

    cariSEl.addEventListener('input', filterSiswa);
    cariSEl.addEventListener('focus', () => {
        if (siswaTerpilihKelas.length > 0) {
            render(siswaTerpilihKelas);
        }
    });

    function setPoin() {
        const s = document.getElementById('kat');
        document.getElementById('poin').value = s.selectedIndex 
            ? s.options[s.selectedIndex].dataset.poin 
            : '';
    }
</script>

<?php if (file_exists("footer.php")) include "footer.php"; ?>
</body>
</html>