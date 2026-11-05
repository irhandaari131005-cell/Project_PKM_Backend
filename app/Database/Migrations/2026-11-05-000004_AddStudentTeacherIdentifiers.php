<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Nomor induk untuk form Tambah Siswa/Guru:
 * - siswa.nis (unik)
 * - guru.nip (unik) dan guru.mata_pelajaran
 * Semuanya NULL-able: data lama dan akun hasil registrasi boleh belum punya nomor induk.
 */
class AddStudentTeacherIdentifiers extends Migration
{
    private const COLUMNS = [
        'siswa' => [
            'nis' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
        ],
        'guru' => [
            'nip' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'mata_pelajaran' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        ],
    ];
    private const UNIQUE = ['siswa' => 'nis', 'guru' => 'nip'];

    public function up()
    {
        foreach (self::COLUMNS as $table => $fields) {
            if (! $this->db->tableExists($table)) {
                throw new RuntimeException("Tabel {$table} harus tersedia sebelum menambahkan nomor induk.");
            }
            $missing = array_filter($fields, fn (string $field) => ! $this->db->fieldExists($field, $table), ARRAY_FILTER_USE_KEY);
            if ($missing !== []) {
                $this->forge->addColumn($table, $missing);
            }
        }
        $this->db->resetDataCache();

        foreach (self::UNIQUE as $table => $field) {
            $index = $this->db->escapeIdentifiers($this->indexName($table, $field));
            $exists = array_key_exists($this->indexName($table, $field), $this->db->getIndexData($table));
            if (! $exists && $this->db->query("CREATE UNIQUE INDEX {$index} ON "
                . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' (' . $this->db->escapeIdentifiers($field) . ')') === false) {
                throw new RuntimeException("Index unik {$table}.{$field} gagal dibuat.");
            }
        }
    }

    public function down()
    {
        foreach (self::UNIQUE as $table => $field) {
            if (array_key_exists($this->indexName($table, $field), $this->db->getIndexData($table))) {
                $this->forge->dropKey($table, $this->indexName($table, $field), false);
            }
        }
        foreach (self::COLUMNS as $table => $fields) {
            $existing = array_values(array_filter(array_keys($fields), fn (string $field) => $this->db->fieldExists($field, $table)));
            if ($existing !== []) {
                $this->forge->dropColumn($table, $existing);
            }
        }
        $this->db->resetDataCache();
    }

    private function indexName(string $table, string $field): string
    {
        // SQLite: nama index bersifat global per database, jadi sertakan prefix tabel.
        return ($this->db->DBDriver === 'SQLite3' ? $this->db->prefixTable($table) . '_' : '') . "uniq_{$table}_{$field}";
    }
}
