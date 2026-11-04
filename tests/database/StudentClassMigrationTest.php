<?php

use App\Database\Migrations\AddStudentClass;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-21-000002_AddStudentClass.php';

final class StudentClassMigrationTest extends CIUnitTestCase
{
    private BaseConnection $isolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'class_', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->isolatedDatabase->query('CREATE TABLE class_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT)');
        $this->isolatedDatabase->query('CREATE TABLE class_siswa (id_siswa INTEGER PRIMARY KEY, nama_siswa TEXT)');
        $this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1']);
        $this->isolatedDatabase->table('siswa')->insert(['id_siswa' => 1, 'nama_siswa' => 'Siswa Lama']);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        parent::tearDown();
    }

    public function testMigrationKeepsExistingStudentsAndAllowsClassSelection(): void
    {
        $migration = new AddStudentClass(Database::forge($this->isolatedDatabase));
        $migration->up();
        $migration->up();
        $this->assertSame([
            'id_siswa' => 1, 'nama_siswa' => 'Siswa Lama', 'id_kelas' => null,
        ], $this->isolatedDatabase->table('siswa')->get()->getRowArray());
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->update(['id_kelas' => 1]);
        $this->assertSame(1, $this->isolatedDatabase->table('siswa')->get()->getRowArray()['id_kelas']);
        $this->isolatedDatabase->table('kelas')->where('id_kelas', 1)->delete();
        $this->assertNull($this->isolatedDatabase->table('siswa')->get()->getRowArray()['id_kelas']);
    }

    public function testUnknownClassIsRejectedByTheDatabase(): void
    {
        (new AddStudentClass(Database::forge($this->isolatedDatabase)))->up();
        $this->expectException(DatabaseException::class);
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->update(['id_kelas' => 999]);
    }
}
