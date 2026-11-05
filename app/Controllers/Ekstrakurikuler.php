<?php

namespace App\Controllers;

use App\Libraries\PhotoUpload;
use App\Models\M_ekstrakurikuler;
use App\Models\M_ekstrakurikuler_foto;
use App\Models\M_guru;
use App\Models\M_users;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * CRUD ekstrakurikuler + foto kegiatan.
 * Baca: guru & siswa yang login. Tulis: hanya guru (dibatasi ApiAccessFilter).
 *
 * Body tulis bisa JSON (tanpa foto) atau multipart/form-data (dengan foto[] / hapus_foto[]).
 * Update multipart dikirim sebagai POST + _method=PUT (method spoofing CodeIgniter).
 */
class Ekstrakurikuler extends BaseController
{
    private const PER_PAGE = 25;
    private const MAX_PHOTOS = 5;
    private const PHOTO_URL_PATH = 'uploads/ekstrakurikuler/';
    private const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
    private const STATUSES = ['ACTIVE', 'INACTIVE'];

    public function index(): ResponseInterface
    {
        $page = $this->request->getGet('page') ?? '1';
        $search = $this->request->getGet('search');
        $hari = $this->request->getGet('hari');
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
        if ($errors !== []) {
            return $this->respond(422, 'Filter data ekstrakurikuler tidak valid.', null, $errors);
        }

        try {
            $model = $this->withTeacher();
            if (is_string($search) && trim($search) !== '') {
                // % dan _ dari pengguna dicari sebagai teks biasa, bukan wildcard LIKE.
                $db = db_connect();
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
                $column = $db->escapeIdentifiers($db->prefixTable('ekstrakurikuler')) . '.' . $db->escapeIdentifiers('nama_ekstrakurikuler');
                $model->where("LOWER({$column}) LIKE " . $db->escape(mb_strtolower($pattern)) . " ESCAPE '!'", null, false);
            }
            if ($hari !== null) {
                $model->where('ekstrakurikuler.hari', $hari);
            }

            $total = $model->countAllResults(false);
            $page = (int) $page;
            $offset = ($page - 1) * self::PER_PAGE;
            $rows = $model->orderBy('ekstrakurikuler.nama_ekstrakurikuler', 'ASC')
                ->orderBy('ekstrakurikuler.id_ekstrakurikuler', 'ASC')
                ->findAll(self::PER_PAGE, $offset);
            $photos = $this->photosFor(array_column($rows, 'id_ekstrakurikuler'));
            $rows = array_map(fn (array $row) => $this->format($row, $photos[(int) $row['id_ekstrakurikuler']] ?? []), $rows);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Data ekstrakurikuler berhasil diambil.',
                'data' => $rows,
                'meta' => $this->meta($page, $total, $offset, count($rows), self::PER_PAGE),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /** Foto terbaru dari ekstrakurikuler aktif, untuk carousel. */
    public function photos(): ResponseInterface
    {
        $perPage = $this->request->getGet('per_page') ?? (string) self::MAX_PHOTOS;
        if (! $this->validId($perPage) || (int) $perPage > 50) {
            return $this->respond(422, 'Filter foto tidak valid.', null, ['per_page' => 'per_page harus berupa bilangan 1 sampai 50.']);
        }

        try {
            $rows = (new M_ekstrakurikuler_foto())
                ->select('ekstrakurikuler_foto.id_foto, ekstrakurikuler_foto.file_name, ekstrakurikuler.nama_ekstrakurikuler')
                ->join('ekstrakurikuler', 'ekstrakurikuler.id_ekstrakurikuler = ekstrakurikuler_foto.id_ekstrakurikuler')
                ->where('ekstrakurikuler.status', 'ACTIVE')
                ->orderBy('ekstrakurikuler_foto.id_foto', 'DESC')
                ->findAll((int) $perPage);
            $rows = array_map(fn (array $row) => $this->formatPhoto($row, $row['nama_ekstrakurikuler']), $rows);

            return $this->response->setStatusCode(200)->setJSON([
                'status' => 200,
                'message' => 'Foto ekstrakurikuler berhasil diambil.',
                'data' => $rows,
                'meta' => $this->meta(1, count($rows), 0, count($rows), (int) $perPage),
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

            return $row === null
                ? $this->notFound()
                : $this->respond(200, 'Data ekstrakurikuler berhasil diambil.', $row);
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
        if (! $this->validId($id)) {
            return $this->invalidId();
        }

        return $this->save((int) $id);
    }

    public function delete(string $id): ResponseInterface
    {
        if (! $this->validId($id)) {
            return $this->invalidId();
        }

        try {
            $model = new M_ekstrakurikuler();
            if ($model->find((int) $id) === null) {
                return $this->notFound();
            }
            $files = array_column((new M_ekstrakurikuler_foto())->where('id_ekstrakurikuler', (int) $id)->findAll(), 'file_name');

            $db = db_connect();
            $db->transException(true)->transStart();
            (new M_ekstrakurikuler_foto())->where('id_ekstrakurikuler', (int) $id)->delete();
            $model->delete((int) $id);
            $db->transComplete();
            $this->removeFiles($files);

            return $this->respond(200, 'Data ekstrakurikuler berhasil dihapus.');
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function save(?int $id): ResponseInterface
    {
        try {
            $input = $this->input();
        } catch (JsonException | InvalidArgumentException) {
            return $this->respond(400, 'Body harus berupa objek JSON atau form yang valid.');
        }

        $moved = [];
        $created = $id === null;
        try {
            $model = new M_ekstrakurikuler();
            if ($id !== null && $model->find($id) === null) {
                return $this->notFound();
            }

            [$data, $errors] = $this->validated($input);
            [$files, $fileErrors] = $this->uploadedPhotos();
            [$removeIds, $removeErrors] = $this->removedPhotos($input, $id);
            $errors += $fileErrors + $removeErrors;
            if (! isset($errors['nama_ekstrakurikuler']) && $this->nameTaken($data['nama_ekstrakurikuler'], $id)) {
                $errors['nama_ekstrakurikuler'] = 'Nama ekstrakurikuler sudah dipakai.';
            }
            if (! isset($errors['foto'])) {
                $existing = $id === null ? 0 : (new M_ekstrakurikuler_foto())->where('id_ekstrakurikuler', $id)->countAllResults();
                if ($existing - count($removeIds) + count($files) > self::MAX_PHOTOS) {
                    $errors['foto'] = 'Maksimal ' . self::MAX_PHOTOS . ' foto per kegiatan. Hapus foto lama terlebih dahulu.';
                }
            }
            if ($errors !== []) {
                return $this->respond(422, 'Validasi gagal.', null, $errors);
            }

            $removedFiles = $removeIds === [] ? []
                : array_column((new M_ekstrakurikuler_foto())->whereIn('id_foto', $removeIds)->findAll(), 'file_name');

            $db = db_connect();
            $db->transException(true)->transStart();
            if ($id === null) {
                $id = (int) $model->insert($data);
            } else {
                $model->update($id, $data);
            }
            if ($removeIds !== []) {
                (new M_ekstrakurikuler_foto())->whereIn('id_foto', $removeIds)->delete();
            }
            foreach ($files as $file) {
                $name = PhotoUpload::store($file, $this->photoDir());
                $moved[] = $name;
                (new M_ekstrakurikuler_foto())->insert(['id_ekstrakurikuler' => $id, 'file_name' => $name]);
            }
            $db->transComplete();
            // File lama baru dihapus setelah database berhasil diperbarui.
            $this->removeFiles($removedFiles);

            return $this->respond(
                $created ? 201 : 200,
                $created ? 'Data ekstrakurikuler berhasil ditambahkan.' : 'Data ekstrakurikuler berhasil diperbarui.',
                $this->findFormatted($id),
            );
        } catch (Throwable $e) {
            // Database sudah di-rollback; buang file yang sempat dipindahkan.
            $this->removeFiles($moved);

            return $this->serverError($e);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validated(array $input): array
    {
        $data = [];
        $errors = [];

        $nama = $this->textInput($input, 'nama_ekstrakurikuler');
        if ($nama === null || $nama === '') {
            $errors['nama_ekstrakurikuler'] = 'Nama ekstrakurikuler wajib diisi.';
        } elseif (mb_strlen($nama) > 100) {
            $errors['nama_ekstrakurikuler'] = 'Nama ekstrakurikuler maksimal 100 karakter.';
        } elseif ($this->hasControlChars($nama)) {
            $errors['nama_ekstrakurikuler'] = 'Nama ekstrakurikuler tidak boleh berisi karakter kontrol.';
        } else {
            $data['nama_ekstrakurikuler'] = $nama;
        }

        $pembina = $this->textInput($input, 'id_pembina');
        if ($pembina === null || $pembina === '') {
            $data['id_pembina'] = null;
        } else {
            // Hanya ID guru (UUID atau ID numerik lama) dari /api/data_guru yang diterima.
            $guruId = M_users::normalizeId($pembina);
            $guru = $guruId === null ? null : (new M_guru())->find((string) $guruId);
            if ($guru === null) {
                $errors['id_pembina'] = 'Pilih pembina dari daftar guru yang terdaftar.';
            } else {
                $data['id_pembina'] = (string) $guru['id_user'];
            }
        }

        $hari = strtolower($this->textInput($input, 'hari') ?? '');
        if (in_array($hari, self::DAYS, true)) {
            $data['hari'] = $hari;
        } else {
            $errors['hari'] = 'Pilih hari yang valid.';
        }

        foreach (['jam_mulai' => 'Jam mulai', 'jam_selesai' => 'Jam selesai'] as $field => $label) {
            $time = $this->textInput($input, $field) ?? '';
            if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/', $time)) {
                $data[$field] = substr($time, 0, 5);
            } else {
                $errors[$field] = "{$label} harus berformat HH:mm.";
            }
        }
        if (isset($data['jam_mulai'], $data['jam_selesai']) && $data['jam_selesai'] <= $data['jam_mulai']) {
            $errors['jam_selesai'] = 'Jam selesai harus setelah jam mulai pada hari yang sama.';
        }

        foreach (['lokasi' => [100, 'Lokasi'], 'deskripsi' => [1000, 'Deskripsi']] as $field => [$max, $label]) {
            $value = $this->textInput($input, $field);
            if ($value === false) {
                $errors[$field] = "{$label} harus berupa teks.";
            } elseif ($value === null || $value === '') {
                $data[$field] = null;
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = "{$label} maksimal {$max} karakter.";
            } else {
                $data[$field] = $value;
            }
        }

        $status = strtoupper($this->textInput($input, 'status') ?? 'ACTIVE');
        if (in_array($status, self::STATUSES, true)) {
            $data['status'] = $status;
        } else {
            $errors['status'] = 'Pilih status aktif atau nonaktif.';
        }

        return [$data, $errors];
    }

    /** @return array{0: list<UploadedFile>, 1: array<string, string>} */
    private function uploadedPhotos(): array
    {
        $files = $this->request->getFileMultiple('foto') ?? [];
        $files = array_values(array_filter($files, static fn ($file) => $file instanceof UploadedFile
            && $file->getError() !== UPLOAD_ERR_NO_FILE));

        if (count($files) > self::MAX_PHOTOS) {
            return [[], ['foto' => 'Pilih maksimal ' . self::MAX_PHOTOS . ' foto per kegiatan.']];
        }
        foreach ($files as $file) {
            $error = PhotoUpload::validate($file);
            if ($error !== null) {
                return [[], ['foto' => $error]];
            }
        }

        return [$files, []];
    }

    /** @return array{0: list<int>, 1: array<string, string>} */
    private function removedPhotos(array $input, ?int $id): array
    {
        $raw = $input['hapus_foto'] ?? [];
        if (! is_array($raw)) {
            return [[], ['hapus_foto' => 'Daftar foto yang dihapus tidak valid.']];
        }
        $ids = [];
        foreach ($raw as $value) {
            $value = is_int($value) ? (string) $value : $value;
            if (! is_string($value) || ! $this->validId(trim($value))) {
                return [[], ['hapus_foto' => 'Daftar foto yang dihapus tidak valid.']];
            }
            $ids[(int) trim($value)] = true;
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [[], []];
        }
        // Foto yang dihapus harus milik ekstrakurikuler ini.
        $owned = $id === null ? 0 : (new M_ekstrakurikuler_foto())
            ->where('id_ekstrakurikuler', $id)->whereIn('id_foto', $ids)->countAllResults();
        if ($owned !== count($ids)) {
            return [[], ['hapus_foto' => 'Foto yang dihapus tidak ditemukan pada kegiatan ini.']];
        }

        return [$ids, []];
    }

    private function withTeacher(): M_ekstrakurikuler
    {
        return (new M_ekstrakurikuler())->select('ekstrakurikuler.*, guru.nama AS nama_pembina')
            ->join('guru', 'guru.id_user = ekstrakurikuler.id_pembina', 'left');
    }

    private function findFormatted(int $id): ?array
    {
        $row = $this->withTeacher()->where('ekstrakurikuler.id_ekstrakurikuler', $id)->first();

        return $row === null ? null : $this->format($row, $this->photosFor([$id])[$id] ?? []);
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function photosFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $grouped = [];
        $rows = (new M_ekstrakurikuler_foto())->whereIn('id_ekstrakurikuler', array_map('intval', $ids))
            ->orderBy('id_foto', 'ASC')->findAll();
        foreach ($rows as $row) {
            $grouped[(int) $row['id_ekstrakurikuler']][] = $row;
        }

        return $grouped;
    }

    private function format(array $row, array $photos): array
    {
        $nama = $row['nama_ekstrakurikuler'];

        return [
            'id_ekstrakurikuler' => (int) $row['id_ekstrakurikuler'],
            'nama_ekstrakurikuler' => $nama,
            'id_pembina' => $row['id_pembina'] ?? null,
            'nama_pembina' => $row['nama_pembina'] ?? null,
            'hari' => $row['hari'],
            'jam_mulai' => substr((string) $row['jam_mulai'], 0, 5),
            'jam_selesai' => substr((string) $row['jam_selesai'], 0, 5),
            'lokasi' => $row['lokasi'] ?? null,
            'deskripsi' => $row['deskripsi'] ?? null,
            'status' => $row['status'],
            // Belum ada tabel anggota ekstrakurikuler.
            'jumlah_anggota' => null,
            'foto' => array_map(fn (array $photo) => $this->formatPhoto($photo, $nama), $photos),
        ];
    }

    private function formatPhoto(array $row, string $caption): array
    {
        return [
            'id_foto' => (string) $row['id_foto'],
            // Path relatif; frontend menggabungkannya dengan VITE_API_BASE_URL.
            'url' => '/' . self::PHOTO_URL_PATH . rawurlencode($row['file_name']),
            'caption' => $caption,
        ];
    }

    private function meta(int $page, int $total, int $offset, int $count, int $perPage): array
    {
        return [
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
            'from' => $count === 0 ? null : $offset + 1,
            'to' => $count === 0 ? null : $offset + $count,
        ];
    }

    private function nameTaken(string $nama, ?int $exceptId = null): bool
    {
        $query = (new M_ekstrakurikuler())->where('LOWER(nama_ekstrakurikuler)', mb_strtolower($nama));
        if ($exceptId !== null) {
            $query->where('id_ekstrakurikuler !=', $exceptId);
        }

        return $query->countAllResults() > 0;
    }

    protected function photoDir(): string
    {
        return FCPATH . self::PHOTO_URL_PATH;
    }

    private function removeFiles(array $names): void
    {
        PhotoUpload::remove($this->photoDir(), $names);
    }

    /** null = tidak dikirim, false = bukan teks, string = nilai yang sudah di-trim. */
    private function textInput(array $input, string $field): string|false|null
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
            if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw new InvalidArgumentException('Body bukan objek JSON.');
            }

            return $decoded;
        }
        // Multipart selalu datang lewat POST (PUT dipalsukan via _method), jadi isinya ada di $_POST.
        $post = $this->request->getPost();
        if (is_array($post) && $post !== []) {
            unset($post['_method']);

            return $post;
        }
        $raw = $this->request->getRawInput();

        return is_array($raw) ? $raw : [];
    }

    private function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1
            && (int) $value <= 2147483647;
    }

    private function invalidId(): ResponseInterface
    {
        return $this->respond(422, 'ID ekstrakurikuler tidak valid.', null, [
            'id_ekstrakurikuler' => 'ID ekstrakurikuler harus berupa bilangan bulat positif.',
        ]);
    }

    private function notFound(): ResponseInterface
    {
        return $this->respond(404, 'Ekstrakurikuler tidak ditemukan.');
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
        log_message('error', 'Ekstrakurikuler: {exception}', ['exception' => $e]);

        return $this->respond(500, 'Terjadi kesalahan saat memproses data ekstrakurikuler.');
    }
}
