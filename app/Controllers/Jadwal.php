<?php

namespace App\Controllers;

use App\Models\M_guru;
use App\Models\M_jadwal;
use App\Models\M_kelas;
use App\Models\M_users;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * CRUD jadwal pelajaran. Baca: guru & siswa yang login. Tulis: hanya guru (ApiAccessFilter).
 *
 * Bentrok (409) dicek pada hari yang sama dengan jam yang tumpang tindih:
 * kelas yang sama, guru yang sama, atau ruangan yang sama tidak boleh dipakai dua kali.
 * Jadwal yang bersambung (mis. 08:00–09:00 lalu 09:00–10:00) tidak dianggap bentrok.
 */
class Jadwal extends BaseController
{
    private const PER_PAGE = 25;
    private const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];

    public function index(): ResponseInterface
    {
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        $hari = $this->request->getGet('hari');
        $kelas = $this->request->getGet('id_kelas');
        $errors = [];

        if (! $this->validId($page)) {
            $errors['page'] = 'Halaman harus berupa bilangan bulat positif.';
        }
        if ($search !== null && (! is_string($search) || mb_strlen(trim($search)) > 100)) {
            $errors['search'] = 'Pencarian harus berupa teks maksimal 100 karakter.';
        }
        if ($hari !== null && ! in_array($hari, self::DAYS, true)) {
            $errors['hari'] = 'Pilih hari yang valid.';
        }
        if ($kelas !== null && ! $this->validId($kelas)) {
            $errors['id_kelas'] = 'ID kelas harus berupa bilangan bulat positif.';
        }
        if ($errors !== []) {
            return $this->respond(422, 'Filter jadwal tidak valid.', null, $errors);
        }

        try {
            $model = $this->withNames();
            if (is_string($search) && trim($search) !== '') {
                // % dan _ dari pengguna dicari sebagai teks biasa, bukan wildcard LIKE.
                $db = db_connect();
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
                $column = $db->escapeIdentifiers($db->prefixTable('jadwal')) . '.' . $db->escapeIdentifiers('mata_pelajaran');
                $model->where("LOWER({$column}) LIKE " . $db->escape(mb_strtolower($pattern)) . " ESCAPE '!'", null, false);
            }
            if ($hari !== null) {
                $model->where('jadwal.hari', $hari);
            }
            if ($kelas !== null) {
                $model->where('jadwal.id_kelas', (int) $kelas);
            }

            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $rows = $model->orderBy($this->dayOrder(), '', false)
                ->orderBy('jadwal.jam_mulai', 'ASC')
                ->orderBy('kelas.nama_kelas', 'ASC')
                ->orderBy('jadwal.id_jadwal', 'ASC')
                ->findAll(self::PER_PAGE, $offset);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data jadwal berhasil diambil.',
                'data' => array_map(fn (array $row) => $this->format($row), $rows),
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
            return $this->invalidId();
        }

        try {
            $row = $this->findFormatted((int) $id);

            return $row === null ? $this->notFound() : $this->respond(200, 'Data jadwal berhasil diambil.', $row);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function create(): ResponseInterface
    {
        return $this->save(null);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->validId($id) ? $this->save((int) $id) : $this->invalidId();
    }

    public function delete(string $id): ResponseInterface
    {
        if (! $this->validId($id)) {
            return $this->invalidId();
        }

        try {
            $model = new M_jadwal();
            if ($model->find((int) $id) === null) {
                return $this->notFound();
            }
            $model->delete((int) $id);

            return $this->respond(200, 'Data jadwal berhasil dihapus.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function save(?int $id): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body JSON harus berupa objek yang valid.');
        }

        try {
            $model = new M_jadwal();
            if ($id !== null && $model->find($id) === null) {
                return $this->notFound();
            }

            [$data, $errors] = $this->validated($input);
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }
            $conflict = $this->conflict($data, $id);
            if ($conflict !== null) {
                return $this->respond(409, $conflict[1], null, [$conflict[0] => $conflict[1]]);
            }

            if ($id === null) {
                $id = (int) $model->insert($data);

                return $this->respond(201, 'Data jadwal berhasil ditambahkan.', $this->findFormatted($id));
            }
            $model->update($id, $data);

            return $this->respond(200, 'Data jadwal berhasil diperbarui.', $this->findFormatted($id));
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validated(array $input): array
    {
        $data = [];
        $errors = [];

        $mapel = $this->text($input, 'mata_pelajaran');
        if ($mapel === null || $mapel === '') {
            $errors['mata_pelajaran'] = 'Mata pelajaran wajib diisi.';
        } elseif (mb_strlen($mapel) > 100) {
            $errors['mata_pelajaran'] = 'Mata pelajaran maksimal 100 karakter.';
        } elseif ($this->hasControlChars($mapel)) {
            $errors['mata_pelajaran'] = 'Mata pelajaran tidak boleh berisi karakter kontrol.';
        } else {
            $data['mata_pelajaran'] = $mapel;
        }

        // FE mengirim ID kelas sebagai teks; angka JSON juga diterima.
        $kelas = is_int($input['id_kelas'] ?? null) ? (string) $input['id_kelas'] : $this->text($input, 'id_kelas');
        if (! is_string($kelas) || ! $this->validId($kelas) || (new M_kelas())->find((int) $kelas) === null) {
            $errors['id_kelas'] = 'Pilih kelas dari daftar kelas yang terdaftar.';
        } else {
            $data['id_kelas'] = (int) $kelas;
        }

        // Hanya ID guru (UUID atau ID numerik lama) dari /api/data_guru yang diterima.
        $guruInput = $this->text($input, 'id_guru');
        $guruId = $guruInput === null || $guruInput === '' ? null : M_users::normalizeId($guruInput);
        $guru = $guruId === null ? null : (new M_guru())->find((string) $guruId);
        if ($guru === null) {
            $errors['id_guru'] = 'Pilih guru dari daftar guru yang terdaftar.';
        } else {
            $data['id_guru'] = (string) $guru['id_user'];
        }

        $hari = strtolower($this->text($input, 'hari') ?? '');
        if (in_array($hari, self::DAYS, true)) {
            $data['hari'] = $hari;
        } else {
            $errors['hari'] = 'Pilih hari yang valid.';
        }

        foreach (['jam_mulai' => 'Jam mulai', 'jam_selesai' => 'Jam selesai'] as $field => $label) {
            $time = $this->text($input, $field) ?? '';
            if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/', $time)) {
                // Selalu HH:MM:00 agar perbandingan teks (SQLite) dan TIME (MySQL) konsisten.
                $data[$field] = substr($time, 0, 5) . ':00';
            } else {
                $errors[$field] = "{$label} harus berformat HH:mm.";
            }
        }
        if (isset($data['jam_mulai'], $data['jam_selesai']) && $data['jam_selesai'] <= $data['jam_mulai']) {
            $errors['jam_selesai'] = 'Jam selesai harus setelah jam mulai pada hari yang sama.';
        }

        $ruangan = $this->text($input, 'ruangan');
        if ($ruangan === false) {
            $errors['ruangan'] = 'Ruangan harus berupa teks.';
        } elseif ($ruangan === null || $ruangan === '') {
            $data['ruangan'] = null;
        } elseif (mb_strlen($ruangan) > 50) {
            $errors['ruangan'] = 'Ruangan maksimal 50 karakter.';
        } elseif ($this->hasControlChars($ruangan)) {
            $errors['ruangan'] = 'Ruangan tidak boleh berisi karakter kontrol.';
        } else {
            $data['ruangan'] = $ruangan;
        }

        return [$data, $errors];
    }

    /** @return array{0: string, 1: string}|null [field, pesan] untuk bentrok pertama. */
    private function conflict(array $data, ?int $exceptId): ?array
    {
        $checks = [
            ['id_kelas', 'jadwal.id_kelas', $data['id_kelas'], 'Kelas ini sudah punya jadwal lain'],
            ['id_guru', 'jadwal.id_guru', $data['id_guru'], 'Guru ini sudah mengajar di jadwal lain'],
        ];
        foreach ($checks as [$field, $column, $value, $message]) {
            $row = $this->overlapping($data, $exceptId)->where($column, $value)->first();
            if ($row !== null) {
                return [$field, $this->conflictMessage($message, $row)];
            }
        }
        if ($data['ruangan'] !== null) {
            $room = db_connect()->escape(mb_strtolower($data['ruangan']));
            $row = $this->overlapping($data, $exceptId)->where('LOWER(' . $this->column('ruangan') . ") = {$room}", null, false)->first();
            if ($row !== null) {
                return ['ruangan', $this->conflictMessage('Ruangan ini sudah dipakai jadwal lain', $row)];
            }
        }

        return null;
    }

    private function overlapping(array $data, ?int $exceptId): M_jadwal
    {
        // Tumpang tindih: mulai_lain < selesai_baru DAN selesai_lain > mulai_baru.
        $query = $this->withNames()->where('jadwal.hari', $data['hari'])
            ->where('jadwal.jam_mulai <', $data['jam_selesai'])
            ->where('jadwal.jam_selesai >', $data['jam_mulai']);
        if ($exceptId !== null) {
            $query->where('jadwal.id_jadwal !=', $exceptId);
        }

        return $query->orderBy('jadwal.jam_mulai', 'ASC');
    }

    private function conflictMessage(string $prefix, array $row): string
    {
        $other = $this->format($row);

        return sprintf(
            '%s pada jam tersebut: %s (%s, %s–%s). Periksa hari, jam, guru, dan ruangan.',
            $prefix,
            $other['mata_pelajaran'],
            $other['nama_kelas'] ?? 'kelas ?',
            $other['jam_mulai'],
            $other['jam_selesai'],
        );
    }

    private function withNames(): M_jadwal
    {
        return (new M_jadwal())->select('jadwal.*, kelas.nama_kelas, guru.nama AS nama_guru')
            ->join('kelas', 'kelas.id_kelas = jadwal.id_kelas', 'left')
            ->join('guru', 'guru.id_user = jadwal.id_guru', 'left');
    }

    private function findFormatted(int $id): ?array
    {
        $row = $this->withNames()->where('jadwal.id_jadwal', $id)->first();

        return $row === null ? null : $this->format($row);
    }

    private function format(array $row): array
    {
        return [
            'id_jadwal' => (int) $row['id_jadwal'],
            'mata_pelajaran' => $row['mata_pelajaran'],
            'id_kelas' => (int) $row['id_kelas'],
            'nama_kelas' => $row['nama_kelas'] ?? null,
            'id_guru' => $row['id_guru'] ?? null,
            'nama_guru' => $row['nama_guru'] ?? null,
            'hari' => $row['hari'],
            'jam_mulai' => substr((string) $row['jam_mulai'], 0, 5),
            'jam_selesai' => substr((string) $row['jam_selesai'], 0, 5),
            'ruangan' => $row['ruangan'] ?? null,
        ];
    }

    /** Urutan Senin → Minggu (bukan alfabet). */
    private function dayOrder(): string
    {
        $cases = '';
        foreach (self::DAYS as $index => $day) {
            $cases .= " WHEN '{$day}' THEN {$index}";
        }

        return 'CASE ' . $this->column('hari') . "{$cases} ELSE 7 END";
    }

    /** Nama kolom jadwal lengkap dengan prefix tabel, untuk potongan SQL mentah. */
    private function column(string $name): string
    {
        $db = db_connect();

        return $db->escapeIdentifiers($db->prefixTable('jadwal')) . '.' . $db->escapeIdentifiers($name);
    }

    /** null = tidak dikirim, false = bukan teks, string = nilai yang sudah di-trim. */
    private function text(array $input, string $field): string|false|null
    {
        if (! array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }

        return is_string($input[$field]) ? trim($input[$field]) : false;
    }

    private function hasControlChars(string $value): bool
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

        return strtoupper($this->request->getMethod()) === 'POST'
            ? $this->request->getPost()
            : $this->request->getRawInput();
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }

    private function invalidId(): ResponseInterface
    {
        return $this->respond(422, 'ID jadwal tidak valid.', null, ['id_jadwal' => 'ID jadwal harus berupa bilangan bulat positif.']);
    }

    private function notFound(): ResponseInterface
    {
        return $this->respond(404, 'Jadwal tidak ditemukan.');
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
        log_message('error', 'Jadwal: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses data jadwal.');
    }
}
