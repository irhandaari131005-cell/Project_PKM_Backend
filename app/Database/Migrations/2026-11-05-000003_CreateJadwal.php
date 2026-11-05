<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tabel jadwal pelajaran.
 * - jadwal.id_kelas -> kelas.id_kelas (kelas dihapus: jadwalnya ikut terhapus).
 * - jadwal.id_guru  -> guru.id_user   (guru dihapus: jadwal tetap ada, guru jadi NULL).
 */
class CreateJadwal extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('id_kelas', 'kelas') || ! $this->db->fieldExists('id_user', 'guru')) {
            throw new RuntimeException('Tabel kelas.id_kelas dan guru.id_user harus tersedia sebelum membuat tabel jadwal.');
        }

        $jadwal = $this->db->escapeIdentifiers($this->db->prefixTable('jadwal'));
        $kelas = $this->db->escapeIdentifiers($this->db->prefixTable('kelas'));
        $guru = $this->db->escapeIdentifiers($this->db->prefixTable('guru'));

        if ($this->db->DBDriver === 'MySQLi') {
            // FK MySQL mensyaratkan tipe kolom yang sama persis dengan kolom tujuan; baca dari database.
            $kelasType = $this->db->query("SHOW FULL COLUMNS FROM {$kelas} WHERE Field = 'id_kelas'")->getRowArray()['Type'] ?? '';
            if (! preg_match('/\A(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?\z/i', $kelasType)) {
                throw new RuntimeException('Kolom kelas.id_kelas harus bertipe integer.');
            }
            $guruColumn = $this->db->query("SHOW FULL COLUMNS FROM {$guru} WHERE Field = 'id_user'")->getRowArray();
            $collation = $guruColumn['Collation'] ?? '';
            if (! preg_match('/\A[a-zA-Z0-9]+_[a-zA-Z0-9_]+\z/', $collation)) {
                throw new RuntimeException('Kolom guru.id_user harus memakai ID teks dengan collation yang valid.');
            }
            $charset = strstr($collation, '_', true);
            $kelasFk = $this->db->escapeIdentifiers($this->db->prefixTable('jadwal') . '_id_kelas_foreign');
            $guruFk = $this->db->escapeIdentifiers($this->db->prefixTable('jadwal') . '_id_guru_foreign');

            $this->run("CREATE TABLE IF NOT EXISTS {$jadwal} (
                id_jadwal INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                mata_pelajaran VARCHAR(100) NOT NULL,
                id_kelas {$kelasType} NOT NULL,
                id_guru VARCHAR(36) CHARACTER SET {$charset} COLLATE {$collation} NULL DEFAULT NULL,
                hari VARCHAR(10) NOT NULL,
                jam_mulai TIME NOT NULL,
                jam_selesai TIME NOT NULL,
                ruangan VARCHAR(50) NULL DEFAULT NULL,
                created_at DATETIME NULL DEFAULT NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                KEY idx_jadwal_kelas_hari (id_kelas, hari),
                KEY idx_jadwal_guru_hari (id_guru, hari),
                CONSTRAINT {$kelasFk} FOREIGN KEY (id_kelas) REFERENCES {$kelas} (id_kelas)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT {$guruFk} FOREIGN KEY (id_guru) REFERENCES {$guru} (id_user)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB");
        } elseif ($this->db->DBDriver === 'SQLite3') {
            $this->run("CREATE TABLE IF NOT EXISTS {$jadwal} (
                id_jadwal INTEGER PRIMARY KEY AUTOINCREMENT,
                mata_pelajaran VARCHAR(100) NOT NULL,
                id_kelas INTEGER NOT NULL REFERENCES {$kelas} (id_kelas) ON UPDATE CASCADE ON DELETE CASCADE,
                id_guru VARCHAR(36) NULL REFERENCES {$guru} (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
                hari VARCHAR(10) NOT NULL,
                jam_mulai TIME NOT NULL,
                jam_selesai TIME NOT NULL,
                ruangan VARCHAR(50) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )");
        } else {
            throw new RuntimeException('Migrasi jadwal mendukung MySQLi dan SQLite3.');
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        $this->forge->dropTable('jadwal', true);
        $this->db->resetDataCache();
    }

    private function run(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Tabel jadwal gagal dibuat.');
        }
    }
}
