<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;
use RuntimeException;

class CreateAbsensi extends Migration
{
    public function up()
    {
        // Instalasi yang sudah punya tabel absensi tidak perlu dikonversi ulang.
        if ($this->db->tableExists('absensi')) {
            return;
        }

        if (! $this->db->tableExists('users') || ! $this->db->fieldExists('id_user', 'users')) {
            throw new RuntimeException('Kolom users.id_user harus tersedia sebelum membuat tabel absensi.');
        }

        $attributes = [];
        if ($this->db->DBDriver === 'MySQLi') {
            $users = $this->db->escapeIdentifiers($this->db->prefixTable('users'));
            $userId = $this->db->query("SHOW FULL COLUMNS FROM {$users} WHERE Field = 'id_user'")->getRowArray();
            $collation = $userId['Collation'] ?? '';
            if (! preg_match('/\A[a-zA-Z0-9]+_[a-zA-Z0-9_]+\z/', $collation)) {
                throw new RuntimeException('Kolom users.id_user harus memakai ID teks dengan charset dan collation yang valid.');
            }
            // FK MySQL mensyaratkan charset/collation kolom ID cocok dengan users,
            // meskipun default koneksi aplikasi berbeda dari tabel yang sudah ada.
            $attributes = [
                'ENGINE' => 'InnoDB',
                'CHARSET' => strstr($collation, '_', true),
                'COLLATE' => $collation,
            ];
        }

        $this->forge->addField([
            'id_absensi' => [
                'type'       => 'CHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'id_user' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'tanggal' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'check_in' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'check_out' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_date' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'update_date' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new RawSql($this->db->DBDriver === 'MySQLi'
                    ? 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
                    : 'CURRENT_TIMESTAMP'),
            ],
            'sn' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
        ]);

        $this->forge->addKey('id_absensi', true);
        $this->forge->addUniqueKey(['id_user', 'tanggal'], 'unique_user_tanggal');
        $this->forge->addKey('tanggal', false, false, 'idx_tanggal');
        $this->forge->addKey('sn', false, false, 'idx_absensi_sn');
        $this->forge->addForeignKey('id_user', 'users', 'id_user', 'CASCADE', 'RESTRICT',
            $this->db->DBDriver === 'SQLite3' ? '' : $this->db->DBPrefix . 'fk_absensi_user');

        $this->forge->createTable('absensi', false, $attributes);
    }

    public function down()
    {
        // Tabel yang sudah ada dapat diadopsi oleh up(); jangan hapus data absensi
        // ketika rollback karena tabel itu mungkin bukan dibuat oleh migrasi ini.
    }
}
