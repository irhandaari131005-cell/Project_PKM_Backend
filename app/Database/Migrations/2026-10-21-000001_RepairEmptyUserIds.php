<?php

namespace App\Database\Migrations;

use App\Models\M_users;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

class RepairEmptyUserIds extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        // Perbaikan hanya untuk skema ID teks; jangan mengubah ID numerik lama.
        $idField = null;
        foreach ($this->db->getFieldData('users') as $field) {
            if ($field->name === 'id_user') {
                $idField = $field;
                break;
            }
        }
        $idType = $idField === null ? '' : strtolower(explode('(', $idField->type, 2)[0]);
        if (! in_array($idType, ['char', 'varchar', 'text'], true)) {
            return;
        }
        if ($this->db->table('users')->where('id_user', '')->countAllResults() === 0) {
            return;
        }
        preg_match('/\((\d+)\)/', $idField->type, $typeLength);
        $maximumLength = $idField->max_length ?? (int) ($typeLength[1] ?? 0);
        if ($maximumLength > 0 && $maximumLength < 36) {
            throw new RuntimeException('Kolom users.id_user harus dapat menyimpan UUID sepanjang 36 karakter.');
        }

        if (! $this->db->transBegin()) {
            throw new RuntimeException('Transaksi perbaikan ID akun gagal dimulai.');
        }

        try {
            // Jangan membuat relasi yatim. Akun kosong yang sudah direferensikan perlu
            // perbaikan relasi khusus, bukan perubahan primary key secara diam-diam.
            $references = [];
            foreach (['siswa', 'guru'] as $table) {
                if ($this->db->tableExists($table) && $this->db->fieldExists('id_user', $table)) {
                    $references[] = [$this->db->prefixTable($table), 'id_user'];
                }
            }
            if ($this->db->DBDriver === 'MySQLi') {
                $keys = $this->db->query('SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
                    AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?', [
                        $this->db->prefixTable('users'), 'id_user',
                    ])->getResultArray();
                foreach ($keys as $key) {
                    $references[] = [$key['TABLE_SCHEMA'] . '.' . $key['TABLE_NAME'], $key['COLUMN_NAME']];
                }
            }
            foreach ($references as [$table, $column]) {
                $tableSql = $this->db->escapeIdentifiers($table);
                $columnSql = $this->db->escapeIdentifiers($column);
                $row = $this->db->query("SELECT COUNT(*) AS total FROM {$tableSql} WHERE {$columnSql} = ?", [''])
                    ->getRowArray();
                if ((int) $row['total'] > 0) {
                    throw new RuntimeException('ID akun kosong masih dipakai pada relasi profil; perbaiki relasinya sebelum migrasi.');
                }
            }

            $updated = $this->db->table('users')->where('id_user', '')
                ->update(['id_user' => M_users::generateUuid()]);
            if (! $updated || $this->db->affectedRows() !== 1 || ! $this->db->transStatus()) {
                throw new RuntimeException('ID akun kosong gagal diperbaiki.');
            }
            if (! $this->db->transCommit()) {
                throw new RuntimeException('Perbaikan ID akun gagal disimpan.');
            }
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function down()
    {
        // UUID yang telah diberikan tetap dipertahankan agar identitas akun stabil.
    }
}
