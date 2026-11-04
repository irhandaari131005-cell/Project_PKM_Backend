<?php

namespace App\Models;

use CodeIgniter\Model;

// Akun memakai UUID sesuai kolom users.id_user VARCHAR(36).
class M_users extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id_user';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $beforeInsert     = ['assignUserId'];
    protected $allowedFields    = [
        'username',
        'nama',
        'password',
        'sn',
        'type',
        'created_date',
        'update_date',
    ];

    protected function assignUserId(array $event): array
    {
        // ID selalu dibuat server, termasuk jika pemanggil menyertakan primary key.
        $event['data']['id_user'] = self::generateUuid();

        return $event;
    }

    public static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /** Sidik jari hash password untuk membatalkan sesi lama setelah password berubah. */
    public static function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    public static function normalizeId(mixed $id): int|string|null
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (! is_string($id)) {
            return null;
        }

        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) === 1) {
            return $id;
        }

        // Kompatibilitas ID numerik lama tanpa pemotongan atau overflow integer.
        if (preg_match('/\A[1-9][0-9]*\z/', $id) === 1) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($id) < strlen($maximum)
                || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) <= 0)) {
                return (int) $id;
            }
        }

        return null;
    }
}
