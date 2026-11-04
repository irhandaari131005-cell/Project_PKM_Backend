<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddStudentClass extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('siswa') || ! $this->db->fieldExists('id_kelas', 'kelas')) {
            throw new RuntimeException('Tabel siswa dan kelas.id_kelas harus tersedia sebelum menambahkan kelas siswa.');
        }
        if ($this->db->fieldExists('id_kelas', 'siswa')) {
            return;
        }

        $siswa = $this->db->escapeIdentifiers($this->db->prefixTable('siswa'));
        $kelas = $this->db->escapeIdentifiers($this->db->prefixTable('kelas'));
        if ($this->db->DBDriver === 'MySQLi') {
            $constraint = $this->db->escapeIdentifiers($this->db->prefixTable('siswa') . '_id_kelas_foreign');
            $sql = "ALTER TABLE {$siswa} ADD COLUMN id_kelas INT NULL DEFAULT NULL,
                ADD CONSTRAINT {$constraint} FOREIGN KEY (id_kelas) REFERENCES {$kelas} (id_kelas)
                ON UPDATE CASCADE ON DELETE SET NULL";
        } elseif ($this->db->DBDriver === 'SQLite3') {
            $sql = "ALTER TABLE {$siswa} ADD COLUMN id_kelas INTEGER DEFAULT NULL
                REFERENCES {$kelas} (id_kelas) ON UPDATE CASCADE ON DELETE SET NULL";
        } else {
            throw new RuntimeException('Migrasi kelas siswa mendukung MySQLi dan SQLite3.');
        }
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Kolom kelas siswa gagal ditambahkan.');
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        if (! $this->db->fieldExists('id_kelas', 'siswa')) {
            return;
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('siswa', $this->db->prefixTable('siswa') . '_id_kelas_foreign');
        }
        $this->forge->dropColumn('siswa', 'id_kelas');
        $this->db->resetDataCache();
    }
}
