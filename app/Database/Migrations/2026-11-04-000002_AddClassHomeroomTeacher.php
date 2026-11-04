<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Menambahkan wali kelas: kelas.id_wali_kelas -> guru.id_user.
 * Satu guru hanya boleh menjadi wali satu kelas (NULL boleh berulang).
 * Jika guru dihapus, kelasnya tetap ada dan wali kelas menjadi NULL.
 */
class AddClassHomeroomTeacher extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('kelas') || ! $this->db->fieldExists('id_user', 'guru')) {
            throw new RuntimeException('Tabel kelas dan guru.id_user harus tersedia sebelum menambahkan wali kelas.');
        }
        if ($this->db->fieldExists('id_wali_kelas', 'kelas')) {
            return;
        }

        $kelas = $this->db->escapeIdentifiers($this->db->prefixTable('kelas'));
        $guru = $this->db->escapeIdentifiers($this->db->prefixTable('guru'));
        if ($this->db->DBDriver === 'MySQLi') {
            // FK MySQL mensyaratkan charset/collation yang sama dengan guru.id_user.
            $column = $this->db->query("SHOW FULL COLUMNS FROM {$guru} WHERE Field = 'id_user'")->getRowArray();
            $collation = $column['Collation'] ?? '';
            if (! preg_match('/\A[a-zA-Z0-9]+_[a-zA-Z0-9_]+\z/', $collation)) {
                throw new RuntimeException('Kolom guru.id_user harus memakai ID teks dengan collation yang valid.');
            }
            $charset = strstr($collation, '_', true);
            $unique = $this->db->escapeIdentifiers('uniq_kelas_wali_kelas');
            $constraint = $this->db->escapeIdentifiers($this->db->prefixTable('kelas') . '_id_wali_kelas_foreign');
            $sql = "ALTER TABLE {$kelas}
                ADD COLUMN id_wali_kelas VARCHAR(36) CHARACTER SET {$charset} COLLATE {$collation} NULL DEFAULT NULL,
                ADD UNIQUE KEY {$unique} (id_wali_kelas),
                ADD CONSTRAINT {$constraint} FOREIGN KEY (id_wali_kelas) REFERENCES {$guru} (id_user)
                ON UPDATE CASCADE ON DELETE SET NULL";
            if ($this->db->query($sql) === false) {
                throw new RuntimeException('Kolom wali kelas gagal ditambahkan.');
            }
        } elseif ($this->db->DBDriver === 'SQLite3') {
            $this->db->query("ALTER TABLE {$kelas} ADD COLUMN id_wali_kelas VARCHAR(36) DEFAULT NULL
                REFERENCES {$guru} (id_user) ON UPDATE CASCADE ON DELETE SET NULL");
            $index = $this->db->escapeIdentifiers($this->db->prefixTable('uniq_kelas_wali_kelas'));
            $this->db->query("CREATE UNIQUE INDEX {$index} ON {$kelas} (id_wali_kelas)");
        } else {
            throw new RuntimeException('Migrasi wali kelas mendukung MySQLi dan SQLite3.');
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        if (! $this->db->fieldExists('id_wali_kelas', 'kelas')) {
            return;
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('kelas', $this->db->prefixTable('kelas') . '_id_wali_kelas_foreign');
            $this->forge->dropKey('kelas', 'uniq_kelas_wali_kelas', false);
        } else {
            $this->db->query('DROP INDEX IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable('uniq_kelas_wali_kelas')));
        }
        $this->forge->dropColumn('kelas', 'id_wali_kelas');
        $this->db->resetDataCache();
    }
}
