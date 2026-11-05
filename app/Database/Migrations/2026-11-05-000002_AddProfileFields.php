<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Field profil yang bisa diedit sendiri lewat halaman "Profil Saya",
 * ditambahkan ke tabel siswa dan guru (semuanya opsional/NULL).
 * Kolom foto hanya menyimpan nama file; file ada di public/uploads/profil.
 */
class AddProfileFields extends Migration
{
    private const TABLES = ['siswa', 'guru'];

    private function fields(): array
    {
        return [
            'tempat_lahir' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'tanggal_lahir' => ['type' => 'DATE', 'null' => true],
            'alamat' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'no_telp' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'foto' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        ];
    }

    public function up()
    {
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                throw new RuntimeException("Tabel {$table} harus tersedia sebelum menambahkan field profil.");
            }
            $missing = array_filter(
                $this->fields(),
                fn (string $field) => ! $this->db->fieldExists($field, $table),
                ARRAY_FILTER_USE_KEY,
            );
            if ($missing !== []) {
                $this->forge->addColumn($table, $missing);
            }
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        foreach (self::TABLES as $table) {
            $existing = array_values(array_filter(
                array_keys($this->fields()),
                fn (string $field) => $this->db->fieldExists($field, $table),
            ));
            if ($existing !== []) {
                $this->forge->dropColumn($table, $existing);
            }
        }
        $this->db->resetDataCache();
    }
}
