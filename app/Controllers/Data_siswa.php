<?php

namespace App\Controllers;

use App\Libraries\SchoolMembers;
use App\Models\M_kelas;
use App\Models\M_siswa;
use App\Models\M_users;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

class Data_siswa extends BaseController
{
    private const PER_PAGE = 25;
    /** NIS/NIP: angka, huruf, titik, strip, atau garis miring, maksimal 30 karakter. */
    public const NIS_PATTERN = '/\A[0-9A-Za-z.\-\/]{1,30}\z/';
    public const STATUSES = ['ACTIVE', 'INACTIVE'];
    /** Data pribadi dari "Profil Saya" + SN kartu: tidak dikirim ke akun siswa lain. */
    public const PRIVATE_FIELDS = ['sn', 'tempat_lahir', 'tanggal_lahir', 'alamat', 'no_telp', 'foto'];

    public function DataSiswa()
    {
        $id_siswa = $this->request->getGet('id_siswa');

        if ($id_siswa !== null && ! self::validPositiveId($id_siswa)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_siswa harus berupa bilangan bulat positif',
            ]);
        }

        // Halaman Data Siswa mengirim page/search: pakai daftar berhalaman + pencarian.
        if ($id_siswa === null && ($this->request->getGet('page') !== null || $this->request->getGet('search') !== null)) {
            return $this->paginated();
        }

        // Siswa boleh melihat seluruh daftar siswa (read-only), tetapi SN kartu dan data pribadi
        // disembunyikan (SN dipakai untuk absensi RFID dan reset password).
        $siswaModel = new M_siswa();
        $data =$id_siswa === null ? $siswaModel->findAll() : $siswaModel->where('id_siswa', (int) $id_siswa)->first();
        if ($data !== null) {
            $data = $id_siswa === null ? array_map(self::visibleRow(...), $data) : self::visibleRow($data);
        }

        // Return json response 200
        if($data === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 404,
                'message' => 'Data tidak ditemukan',
            ]);
        } else {
            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data berhasil diambil',
                'data' => $data,
            ]);
        }
    }

    /** GET ?page=&search= — cari nama atau NIS, 25 per halaman, dengan nama kelas dan ringkasan. */
    private function paginated(): ResponseInterface
    {
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        if (! self::validPositiveId($page)) {
            return $this->respond(422, 'Halaman harus berupa bilangan bulat positif.', null, ['page' => 'Halaman tidak valid.']);
        }
        if ($search !== null && (! is_string($search) || mb_strlen(trim($search)) > 100)) {
            return $this->respond(422, 'Pencarian tidak valid.', null, ['search' => 'Pencarian harus berupa teks maksimal 100 karakter.']);
        }

        try {
            $db = db_connect();
            // akun_id: penanda siswa sudah punya akun login (has_account) untuk form edit/hapus.
            $model = (new M_siswa())->select('siswa.*, kelas.nama_kelas, users.id_user AS akun_id')
                ->join('kelas', 'kelas.id_kelas = siswa.id_kelas', 'left')
                ->join('users', 'users.id_user = siswa.id_user', 'left');
            if (is_string($search) && trim($search) !== '') {
                // % dan _ dari pengguna dicari sebagai teks biasa, bukan wildcard LIKE.
                $pattern = $db->escape(mb_strtolower('%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%'));
                $table = $db->escapeIdentifiers($db->prefixTable('siswa'));
                $model->where("(LOWER({$table}.nama_siswa) LIKE {$pattern} ESCAPE '!' OR LOWER({$table}.nis) LIKE {$pattern} ESCAPE '!')", null, false);
            }
            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $rows = $model->orderBy('siswa.nama_siswa', 'ASC')->orderBy('siswa.id_siswa', 'ASC')->findAll(self::PER_PAGE, $offset);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data berhasil diambil',
                'data' => array_map(static fn (array $row) => self::visibleRow(self::accountFlag($row)), $rows),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'from' => $rows === [] ? null : $offset + 1,
                    'to' => $rows === [] ? null : $offset + count($rows),
                    'summary' => [
                        'total_siswa' => (new M_siswa())->countAllResults(),
                        'total_kelas' => (new M_kelas())->countAllResults(),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** POST — Tambah Siswa (khusus guru). Data tanpa akun; akun nyusul lewat registrasi dengan SN yang sama. */
    public function create(): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $errors = [];
            $nama = self::text($input['nama_siswa'] ?? null);
            if ($nama === null || $nama === '' || mb_strlen($nama) > 100 || self::hasControlChars($nama)) {
                $errors['nama_siswa'] = 'Nama siswa wajib diisi, maksimal 100 karakter.';
            }
            $nis = self::text($input['nis'] ?? null);
            if ($nis === null || ! preg_match(self::NIS_PATTERN, $nis)) {
                $errors['nis'] = 'NIS wajib diisi (maksimal 30 karakter: angka, huruf, titik, strip, atau garis miring).';
            } elseif ((new M_siswa())->where('nis', $nis)->countAllResults() > 0) {
                $errors['nis'] = 'NIS sudah dipakai siswa lain.';
            }
            $kelas = is_int($input['id_kelas'] ?? null) ? (string) $input['id_kelas'] : self::text($input['id_kelas'] ?? null);
            if (! is_string($kelas) || ! self::validPositiveId($kelas) || (new M_kelas())->find((int) $kelas) === null) {
                $errors['id_kelas'] = 'Pilih kelas dari daftar kelas yang terdaftar.';
            }
            // SN opsional: kosong/tidak dikirim = belum punya kartu.
            $sn = self::text($input['sn'] ?? null);
            if (isset($input['sn']) && ! is_string($input['sn'])) {
                $errors['sn'] = 'Nomor kartu harus berupa teks.';
            } elseif ($sn === '') {
                $sn = null;
            }
            if ($sn !== null && ! isset($errors['sn'])) {
                if (mb_strlen($sn) > 50 || self::hasControlChars($sn)) {
                    $errors['sn'] = 'Nomor kartu maksimal 50 karakter.';
                } elseif ((new SchoolMembers(db_connect()))->cardInUse($sn)) {
                    $errors['sn'] = 'Nomor kartu sudah dipakai.';
                }
            }
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
            $id = (new M_siswa())->insert([
                'id_user' => null, 'nama_siswa' => $nama, 'nis' => $nis, 'id_kelas' => (int) $kelas, 'sn' => $sn,
                'status' => 'ACTIVE', 'type' => 'siswa', 'created_date' => $now, 'update_date' => $now,
            ]);
            $row = (new M_siswa())->select('siswa.*, kelas.nama_kelas')->join('kelas', 'kelas.id_kelas = siswa.id_kelas', 'left')
                ->where('siswa.id_siswa', (int) $id)->first();

            return $this->respond(201, 'Data siswa berhasil ditambahkan.', $row);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * PUT/PATCH /api/data_siswa/{id_siswa} — ubah data siswa (khusus guru, dibatasi ApiAccessFilter).
     * Field yang tidak dikirim tidak berubah. Bila siswa sudah punya akun, nama dan nomor kartu (SN)
     * pada akun ikut diperbarui agar login, lupa password, dan absensi tetap memakai data yang sama.
     */
    public function update(string $id): ResponseInterface
    {
        if (! self::validPositiveId($id)) {
            return $this->respond(422, 'ID siswa tidak valid.', null, ['id_siswa' => 'ID siswa harus berupa bilangan bulat positif.']);
        }
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $db = db_connect();
            $student = (new M_siswa())->find((int) $id);
            if ($student === null) {
                return $this->respond(404, 'Data siswa tidak ditemukan.');
            }
            $members = new SchoolMembers($db);
            $accountId = $members->accountId($student['id_user'] ?? null);

            $data = [];
            $errors = [];
            if (array_key_exists('nama_siswa', $input)) {
                $nama = self::text($input['nama_siswa']);
                if ($nama === null || $nama === '' || mb_strlen($nama) > 100 || self::hasControlChars($nama)) {
                    $errors['nama_siswa'] = 'Nama siswa wajib diisi, maksimal 100 karakter.';
                } else {
                    $data['nama_siswa'] = $nama;
                }
            }
            if (array_key_exists('nis', $input)) {
                $nis = self::text($input['nis']);
                if ($nis === null || ! preg_match(self::NIS_PATTERN, $nis)) {
                    $errors['nis'] = 'NIS wajib diisi (maksimal 30 karakter: angka, huruf, titik, strip, atau garis miring).';
                } elseif ((new M_siswa())->where('nis', $nis)->where('id_siswa !=', (int) $id)->countAllResults() > 0) {
                    $errors['nis'] = 'NIS sudah dipakai siswa lain.';
                } else {
                    $data['nis'] = $nis;
                }
            }
            if (array_key_exists('id_kelas', $input)) {
                $kelas = is_int($input['id_kelas']) ? (string) $input['id_kelas'] : self::text($input['id_kelas']);
                if (! is_string($kelas) || ! self::validPositiveId($kelas) || (new M_kelas())->find((int) $kelas) === null) {
                    $errors['id_kelas'] = 'Pilih kelas dari daftar kelas yang terdaftar.';
                } else {
                    $data['id_kelas'] = (int) $kelas;
                }
            }
            if (array_key_exists('sn', $input)) {
                $snError = self::validateCard($input['sn'], $accountId !== null, $members, ['siswa' => (int) $id, 'users' => $accountId], $sn);
                if ($snError !== null) {
                    $errors['sn'] = $snError;
                } else {
                    $data['sn'] = $sn;
                }
            }
            if (array_key_exists('status', $input)) {
                $status = is_string($input['status']) ? strtoupper(trim($input['status'])) : null;
                if (in_array($status, self::STATUSES, true)) {
                    $data['status'] = $status;
                } else {
                    $errors['status'] = 'Pilih status Aktif atau Nonaktif.';
                }
            }
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }
            if ($data === []) {
                return $this->respond(422, 'Validasi gagal.', null, [
                    'nama_siswa' => 'Kirim minimal satu field: nama_siswa, nis, id_kelas, sn, atau status.',
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
            $account = [];
            if (isset($data['nama_siswa'])) {
                $account['nama'] = $data['nama_siswa'];
            }
            if (array_key_exists('sn', $data)) {
                $account['sn'] = $data['sn'];
            }
            try {
                $db->transException(true)->transStart();
                (new M_siswa())->update((int) $id, $data + ['update_date' => $now]);
                if ($accountId !== null && $account !== []) {
                    (new M_users())->update($accountId, $account + ['update_date' => $now]);
                }
                $db->transComplete();
            } catch (DatabaseException $e) {
                // Request bersamaan bisa lolos pengecekan; unique key database (NIS/SN) jadi pengaman terakhir.
                $conflict = $this->uniqueConflicts($data, (int) $id, $accountId, $members);
                if ($conflict !== []) {
                    return $this->respond(422, 'Validasi gagal.', null, $conflict);
                }
                throw $e;
            }

            return $this->respond(200, 'Data siswa berhasil diperbarui.',
                self::visibleRow($this->findWithClass((int) $id) + ['has_account' => $accountId !== null]));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /api/data_siswa/{id_siswa} — hapus data siswa (khusus guru).
     * Akun login siswa ikut dihapus. Siswa yang sudah punya riwayat absensi tidak dihapus
     * agar riwayat tetap utuh; ubah statusnya menjadi Nonaktif.
     */
    public function delete(string $id): ResponseInterface
    {
        if (! self::validPositiveId($id)) {
            return $this->respond(422, 'ID siswa tidak valid.', null, ['id_siswa' => 'ID siswa harus berupa bilangan bulat positif.']);
        }

        try {
            $db = db_connect();
            $student = (new M_siswa())->find((int) $id);
            if ($student === null) {
                return $this->respond(404, 'Data siswa tidak ditemukan.');
            }
            $accountId = (new SchoolMembers($db))->accountId($student['id_user'] ?? null);
            if ($accountId !== null && $db->tableExists('absensi')
                && $db->table('absensi')->where('id_user', $accountId)->countAllResults() > 0) {
                return $this->respond(409, 'Siswa ini sudah memiliki riwayat absensi sehingga tidak dapat dihapus. Ubah statusnya menjadi Nonaktif agar riwayat tetap tersimpan.');
            }

            $db->transException(true)->transStart();
            (new M_siswa())->delete((int) $id);
            if ($accountId !== null) {
                (new M_users())->delete($accountId);
            }
            $db->transComplete();

            return $this->respond(200, $accountId === null
                ? 'Data siswa berhasil dihapus.'
                : 'Data siswa beserta akun loginnya berhasil dihapus.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * Validasi nomor kartu (SN) untuk ubah data siswa/guru. Kosong/null = hapus kartu,
     * kecuali orangnya sudah punya akun (SN dipakai untuk lupa password & absensi).
     *
     * @param array{users?: int|string|null, siswa?: int|null, guru?: string|null} $ignore
     */
    public static function validateCard(mixed $raw, bool $hasAccount, SchoolMembers $members, array $ignore, ?string &$sn): ?string
    {
        $sn = null;
        if ($raw !== null && ! is_string($raw)) {
            return 'Nomor kartu harus berupa teks.';
        }
        $value = $raw === null ? '' : trim($raw);
        if ($value === '') {
            return $hasAccount ? 'Nomor kartu wajib diisi karena sudah dipakai untuk akun login.' : null;
        }
        if (mb_strlen($value) > 50 || self::hasControlChars($value)) {
            return 'Nomor kartu maksimal 50 karakter.';
        }
        if ($members->cardInUse($value, $ignore)) {
            return 'Nomor kartu sudah dipakai.';
        }
        $sn = $value;

        return null;
    }

    /** @return array<string, string> */
    private function uniqueConflicts(array $data, int $id, ?string $accountId, SchoolMembers $members): array
    {
        $errors = [];
        try {
            if (isset($data['nis']) && (new M_siswa())->where('nis', $data['nis'])->where('id_siswa !=', $id)->countAllResults() > 0) {
                $errors['nis'] = 'NIS sudah dipakai siswa lain.';
            }
            if (isset($data['sn']) && $members->cardInUse($data['sn'], ['siswa' => $id, 'users' => $accountId])) {
                $errors['sn'] = 'Nomor kartu sudah dipakai.';
            }
        } catch (Throwable) {
            return [];
        }

        return $errors;
    }

    private function findWithClass(int $id): ?array
    {
        return (new M_siswa())->select('siswa.*, kelas.nama_kelas')->join('kelas', 'kelas.id_kelas = siswa.id_kelas', 'left')
            ->where('siswa.id_siswa', $id)->first();
    }

    public function DataKelas()
    {
        $jurusan = $this->request->getGet('id_jurusan');

        if ($jurusan !== null && ! self::validPositiveId($jurusan)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_jurusan harus berupa bilangan bulat positif',
            ]);
        }

        $kelasModel = new M_kelas();

        if ($jurusan !== null) {
            $kelasModel->where('id_jurusan', (int) $jurusan);
        }

        $data = $kelasModel->findAll();

        // Return json response 200
        return $this->response->setStatusCode(200)->setJSON([
            'status' => 200,
            'message' => 'Data berhasil diambil',
            'data' => $data,
        ]);
    }

    /** ID INT positif dari query string: hanya digit, tanpa spasi/tanda +/nol di depan. */
    public static function validPositiveId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }

    /** Ubah kolom bantu akun_id (LEFT JOIN users) menjadi has_account: true/false. */
    public static function accountFlag(array $row): array
    {
        if (array_key_exists('akun_id', $row)) {
            $row['has_account'] = $row['akun_id'] !== null && $row['akun_id'] !== '';
            unset($row['akun_id']);
        }

        return $row;
    }

    /** Akun siswa tidak menerima SN dan data pribadi orang lain. */
    private static function visibleRow(array $row): array
    {
        if ((session()->get('auth_user')['type'] ?? null) !== 'guru') {
            foreach (self::PRIVATE_FIELDS as $field) {
                unset($row[$field]);
            }
        }

        return $row;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
    }

    private static function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1 || ! mb_check_encoding($value, 'UTF-8');
    }

    private function input(): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $decoded = json_decode($this->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                throw new InvalidArgumentException('Body bukan objek JSON.');
            }

            return $decoded;
        }

        // PUT/PATCH form-urlencoded tidak masuk $_POST; baca dari body mentah.
        return strtoupper($this->request->getMethod()) === 'POST'
            ? $this->request->getPost()
            : $this->request->getRawInput();
    }

    private function respond(int $status, string $message, ?array $data = null, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => $status, 'message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    private function serverError(Throwable $e): ResponseInterface
    {
        log_message('error', 'Data siswa: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses data siswa.');
    }
}
