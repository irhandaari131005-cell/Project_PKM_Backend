<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Validasi is_unique saja tidak mencegah dua request register yang datang bersamaan
 * (mis. tombol daftar diklik dua kali). Unique key menjadi pengaman terakhir.
 */
class AddUniqueUserCredentials extends Migration
{
    private const KEYS = ['username' => 'uniq_users_username', 'sn' => 'uniq_users_sn'];

    public function up()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        $existing = [];
        foreach ($this->db->getIndexData('users') as $index) {
            if (strtoupper($index->type) === 'UNIQUE' || strtoupper($index->type) === 'PRIMARY') {
                $existing[] = $index->fields;
            }
        }

        $added = false;
        foreach (self::KEYS as $field => $name) {
            if (! $this->db->fieldExists($field, 'users') || in_array([$field], $existing, true)) {
                continue;
            }

            // Jangan menghapus atau mengubah akun secara diam-diam: duplikat harus dibereskan manual.
            $duplicate = $this->db->table('users')->select($field)->where($field . ' IS NOT NULL')
                ->groupBy($field)->having('COUNT(*) >', 1)->get()->getRowArray();
            if ($duplicate !== null) {
                throw new RuntimeException("users.{$field} masih memiliki data ganda; perbaiki dulu sebelum migrasi.");
            }

            $this->forge->addUniqueKey($field, $name);
            $added = true;
        }

        if ($added && ! $this->forge->processIndexes('users')) {
            throw new RuntimeException('Unique key username/SN gagal ditambahkan.');
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        // Driver berbeda bisa menyimpan nama index dengan atau tanpa prefix tabel.
        $names = [...array_values(self::KEYS), ...array_map(fn (string $name) => $this->db->DBPrefix . $name, self::KEYS)];
        foreach ($this->db->getIndexData('users') as $index) {
            if (in_array($index->name, $names, true)) {
                $this->forge->dropKey('users', $index->name, false);
            }
        }
    }
}
