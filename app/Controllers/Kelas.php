<?php

namespace App\Controllers;

use App\Models\M_guru;
use App\Models\M_kelas;
use App\Models\M_users;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * CRUD data kelas berdasarkan tabel `kelas` (id_kelas, nama_kelas, id_jurusan, tahun_ajaran, status).
 * Baca: guru & siswa yang login. Tulis: hanya guru (dibatasi ApiAccessFilter).
 */
class Kelas extends BaseController
{
    private const PER_PAGE = 25;
    private const STATUSES = ['aktif', 'nonaktif'];

    public function index(): ResponseInterface
    {
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        $jurusan = $this->request->getGet('id_jurusan');
        $status = $this->request->getGet('status');
        $errors = [];

        if (! $this->validId($page)) {
            $errors['page'] = 'Halaman harus berupa bilangan bulat positif.';
        }
        if ($search !== null && (! is_string($search) || mb_strlen(trim($search)) > 100)) {
            $errors['search'] = 'Pencarian harus berupa teks maksimal 100 karakter.';
        }
        if ($jurusan !== null && ! $this->validId($jurusan)) {
            $errors['id_jurusan'] = 'id_jurusan harus berupa bilangan bulat positif.';
        }
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'Status harus aktif atau nonaktif.';
        }
        if ($errors !== []) {
            return $this->respond(422, 'Filter data kelas tidak valid.', null, $errors);
        }

