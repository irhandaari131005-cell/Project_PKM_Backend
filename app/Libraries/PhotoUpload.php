<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Validasi + penyimpanan foto unggahan (JPG/WebP, maks 2 MB) di bawah public/uploads.
 * Nama file selalu dibuat server (32 hex + ekstensi), jadi aman dipakai di URL dan unlink.
 */
final class PhotoUpload
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const TYPES = ['image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** @return string|null Pesan error untuk pengguna, atau null bila valid. */
    public static function validate(UploadedFile $file): ?string
    {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->isValid() && $file->getSize() > self::MAX_BYTES)) {
            return 'Ukuran foto maksimal 2 MB per file.';
        }
        if (! $file->isValid()) {
            return 'Foto gagal diunggah. Silakan coba lagi.';
        }
        // Cek isi file sungguhan (bukan sekadar ekstensi/header dari browser).
        $mime = $file->getMimeType();
        $image = @getimagesize($file->getTempName());
        if (! isset(self::TYPES[$mime]) || $image === false || ($image['mime'] ?? null) !== $mime) {
            return 'Format foto harus WebP, JPG, atau JPEG.';
        }

        return null;
    }

    /** Pindahkan file yang sudah divalidasi ke $dir; mengembalikan nama file baru. */
    public static function store(UploadedFile $file, string $dir): string
    {
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$file->getMimeType()];
        $file->move($dir, $name);

        return $name;
    }

    /** @param list<mixed> $names */
    public static function remove(string $dir, array $names): void
    {
        foreach ($names as $name) {
            if (self::isStoredName($name)) {
                @unlink(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    public static function isStoredName(mixed $name): bool
    {
        return is_string($name) && preg_match('/\A[0-9a-f]{32}\.(jpg|webp)\z/', $name) === 1;
    }

    /** Ambil file dari input; null bila tidak ada file yang dikirim. */
    public static function single(?UploadedFile $file): ?UploadedFile
    {
        return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }
}
