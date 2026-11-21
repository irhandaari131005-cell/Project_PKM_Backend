<?php

namespace App\Controllers;

use App\Models\M_absensi;
use App\Models\M_kelas;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * GET /api/absensi/riwayat — riwayat absensi dengan 4 filter:
 * id_kelas, search (nama/NIS siswa), dari & sampai (YYYY-MM-DD, maksimal 92 hari).
 * Parameter lama `tanggal` tetap diterima (sama dengan dari = sampai = tanggal).
 *
 * Selain daftar catatan (`data` + `meta`, 25 per halaman), respons memuat `rekap`:
 * jumlah hari hadir tiap siswa pada rentang tersebut, termasuk siswa yang belum hadir sama sekali.
 */
class Riwayat_absen extends BaseController
{
    private const PER_PAGE = 25;
    private const MAX_RANGE_DAYS = 92;
    private const REKAP_LIMIT = 50;

    public function index(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $kelas = $this->request->getGet('id_kelas');
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        $dari = $this->request->getGet('dari');
        $sampai = $this->request->getGet('sampai');
        $errors = [];

        if ($dari === null && $sampai === null) {
            // Satu tanggal (perilaku lama): ?tanggal=, default hari ini (WIB).
            $tanggal = $this->request->getGet('tanggal') ?? $today;
            if (! $this->validDate($tanggal)) {
                $errors['tanggal'] = 'Pilih tanggal yang valid dengan format YYYY-MM-DD.';
            }
            $dari = $sampai = $tanggal;
        } else {
            $dari ??= $sampai;
            $sampai ??= $dari;
            if (! $this->validDate($dari)) {
                $errors['dari'] = 'Pilih tanggal awal yang valid (YYYY-MM-DD).';
            }
            if (! $this->validDate($sampai)) {
                $errors['sampai'] = 'Pilih tanggal akhir yang valid (YYYY-MM-DD).';
            }
            if ($errors === []) {
                if ($sampai < $dari) {
                    $errors['sampai'] = 'Tanggal akhir tidak boleh sebelum tanggal awal.';
                } elseif ($this->dayCount($dari, $sampai) > self::MAX_RANGE_DAYS) {
                    $errors['sampai'] = 'Rentang tanggal maksimal ' . self::MAX_RANGE_DAYS . ' hari.';
                }
            }
        }
        if ($kelas !== null && ! $this->validId($kelas)) {
            $errors['id_kelas'] = 'id_kelas harus berupa bilangan bulat positif.';
        }
        if ($search !== null && (! is_string($search) || mb_strlen(trim($search)) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $search) === 1)) {
            $errors['search'] = 'Pencarian harus berupa teks maksimal 100 karakter.';
        }
        if (! $this->validId($page)) {
            $errors['page'] = 'Halaman harus berupa bilangan bulat positif.';
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }
        $search = is_string($search) ? trim($search) : '';