        try {
            $model = $this->withStudentCount();
            if (is_string($search) && trim($search) !== '') {
                // % dan _ dari pengguna dicari sebagai teks biasa, bukan wildcard LIKE.
                $db = db_connect();
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
                $column = $db->escapeIdentifiers($db->prefixTable('kelas')) . '.' . $db->escapeIdentifiers('nama_kelas');
                $model->where("LOWER({$column}) LIKE " . $db->escape(mb_strtolower($pattern)) . " ESCAPE '!'", null, false);
            }
            if ($jurusan !== null) {
                $model->where('kelas.id_jurusan', (int) $jurusan);
            }
            if ($status !== null) {
                $model->where('kelas.status', $status);
            }

            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $rows = $model->orderBy('kelas.nama_kelas', 'ASC')->orderBy('kelas.id_kelas', 'ASC')
                ->findAll(self::PER_PAGE, $offset);
            $rows = array_map(fn (array $row) => $this->format($row), $rows);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data kelas berhasil diambil.',
                'data' => $rows,
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

    public function show(string $id): ResponseInterface
    {
        if (! $this->validId($id)) {
            return $this->respond(422, 'ID kelas tidak valid.', null, ['id_kelas' => 'ID kelas harus berupa bilangan bulat positif.']);
        }

        try {
            $row = $this->findWithCount((int) $id);

            return $row === null
                ? $this->respond(404, 'Kelas tidak ditemukan.')
                : $this->respond(200, 'Data kelas berhasil diambil.', $row);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function create(): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            [$data, $errors] = $this->validated($input, true);
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }
            if ($this->nameTaken($data['nama_kelas'])) {
                return $this->duplicateName();
            }
            if ($this->homeroomTaken($data['id_wali_kelas'] ?? null)) {
                return $this->homeroomConflict();
            }

            $data += ['status' => 'aktif'];
            $model = new M_kelas();
            try {
                $id = $model->insert($data);
            } catch (DatabaseException $e) {
                return $this->conflictAfterFailure($data) ?? throw $e;
            }
            if ($id === false) {
                return $this->respond(422, 'Validasi gagal.', null, $model->errors());
            }

            return $this->respond(201, 'Data kelas berhasil ditambahkan.', $this->findWithCount((int) $id));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** PUT dan PATCH sama-sama memperbarui sebagian: field yang tidak dikirim tidak diubah. */
    public function update(string $id): ResponseInterface
    {
        if (! $this->validId($id)) {
            return $this->respond(422, 'ID kelas tidak valid.', null, ['id_kelas' => 'ID kelas harus berupa bilangan bulat positif.']);
        }

        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $model = new M_kelas();
            if ($model->find((int) $id) === null) {
                return $this->respond(404, 'Kelas tidak ditemukan.');
            }

            [$data, $errors] = $this->validated($input, false);
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }
            if ($data === []) {
                return $this->respond(422, 'Validasi gagal.', null, [
                    'nama_kelas' => 'Kirim minimal satu field: nama_kelas, id_jurusan, tahun_ajaran, status, atau id_wali_kelas.',
                ]);
            }
            if (isset($data['nama_kelas']) && $this->nameTaken($data['nama_kelas'], (int) $id)) {
                return $this->duplicateName();
            }
            if ($this->homeroomTaken($data['id_wali_kelas'] ?? null, (int) $id)) {
                return $this->homeroomConflict();
            }

            try {
                $updated = $model->update((int) $id, $data);
            } catch (DatabaseException $e) {
                return $this->conflictAfterFailure($data, (int) $id) ?? throw $e;
            }
            if (! $updated) {
                return $this->respond(422, 'Validasi gagal.', null, $model->errors());
            }

            return $this->respond(200, 'Data kelas berhasil diperbarui.', $this->findWithCount((int) $id));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function delete(string $id): ResponseInterface
    {
        if (! $this->validId($id)) {
            return $this->respond(422, 'ID kelas tidak valid.', null, ['id_kelas' => 'ID kelas harus berupa bilangan bulat positif.']);
        }

        try {
            $row = $this->findWithCount((int) $id);
            if ($row === null) {
                return $this->respond(404, 'Kelas tidak ditemukan.');
            }
            // FK siswa.id_kelas memakai ON DELETE SET NULL; jangan lepaskan siswa dari kelas diam-diam.
            if ($row['jumlah_siswa'] > 0) {
                return $this->respond(409, 'Kelas masih memiliki siswa. Pindahkan siswa terlebih dahulu.');
            }
            if (! (new M_kelas())->delete((int) $id)) {
                throw new \RuntimeException('Gagal menghapus kelas.');
            }

            return $this->respond(200, 'Data kelas berhasil dihapus.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validated(array $input, bool $creating): array
    {
        $data = [];
        $errors = [];

        if ($creating || array_key_exists('nama_kelas', $input)) {
            $nama = $input['nama_kelas'] ?? null;
            $nama = is_string($nama) ? trim($nama) : $nama;
            if (! is_string($nama) || $nama === '') {
                $errors['nama_kelas'] = 'Nama kelas wajib diisi.';
            } elseif (mb_strlen($nama) > 100) {
                $errors['nama_kelas'] = 'Nama kelas maksimal 100 karakter.';
            } elseif (preg_match('/[\x00-\x1F\x7F]/u', $nama) || ! mb_check_encoding($nama, 'UTF-8')) {
                $errors['nama_kelas'] = 'Nama kelas tidak boleh berisi karakter kontrol.';
            } else {
                $data['nama_kelas'] = $nama;
            }
        }

        if (array_key_exists('id_jurusan', $input)) {
            $jurusan = $input['id_jurusan'];
            if ($jurusan === null || $jurusan === '') {
                $data['id_jurusan'] = null;
            } elseif (is_int($jurusan) ? $jurusan > 0 && $jurusan <= 2147483647 : $this->validId($jurusan)) {
                $data['id_jurusan'] = (int) $jurusan;
            } else {
                $errors['id_jurusan'] = 'id_jurusan harus berupa bilangan bulat positif.';
            }
        }

        if (array_key_exists('tahun_ajaran', $input)) {
            $tahun = $input['tahun_ajaran'];
            if ($tahun === null || $tahun === '') {
                $data['tahun_ajaran'] = null;
            } elseif (is_string($tahun) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $tahun)
                && checkdate((int) substr($tahun, 5, 2), (int) substr($tahun, 8, 2), (int) substr($tahun, 0, 4))) {
                $data['tahun_ajaran'] = $tahun;
            } else {
                $errors['tahun_ajaran'] = 'tahun_ajaran harus berupa tanggal valid dengan format YYYY-MM-DD.';
            }
        }

        if (array_key_exists('status', $input)) {
            $status = is_string($input['status']) ? strtolower(trim($input['status'])) : null;
            if (in_array($status, self::STATUSES, true)) {
                $data['status'] = $status;
            } else {
                $errors['status'] = 'Status harus aktif atau nonaktif.';
            }
        }

        if (array_key_exists('id_wali_kelas', $input)) {
            $wali = is_string($input['id_wali_kelas']) ? trim($input['id_wali_kelas']) : $input['id_wali_kelas'];
            if ($wali === null || $wali === '') {
                $data['id_wali_kelas'] = null;
            } else {
                // Hanya ID teks (UUID atau ID numerik lama) dari /api/data_guru yang diterima.
                $guruId = is_string($wali) ? M_users::normalizeId($wali) : null;
                $guru = $guruId === null ? null : (new M_guru())->find((string) $guruId);
                if ($guru === null) {
                    $errors['id_wali_kelas'] = 'Pilih wali kelas dari daftar guru yang terdaftar.';
                } else {
                    // Simpan ID persis seperti di database agar perbandingan konsisten.
                    $data['id_wali_kelas'] = (string) $guru['id_user'];
                }
            }
        }

        return [$data, $errors];
    }

    private function withStudentCount(): M_kelas
    {
        $db = db_connect();
        $siswa = $db->escapeIdentifiers($db->prefixTable('siswa'));
        $kelas = $db->escapeIdentifiers($db->prefixTable('kelas'));

        return (new M_kelas())->select('kelas.*, guru.nama AS nama_wali_kelas')
            ->select("(SELECT COUNT(*) FROM {$siswa} WHERE {$siswa}.id_kelas = {$kelas}.id_kelas) AS jumlah_siswa", false)
            ->join('guru', 'guru.id_user = kelas.id_wali_kelas', 'left');
    }

    private function findWithCount(int $id): ?array
    {
        $row = $this->withStudentCount()->where('kelas.id_kelas', $id)->first();

        return $row === null ? null : $this->format($row);
    }

    private function format(array $row): array
    {
        return [
            'id_kelas' => (int) $row['id_kelas'],
            'nama_kelas' => $row['nama_kelas'],
            'id_jurusan' => $row['id_jurusan'] === null ? null : (int) $row['id_jurusan'],
            'tahun_ajaran' => $row['tahun_ajaran'] ?? null,
            'status' => $row['status'] ?? null,
            'id_wali_kelas' => $row['id_wali_kelas'] ?? null,
            'nama_wali_kelas' => $row['nama_wali_kelas'] ?? null,
            'jumlah_siswa' => (int) ($row['jumlah_siswa'] ?? 0),
        ];
    }

    /** Satu guru hanya boleh menjadi wali untuk satu kelas. */
    private function homeroomTaken(?string $guruId, ?int $exceptId = null): bool
    {
        if ($guruId === null) {
            return false;
        }
        $query = (new M_kelas())->where('id_wali_kelas', $guruId);
        if ($exceptId !== null) {
            $query->where('id_kelas !=', $exceptId);
        }

        return $query->countAllResults() > 0;
    }

    private function homeroomConflict(): ResponseInterface
    {
        return $this->respond(409, 'Guru tersebut sudah menjadi wali kelas lain.', null, [
            'id_wali_kelas' => 'Guru tersebut sudah menjadi wali kelas lain.',
        ]);
    }

    /** Request bersamaan bisa lolos pengecekan; unique key database jadi pengaman terakhir. */
    private function conflictAfterFailure(array $data, ?int $exceptId = null): ?ResponseInterface
    {
        try {
            if (isset($data['nama_kelas']) && $this->nameTaken($data['nama_kelas'], $exceptId)) {
                return $this->duplicateName();
            }
            if ($this->homeroomTaken($data['id_wali_kelas'] ?? null, $exceptId)) {
                return $this->homeroomConflict();
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function nameTaken(string $nama, ?int $exceptId = null): bool
    {
        $query = (new M_kelas())->where('LOWER(nama_kelas)', mb_strtolower($nama));
        if ($exceptId !== null) {
            $query->where('id_kelas !=', $exceptId);
        }

        return $query->countAllResults() > 0;
    }

    private function duplicateName(): ResponseInterface
    {
        return $this->respond(409, 'Nama kelas sudah dipakai.', null, ['nama_kelas' => 'Nama kelas sudah dipakai.']);
    }

    private function input(): array
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $decoded = json_decode($this->request->getBody(), false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new InvalidArgumentException('Body bukan objek JSON.');
            }

            return (array) $decoded;
        }

        return strtoupper($this->request->getMethod()) === 'POST'
            ? $this->request->getPost()
            : $this->request->getRawInput();
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
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
        log_message('error', 'Kelas: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses data kelas.');
    }
}
