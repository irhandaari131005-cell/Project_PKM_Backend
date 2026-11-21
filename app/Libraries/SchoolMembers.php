<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Aturan bersama untuk data siswa/guru dan akun login.
 *
 * Alur "data dulu, akun nyusul": guru boleh menambah data siswa/guru tanpa akun.
 * Saat orang tersebut mendaftar dengan SN (nomor kartu) yang sama dan tipe yang sama,
 * akunnya disambungkan ke data yang sudah ada, bukan membuat data dobel.
 *
 * Data dianggap "belum punya akun" bila:
 * - siswa: id_user kosong/NULL atau tidak ada di tabel users;
 * - guru:  id_user (primary key) tidak ada di tabel users.
 */
final class SchoolMembers
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * SN sudah dipakai akun mana pun atau data siswa/guru mana pun.
     *
     * $ignore mengecualikan baris milik orang yang sedang diedit, mis.
     * ['siswa' => 12, 'users' => '<uuid akun>'] atau ['guru' => '<uuid>', 'users' => '<uuid>'].
     *
     * @param array{users?: int|string|null, siswa?: int|null, guru?: string|null} $ignore
     */
    public function cardInUse(string $sn, array $ignore = []): bool
    {
        $keys = ['users' => 'id_user', 'siswa' => 'id_siswa', 'guru' => 'id_user'];
        foreach ($keys as $table => $key) {
            $query = $this->db->table($table)->where('sn', $sn);
            if (($ignore[$table] ?? null) !== null) {
                $query->where("{$key} !=", $ignore[$table]);
            }
            if ($query->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    /** Akun login (users.id_user) milik data siswa/guru ini, atau null bila belum punya akun. */
    public function accountId(mixed $userId): ?string
    {
        if (! is_string($userId) && ! is_int($userId)) {
            return null;
        }
        $userId = (string) $userId;
        if ($userId === '') {
            return null;
        }

        return $this->db->table('users')->where('id_user', $userId)->countAllResults() > 0 ? $userId : null;
    }

    /**
     * Data siswa/guru tanpa akun dengan SN ini dan tipe yang sama.
     *
     * @return array{table: 'siswa'|'guru', key: int|string, row: array}|null
     */
    public function claimable(string $sn, string $type): ?array
    {
        if (! in_array($type, ['siswa', 'guru'], true)
            || $this->db->table('users')->where('sn', $sn)->countAllResults() > 0) {
            return null;
        }
        $users = $this->db->prefixTable('users');
        $table = $this->db->prefixTable($type);
        // Nama tabel di sini sudah berprefix, jadi semua potongan SQL ditulis mentah (escape manual).
        $rows = $this->db->query(
            "SELECT {$table}.* FROM {$table} LEFT JOIN {$users} ON {$users}.id_user = {$table}.id_user"
            . " WHERE {$table}.sn = ? AND {$users}.id_user IS NULL",
            [$sn],
        )->getResultArray();

        // Lebih dari satu data dengan SN yang sama: jangan menebak, minta admin merapikan.
        if (count($rows) !== 1) {
            return null;
        }
        $row = $rows[0];

        return [
            'table' => $type,
            'key' => $type === 'siswa' ? (int) $row['id_siswa'] : (string) $row['id_user'],
            'row' => $row,
        ];
    }

    /** Sambungkan data siswa/guru yang belum punya akun ke akun baru $userId. */
    public function link(array $claim, string $userId, string $now): void
    {
        if ($claim['table'] === 'siswa') {
            $users = $this->db->prefixTable('users');
            $siswa = $this->db->prefixTable('siswa');
            // Syarat "belum punya akun" diulang di WHERE: dua pendaftaran bersamaan tidak bisa
            // sama-sama mengklaim data yang sama.
            $this->db->table('siswa')
                ->where('id_siswa', $claim['key'])
                ->where("({$siswa}.id_user IS NULL OR {$siswa}.id_user = '' OR {$siswa}.id_user NOT IN (SELECT id_user FROM {$users}))", null, false)
                ->update(['id_user' => $userId, 'update_date' => $now]);
        } else {
            // Primary key guru ikut berganti; FK wali kelas/jadwal/pembina memakai ON UPDATE CASCADE.
            $this->db->table('guru')
                ->where('id_user', $claim['key'])
                ->update(['id_user' => $userId, 'update_date' => $now]);
        }
        if ($this->db->affectedRows() !== 1) {
            throw new \RuntimeException('Data siswa/guru sudah disambungkan ke akun lain.');
        }
    }
}