        try {
            if ($kelas !== null && (new M_kelas())->find((int) $kelas) === null) {
                return $this->validationError(['id_kelas' => 'Kelas tidak ditemukan.']);
            }

            $db = db_connect();
            $studentOnly = session()->get('auth_user')['type'] === 'siswa'
                ? (string) session()->get('auth_user')['id_user'] : null;
            $model = (new M_absensi())->withStudentClass()
                ->where('absensi.tanggal >=', $dari)->where('absensi.tanggal <=', $sampai);
            if ($studentOnly !== null) {
                $model->where('absensi.id_user', $studentOnly);
            }
            if ($kelas !== null) {
                $model->where('siswa.id_kelas', (int) $kelas);
            }
            if ($search !== '') {
                $model->where($this->searchCondition($search), null, false);
            }
            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $data = $model->orderBy('absensi.tanggal', 'DESC')->orderBy('kelas.nama_kelas', 'ASC')
                ->orderBy('siswa.nama_siswa', 'ASC')->orderBy('absensi.id_absensi', 'ASC')
                ->findAll(self::PER_PAGE, $offset);
            foreach ($data as &$row) {
                $row['status_kehadiran'] = 'hadir';
            }
            unset($row);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Riwayat absensi berhasil diambil.',
                'data' => $data,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'from' => $data === [] ? null : $offset + 1,
                    'to' => $data === [] ? null : $offset + count($data),
                ],
                'rekap' => $this->rekap($db, $dari, $sampai, $kelas === null ? null : (int) $kelas, $search, $studentOnly),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Riwayat absensi gagal: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 500,
                'message' => 'Terjadi kesalahan saat mengambil riwayat absensi.',
            ]);
        }
    }

    /**
     * Jumlah hari hadir per siswa pada rentang tanggal (siswa nonaktif tidak dihitung).
     * `hari_tercatat` = jumlah tanggal yang punya catatan absensi di sekolah (perkiraan hari sekolah).
     */
    private function rekap($db, string $dari, string $sampai, ?int $kelas, string $search, ?string $studentOnly): array
    {
        $s = $db->escapeIdentifiers($db->prefixTable('siswa'));
        $k = $db->escapeIdentifiers($db->prefixTable('kelas'));
        $a = $db->escapeIdentifiers($db->prefixTable('absensi'));
        $where = [];
        $binds = [$dari, $sampai];
        if ($studentOnly !== null) {
            $where[] = "{$s}.id_user = ?";
            $binds[] = $studentOnly;
        }
        if ($kelas !== null) {
            $where[] = "{$s}.id_kelas = ?";
            $binds[] = $kelas;
        }
        if ($search !== '') {
            $where[] = $this->searchCondition($search);
        }
        if ($db->fieldExists('status', 'siswa')) {
            $where[] = "({$s}.status IS NULL OR UPPER({$s}.status) <> 'INACTIVE')";
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = " FROM {$s} LEFT JOIN {$k} ON {$k}.id_kelas = {$s}.id_kelas"
            . " LEFT JOIN {$a} ON {$a}.id_user = {$s}.id_user AND {$a}.tanggal >= ? AND {$a}.tanggal <= ?";

        $rows = $db->query(
            "SELECT {$s}.id_siswa, {$s}.nama_siswa, {$s}.id_kelas, {$k}.nama_kelas, COUNT(DISTINCT {$a}.tanggal) AS jumlah_hadir"
            . $from . $whereSql
            . " GROUP BY {$s}.id_siswa, {$s}.nama_siswa, {$s}.id_kelas, {$k}.nama_kelas"
            . " ORDER BY {$k}.nama_kelas ASC, {$s}.nama_siswa ASC, {$s}.id_siswa ASC LIMIT " . self::REKAP_LIMIT,
            $binds,
        )->getResultArray();
        $total = (int) ($db->query(
            "SELECT COUNT(*) AS jumlah FROM {$s} LEFT JOIN {$k} ON {$k}.id_kelas = {$s}.id_kelas" . $whereSql,
            array_slice($binds, 2),
        )->getRowArray()['jumlah'] ?? 0);
        $recorded = (int) ($db->query(
            "SELECT COUNT(DISTINCT tanggal) AS jumlah FROM {$a} WHERE tanggal >= ? AND tanggal <= ?",
            [$dari, $sampai],
        )->getRowArray()['jumlah'] ?? 0);

        return [
            'dari' => $dari,
            'sampai' => $sampai,
            'jumlah_hari' => $this->dayCount($dari, $sampai),
            'hari_tercatat' => $recorded,
            'total_siswa' => $total,
            'siswa' => array_map(static fn (array $row) => [
                'id_siswa' => (int) $row['id_siswa'],
                'nama_siswa' => $row['nama_siswa'],
                'id_kelas' => $row['id_kelas'] === null ? null : (int) $row['id_kelas'],
                'nama_kelas' => $row['nama_kelas'],
                'jumlah_hadir' => (int) $row['jumlah_hadir'],
            ], $rows),
        ];
    }

    /** Cari nama atau NIS siswa; % dan _ dari pengguna dicari sebagai teks biasa. */
    private function searchCondition(string $search): string
    {
        $db = db_connect();
        $table = $db->escapeIdentifiers($db->prefixTable('siswa'));
        $pattern = $db->escape(mb_strtolower('%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%'));
        $condition = "LOWER({$table}.nama_siswa) LIKE {$pattern} ESCAPE '!'";
        if ($db->fieldExists('nis', 'siswa')) {
            $condition .= " OR LOWER({$table}.nis) LIKE {$pattern} ESCAPE '!'";
        }

        return "({$condition})";
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function dayCount(string $dari, string $sampai): int
    {
        $timezone = new DateTimeZone('Asia/Jakarta');

        return (int) (new DateTimeImmutable($dari, $timezone))->diff(new DateTimeImmutable($sampai, $timezone))->days + 1;
    }

    private function validId(mixed $value): bool
    {
        // Hanya digit murni: tolak spasi, tanda +, dan angka nol di depan.
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }

    private function validationError(array $errors): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'status' => 422, 'message' => 'Filter riwayat absensi tidak valid.', 'errors' => $errors,
        ]);
    }
}
