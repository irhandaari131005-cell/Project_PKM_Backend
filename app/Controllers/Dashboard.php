<?php

namespace App\Controllers;

use App\Models\M_absensi;
use App\Models\M_ekstrakurikuler;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class Dashboard extends BaseController
{
    /**
     * Angka untuk kartu Dashboard: kehadiran hari ini (WIB) dan jumlah ekstrakurikuler aktif.
     * Hanya hitungan, tanpa data pribadi, jadi aman untuk guru maupun siswa.
     */
    public function Ringkasan(): ResponseInterface
    {
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            $today = $now->format('Y-m-d');
            $db = db_connect();
            $auth = session()->get('auth_user');
            $isSiswa = ($auth['type'] ?? null) === 'siswa';
            $hadir = (new M_absensi())->select('id_user')->distinct()
                ->where('tanggal', $today)->where('check_in IS NOT NULL', null, false)
                ->countAllResults();
            $pulang = (new M_absensi())->select('id_user')->distinct()
                ->where('tanggal', $today)->where('check_out IS NOT NULL', null, false)
                ->countAllResults();
            $totalSiswa = (new M_siswa())->countAllResults();

            $data = [
                'tanggal' => $today,
                'hari' => self::DAYS[(int) $now->format('N') - 1],
                'kehadiran' => [
                    'hadir_hari_ini' => $hadir,
                    'total_siswa' => $totalSiswa,
                    'sudah_pulang' => $pulang,
                    'belum_hadir' => max(0, $totalSiswa - $hadir),
                ],
                'ekstrakurikuler' => [
                    'aktif' => (new M_ekstrakurikuler())->where('status', 'ACTIVE')->countAllResults(),
                    'total' => (new M_ekstrakurikuler())->countAllResults(),
                ],
                'total_guru' => $db->tableExists('guru') ? $db->table('guru')->countAllResults() : null,
                'total_kelas' => $db->tableExists('kelas') ? $db->table('kelas')->countAllResults() : null,
                'tren_kehadiran' => $this->attendanceTrend($now),
                'jadwal_hari_ini' => $this->todaySchedule($db, $now, $auth),
                'ekskul_hari_ini' => $this->todayExtracurriculars($now),
            ];
            if ($isSiswa) {
                $data['kehadiran_saya'] = $this->ownAttendance($now, (string) $auth['id_user']);
            } else {
                $data['absensi_terbaru'] = $this->latestAttendance($today);
            }

            return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Ringkasan dashboard berhasil diambil.',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Ringkasan dashboard: {exception}', ['exception' => $e]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Ringkasan dashboard belum dapat dimuat.',
            ]);
        }
    }

    private const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];

    /** Jumlah siswa hadir per hari, 7 hari terakhir (termasuk hari ini), urut dari yang terlama. */
    private function attendanceTrend(DateTimeImmutable $now): array
    {
        $start = $now->modify('-6 days')->format('Y-m-d');
        $rows = (new M_absensi())->select('tanggal, COUNT(DISTINCT id_user) AS hadir', false)
            ->where('tanggal >=', $start)->where('tanggal <=', $now->format('Y-m-d'))
            ->groupBy('tanggal')->findAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[substr((string) $row['tanggal'], 0, 10)] = (int) $row['hadir'];
        }
        $trend = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $day = $now->modify("-{$ago} days");
            $date = $day->format('Y-m-d');
            $trend[] = ['tanggal' => $date, 'hari' => self::DAYS[(int) $day->format('N') - 1], 'hadir' => $counts[$date] ?? 0];
        }

        return $trend;
    }

    /** Jadwal hari ini: siswa melihat jadwal kelasnya, guru melihat jadwal mengajarnya sendiri. */
    private function todaySchedule($db, DateTimeImmutable $now, array $auth): array
    {
        if (! $db->tableExists('jadwal') || ! $db->tableExists('kelas')) {
            return [];
        }
        $query = $db->table('jadwal')->select('jadwal.mata_pelajaran, jadwal.jam_mulai, jadwal.jam_selesai, jadwal.ruangan, kelas.nama_kelas, guru.nama AS nama_guru')
            ->join('kelas', 'kelas.id_kelas = jadwal.id_kelas', 'left')
            ->join('guru', 'guru.id_user = jadwal.id_guru', 'left')
            ->where('jadwal.hari', self::DAYS[(int) $now->format('N') - 1]);
        if (($auth['type'] ?? null) === 'siswa') {
            $kelas = (new M_siswa())->select('id_kelas')->where('id_user', (string) $auth['id_user'])->first()['id_kelas'] ?? null;
            if ($kelas === null) {
                return [];
            }
            $query->where('jadwal.id_kelas', (int) $kelas);
        } else {
            $query->where('jadwal.id_guru', (string) $auth['id_user']);
        }

        return array_map(static fn (array $row) => [
            'mata_pelajaran' => $row['mata_pelajaran'],
            'jam_mulai' => substr((string) $row['jam_mulai'], 0, 5),
            'jam_selesai' => substr((string) $row['jam_selesai'], 0, 5),
            'ruangan' => $row['ruangan'],
            'nama_kelas' => $row['nama_kelas'],
            'nama_guru' => $row['nama_guru'],
        ], $query->orderBy('jadwal.jam_mulai', 'ASC')->get(8)->getResultArray());
    }

    private function todayExtracurriculars(DateTimeImmutable $now): array
    {
        return array_map(static fn (array $row) => [
            'nama_ekstrakurikuler' => $row['nama_ekstrakurikuler'],
            'jam_mulai' => substr((string) $row['jam_mulai'], 0, 5),
            'jam_selesai' => substr((string) $row['jam_selesai'], 0, 5),
            'lokasi' => $row['lokasi'] ?? null,
        ], (new M_ekstrakurikuler())->where('status', 'ACTIVE')
            ->where('hari', self::DAYS[(int) $now->format('N') - 1])
            ->orderBy('jam_mulai', 'ASC')->findAll(5));
    }

    /** 5 absensi terakhir hari ini (khusus guru) untuk memantau kedatangan. */
    private function latestAttendance(string $today): array
    {
        $model = db_connect()->tableExists('kelas') ? (new M_absensi())->withStudentClass() : (new M_absensi())->withSiswa();

        return array_map(static fn (array $row) => [
            'nama_siswa' => $row['nama_siswa'],
            'nama_kelas' => $row['nama_kelas'] ?? null,
            'check_in' => $row['check_in'],
            'check_out' => $row['check_out'],
        ], $model->where('absensi.tanggal', $today)
            ->orderBy('absensi.update_date', 'DESC')->orderBy('absensi.check_in', 'DESC')->findAll(5));
    }

    /** Kehadiran siswa yang login: status hari ini, jumlah hari hadir minggu ini (Senin–hari ini) & bulan ini. */
    private function ownAttendance(DateTimeImmutable $now, string $userId): array
    {
        $today = $now->format('Y-m-d');
        $weekStart = $now->modify('-' . ((int) $now->format('N') - 1) . ' days')->format('Y-m-d');
        $count = static fn (string $from) => (new M_absensi())->select('tanggal')->distinct()
            ->where('id_user', $userId)->where('tanggal >=', $from)->where('tanggal <=', $today)->countAllResults();
        $todayRow = (new M_absensi())->where('id_user', $userId)->where('tanggal', $today)->first();

        return [
            'hari_ini' => $todayRow === null ? null : ['check_in' => $todayRow['check_in'], 'check_out' => $todayRow['check_out']],
            'minggu_ini' => $count($weekStart),
            'bulan_ini' => $count($now->format('Y-m-01')),
        ];
    }

    // Dashboard controller for handling dashboard related requests
    public function Dashboard()
    {
        // Jangan kirim hash password atau SN pemulihan akun ke dashboard.
        $data = (new M_users())
            ->select('id_user, username, nama, type, created_date, update_date');
        if (session()->get('auth_user')['type'] === 'siswa') {
            $data->where('id_user', (string) session()->get('auth_user')['id_user']);
        }
        $data = $data->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }

    public function Data_guru()
    {
        // Load model m_guru
        $this->m_user = new \App\Models\M_guru();

        // Get all data from table guru
        $data = $this->m_user->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }
    
    public function Data_siswa()
    {
        // Load model m_siswa
        $this->m_user = new \App\Models\M_siswa();

        // Get all data from table siswa
        $data = $this->m_user->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }
}
