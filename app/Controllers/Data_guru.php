<?php

namespace App\Controllers;

use App\Libraries\SchoolMembers;
use App\Models\M_kelas;
use App\Models\M_guru;
use App\Models\M_users;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Throwable;

class Data_guru extends BaseController
{
    private const PER_PAGE = 25;

    /** GET ?page=&search= — cari nama atau NIP, 25 per halaman. */
    private function paginated(): ResponseInterface
    {
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        if (! Data_siswa::validPositiveId($page)) {
            return $this->respond(422, 'Halaman harus berupa bilangan bulat positif.', null, ['page' => 'Halaman tidak valid.']);
        }
        if ($search !== null && (! is_string($search) || mb_strlen(trim($search)) > 100)) {
            return $this->respond(422, 'Pencarian tidak valid.', null, ['search' => 'Pencarian harus berupa teks maksimal 100 karakter.']);
        }

        try {
            $db = db_connect();
            // akun_id: penanda guru sudah punya akun login (has_account) untuk form edit/hapus.
            $model = (new M_guru())->select('guru.*, users.id_user AS akun_id')
                ->join('users', 'users.id_user = guru.id_user', 'left');
            if (is_string($search) && trim($search) !== '') {
                // % dan _ dari pengguna dicari sebagai teks biasa, bukan wildcard LIKE.
                $pattern = $db->escape(mb_strtolower('%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%'));
                $table = $db->escapeIdentifiers($db->prefixTable('guru'));
                $model->where("(LOWER({$table}.nama) LIKE {$pattern} ESCAPE '!' OR LOWER({$table}.nip) LIKE {$pattern} ESCAPE '!')", null, false);
            }
            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $rows = $model->orderBy('guru.nama', 'ASC')->orderBy('guru.id_user', 'ASC')->findAll(self::PER_PAGE, $offset);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data berhasil diambil',
                'data' => array_map(static fn (array $row) => self::visibleRow(Data_siswa::accountFlag($row)), $rows),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'from' => $rows === [] ? null : $offset + 1,
                    'to' => $rows === [] ? null : $offset + count($rows),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** POST — Tambah Guru (khusus guru). Data tanpa akun; akun nyusul lewat registrasi dengan SN yang sama. */
    public function create(): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $errors = [];
            $nama = self::text($input['nama'] ?? null);
            if ($nama === null || $nama === '' || mb_strlen($nama) > 100 || self::hasControlChars($nama)) {
                $errors['nama'] = 'Nama guru wajib diisi, maksimal 100 karakter.';
            }
            $nip = self::text($input['nip'] ?? null);
            if ($nip === null || ! preg_match(Data_siswa::NIS_PATTERN, $nip)) {
                $errors['nip'] = 'NIP wajib diisi (maksimal 30 karakter: angka, huruf, titik, strip, atau garis miring).';
            } elseif ((new M_guru())->where('nip', $nip)->countAllResults() > 0) {
                $errors['nip'] = 'NIP sudah dipakai guru lain.';
            }
            $mapel = self::text($input['mata_pelajaran'] ?? null);
            if ($mapel === null || $mapel === '' || mb_strlen($mapel) > 100 || self::hasControlChars($mapel)) {
                $errors['mata_pelajaran'] = 'Mata pelajaran wajib diisi, maksimal 100 karakter.';
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

            // id_user adalah primary key guru. Sebelum ada akun, pakai UUID sementara yang tidak ada di
            // tabel users; saat registrasi, UUID ini diganti ID akun (FK lain ikut lewat ON UPDATE CASCADE).
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
            $id = M_users::generateUuid();
            (new M_guru())->insert([
                'id_user' => $id, 'nama' => $nama, 'nip' => $nip, 'mata_pelajaran' => $mapel, 'sn' => $sn,
                'status' => 'ACTIVE', 'type' => 'guru', 'created_date' => $now, 'update_date' => $now,
            ]);

            return $this->respond(201, 'Data guru berhasil ditambahkan.', self::visibleRow((new M_guru())->find($id)));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * PUT/PATCH /api/data_guru/{id_guru} — ubah data guru (khusus guru, dibatasi ApiAccessFilter).
     * Field yang tidak dikirim tidak berubah. Bila guru sudah punya akun, nama dan nomor kartu (SN)
     * pada akun ikut diperbarui.
     */
    public function update(string $id): ResponseInterface
    {
        $guruId = M_users::normalizeId($id);
        if ($guruId === null) {
            return $this->respond(422, 'ID guru tidak valid.', null, ['id_guru' => 'ID guru tidak valid.']);
        }
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $db = db_connect();
            $teacher = (new M_guru())->find((string) $guruId);
            if ($teacher === null) {
                return $this->respond(404, 'Data guru tidak ditemukan.');
            }
            $key = (string) $teacher['id_user'];
            $members = new SchoolMembers($db);
            $accountId = $members->accountId($key);

            $data = [];
            $errors = [];
            foreach (['nama' => 'Nama guru', 'mata_pelajaran' => 'Mata pelajaran'] as $field => $label) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }
                $value = self::text($input[$field]);
                if ($value === null || $value === '' || mb_strlen($value) > 100 || self::hasControlChars($value)) {
                    $errors[$field] = "{$label} wajib diisi, maksimal 100 karakter.";
                } else {
                    $data[$field] = $value;
                }
            }
            if (array_key_exists('nip', $input)) {
                $nip = self::text($input['nip']);
                if ($nip === null || ! preg_match(Data_siswa::NIS_PATTERN, $nip)) {
                    $errors['nip'] = 'NIP wajib diisi (maksimal 30 karakter: angka, huruf, titik, strip, atau garis miring).';
                } elseif ((new M_guru())->where('nip', $nip)->where('id_user !=', $key)->countAllResults() > 0) {
                    $errors['nip'] = 'NIP sudah dipakai guru lain.';
                } else {
                    $data['nip'] = $nip;
                }
            }
            if (array_key_exists('sn', $input)) {
                $snError = Data_siswa::validateCard($input['sn'], $accountId !== null, $members, ['guru' => $key, 'users' => $accountId], $sn);
                if ($snError !== null) {
                    $errors['sn'] = $snError;
                } else {
                    $data['sn'] = $sn;
                }
            }
            if (array_key_exists('status', $input)) {
                $status = is_string($input['status']) ? strtoupper(trim($input['status'])) : null;
                if (in_array($status, Data_siswa::STATUSES, true)) {
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
                    'nama' => 'Kirim minimal satu field: nama, nip, mata_pelajaran, sn, atau status.',
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
            $account = array_intersect_key($data, ['nama' => true, 'sn' => true]);
            try {
                $db->transException(true)->transStart();
                (new M_guru())->update($key, $data + ['update_date' => $now]);
                if ($accountId !== null && $account !== []) {
                    (new M_users())->update($accountId, $account + ['update_date' => $now]);
                }
                $db->transComplete();
            } catch (DatabaseException $e) {
                // Request bersamaan bisa lolos pengecekan; unique key database (NIP/SN) jadi pengaman terakhir.
                $conflict = [];
                if (isset($data['nip']) && (new M_guru())->where('nip', $data['nip'])->where('id_user !=', $key)->countAllResults() > 0) {
                    $conflict['nip'] = 'NIP sudah dipakai guru lain.';
                }
                if (isset($data['sn']) && $members->cardInUse($data['sn'], ['guru' => $key, 'users' => $accountId])) {
                    $conflict['sn'] = 'Nomor kartu sudah dipakai.';
                }
                if ($conflict !== []) {
                    return $this->respond(422, 'Validasi gagal.', null, $conflict);
                }
                throw $e;
            }

            if ($accountId !== null && isset($data['nama'])
                && (string) (session()->get('auth_user')['id_user'] ?? '') === $accountId) {
                // Guru mengubah datanya sendiri: nama di header langsung ikut berubah.
                session()->set('auth_user', ['nama' => $data['nama']] + session()->get('auth_user'));
            }

            return $this->respond(200, 'Data guru berhasil diperbarui.',
                self::visibleRow((new M_guru())->find($key) + ['has_account' => $accountId !== null]));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /api/data_guru/{id_guru} — hapus data guru (khusus guru). Akun login guru ikut dihapus.
     * Wali kelas, jadwal, dan pembina ekskul yang memakai guru ini menjadi kosong (FK ON DELETE SET NULL).
     */
    public function delete(string $id): ResponseInterface
    {
        $guruId = M_users::normalizeId($id);
        if ($guruId === null) {
            return $this->respond(422, 'ID guru tidak valid.', null, ['id_guru' => 'ID guru tidak valid.']);
        }

        try {
            $db = db_connect();
            $teacher = (new M_guru())->find((string) $guruId);
            if ($teacher === null) {
                return $this->respond(404, 'Data guru tidak ditemukan.');
            }
            $key = (string) $teacher['id_user'];
            if ((string) (session()->get('auth_user')['id_user'] ?? '') === $key) {
                return $this->respond(409, 'Anda tidak dapat menghapus data guru milik akun Anda sendiri.');
            }
            $accountId = (new SchoolMembers($db))->accountId($key);
            if ($accountId !== null && $db->tableExists('absensi')
                && $db->table('absensi')->where('id_user', $accountId)->countAllResults() > 0) {
                return $this->respond(409, 'Akun guru ini memiliki riwayat absensi sehingga tidak dapat dihapus. Ubah statusnya menjadi Nonaktif.');
            }

            $db->transException(true)->transStart();
            (new M_guru())->delete($key);
            if ($accountId !== null) {
                (new M_users())->delete($accountId);
            }
            $db->transComplete();

            return $this->respond(200, $accountId === null
                ? 'Data guru berhasil dihapus.'
                : 'Data guru beserta akun loginnya berhasil dihapus.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * Frontend (dropdown wali kelas & halaman guru) membaca ID guru dari `id_guru`.
     * Primary key guru adalah id_user, jadi kirim keduanya dengan nilai yang sama.
     * SN kartu (untuk reset password) dan data pribadi hanya untuk guru; siswa melihat data umum.
     */
    private static function visibleRow(array $row): array
    {
        if ((session()->get('auth_user')['type'] ?? null) !== 'guru') {
            foreach (Data_siswa::PRIVATE_FIELDS as $field) {
                unset($row[$field]);
            }
        }

        return $row + ['id_guru' => $row['id_user'] ?? null];
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
        log_message('error', 'Data guru: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses data guru.');
    }
    public function DataGuru()
    {
        $idUser = $this->request->getGet('id_user');
        $id_guru = $this->request->getGet('id_guru');

        if ($idUser !== null && M_users::normalizeId($idUser) === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_user harus berupa UUID atau ID numerik lama yang valid',
            ]);
        }

        if ($id_guru !== null && ! is_int(M_users::normalizeId($id_guru))) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 400,
                'message' => 'id_guru harus berupa bilangan bulat positif',
            ]);
        }

        // Halaman Data Guru & dropdown guru mengirim page/search: pakai daftar berhalaman + pencarian.
        if ($idUser === null && $id_guru === null
            && ($this->request->getGet('page') !== null || $this->request->getGet('search') !== null)) {
            return $this->paginated();
        }

        $guruModel = new M_guru();
        // id_guru tetap diterima untuk ID numerik lama; akun baru memakai UUID id_user.
        $identifier = M_users::normalizeId($idUser ?? $id_guru);
        $data = $identifier === null ? $guruModel->findAll() : $guruModel->find((string) $identifier);

        $withAlias = self::visibleRow(...);
        if ($data !== null) {
            $data = $identifier === null ? array_map($withAlias, $data) : $withAlias($data);
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

    public function DataKelas() 
    {
        $jurusan = $this->request->getGet('id_jurusan');

        if ($jurusan !== null && filter_var($jurusan, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
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
}
