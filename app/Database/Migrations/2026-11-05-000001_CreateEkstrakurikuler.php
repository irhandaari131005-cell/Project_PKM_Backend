<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tabel ekstrakurikuler dan fotonya.
 * - ekstrakurikuler.id_pembina -> guru.id_user (guru dihapus: pembina jadi NULL).
 * - ekstrakurikuler_foto.id_ekstrakurikuler -> ekstrakurikuler (ekskul dihapus: fotonya ikut terhapus).
 * File foto disimpan di public/uploads/ekstrakurikuler, tabel hanya menyimpan nama file.
 */
class CreateEkstrakurikuler extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('id_user', 'guru')) {
            throw new RuntimeException('Tabel guru.id_user harus tersedia sebelum membuat tabel ekstrakurikuler.');
        }

        $ekskul = $this->db->escapeIdentifiers($this->db->prefixTable('ekstrakurikuler'));
        $foto = $this->db->escapeIdentifiers($this->db->prefixTable('ekstrakurikuler_foto'));
        $guru = $this->db->escapeIdentifiers($this->db->prefixTable('guru'));

        if ($this->db->DBDriver === 'MySQLi') {
            // FK MySQL mensyaratkan charset/collation yang sama dengan guru.id_user.
            $column = $this->db->query("SHOW FULL COLUMNS FROM {$guru} WHERE Field = 'id_user'")->getRowArray();
            $collation = $column['Collation'] ?? '';
            if (! preg_match('/\A[a-zA-Z0-9]+_[a-zA-Z0-9_]+\z/', $collation)) {
                throw new RuntimeException('Kolom guru.id_user harus memakai ID teks dengan collation yang valid.');
            }
            $charset = strstr($collation, '_', true);
            $pembinaFk = $this->db->escapeIdentifiers($this->db->prefixTable('ekstrakurikuler') . '_id_pembina_foreign');
            $fotoFk = $this->db->escapeIdentifiers($this->db->prefixTable('ekstrakurikuler_foto') . '_id_ekstrakurikuler_foreign');

            $this->run("CREATE TABLE IF NOT EXISTS {$ekskul} (
                id_ekstrakurikuler INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                nama_ekstrakurikuler VARCHAR(100) NOT NULL,
                id_pembina VARCHAR(36) CHARACTER SET {$charset} COLLATE {$collation} NULL DEFAULT NULL,
                hari VARCHAR(10) NOT NULL,
                jam_mulai TIME NOT NULL,
                jam_selesai TIME NOT NULL,
                lokasi VARCHAR(100) NULL DEFAULT NULL,
                deskripsi TEXT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
                created_at DATETIME NULL DEFAULT NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                KEY idx_ekstrakurikuler_hari (hari),
                CONSTRAINT {$pembinaFk} FOREIGN KEY (id_pembina) REFERENCES {$guru} (id_user)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB");
            $this->run("CREATE TABLE IF NOT EXISTS {$foto} (
                id_foto INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                id_ekstrakurikuler INT UNSIGNED NOT NULL,
                file_name VARCHAR(100) NOT NULL,
                created_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uniq_ekstrakurikuler_foto_file (file_name),
                CONSTRAINT {$fotoFk} FOREIGN KEY (id_ekstrakurikuler) REFERENCES {$ekskul} (id_ekstrakurikuler)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB");
        } elseif ($this->db->DBDriver === 'SQLite3') {
            $this->run("CREATE TABLE IF NOT EXISTS {$ekskul} (
                id_ekstrakurikuler INTEGER PRIMARY KEY AUTOINCREMENT,
                nama_ekstrakurikuler VARCHAR(100) NOT NULL,
                id_pembina VARCHAR(36) NULL REFERENCES {$guru} (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
                hari VARCHAR(10) NOT NULL,
                jam_mulai TIME NOT NULL,
                jam_selesai TIME NOT NULL,
                lokasi VARCHAR(100) NULL,
                deskripsi TEXT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'ACTIVE',
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )");
            $this->run("CREATE TABLE IF NOT EXISTS {$foto} (
                id_foto INTEGER PRIMARY KEY AUTOINCREMENT,
                id_ekstrakurikuler INTEGER NOT NULL REFERENCES {$ekskul} (id_ekstrakurikuler)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                file_name VARCHAR(100) NOT NULL UNIQUE,
                created_at DATETIME NULL
            )");
        } else {
            throw new RuntimeException('Migrasi ekstrakurikuler mendukung MySQLi dan SQLite3.');
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        $this->forge->dropTable('ekstrakurikuler_foto', true);
        $this->forge->dropTable('ekstrakurikuler', true);
        $this->db->resetDataCache();
    }

    private function run(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Tabel ekstrakurikuler gagal dibuat.');
        }
    }
}
