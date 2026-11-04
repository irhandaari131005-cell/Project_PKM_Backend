<?php

use App\Database\Migrations\RepairEmptyUserIds;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-21-000001_RepairEmptyUserIds.php';

final class RepairEmptyUserIdsTest extends CIUnitTestCase
{
    private BaseConnection $isolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'repair_', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->isolatedDatabase->query('CREATE TABLE repair_users (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, username TEXT NOT NULL, password TEXT NOT NULL
        )');
        $this->isolatedDatabase->query('CREATE TABLE repair_siswa (
            id_siswa INTEGER PRIMARY KEY, id_user VARCHAR(36),
            FOREIGN KEY (id_user) REFERENCES repair_users(id_user)
        )');
        $this->isolatedDatabase->query('CREATE TABLE repair_guru (id_user VARCHAR(36) PRIMARY KEY)');
        $this->isolatedDatabase->table('users')->insertBatch([
            ['id_user' => '', 'username' => 'blank_id', 'password' => 'keep_blank_hash'],
            ['id_user' => '47c3f276-a10a-4e3e-a6cd-fb14a305fdeb', 'username' => 'valid_id', 'password' => 'keep_uuid_hash'],
        ]);
        $this->isolatedDatabase->table('siswa')->insert([
            'id_siswa' => 1, 'id_user' => '47c3f276-a10a-4e3e-a6cd-fb14a305fdeb',
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        parent::tearDown();
    }

    public function testRepairsOnlyTheEmptyIdAndKeepsCredentialsAndExistingRelations(): void
    {
        $existing = $this->isolatedDatabase->table('users')->where('username', 'valid_id')->get()->getRowArray();
        $profiles = $this->isolatedDatabase->table('siswa')->get()->getResultArray();
        $migration = new RepairEmptyUserIds(Database::forge($this->isolatedDatabase));
        $migration->up();

        $repaired = $this->isolatedDatabase->table('users')->where('username', 'blank_id')->get()->getRowArray();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $repaired['id_user']);
        $this->assertSame('keep_blank_hash', $repaired['password']);
        $this->assertSame(2, $this->isolatedDatabase->table('users')->countAllResults());
        $this->assertSame($existing, $this->isolatedDatabase->table('users')->where('username', 'valid_id')->get()->getRowArray());
        $this->assertSame($profiles, $this->isolatedDatabase->table('siswa')->get()->getResultArray());

        $migration->up();
        $migration->down();
        $this->assertSame($repaired, $this->isolatedDatabase->table('users')->where('username', 'blank_id')->get()->getRowArray());
    }

    /** @dataProvider referencingProfiles */
    public function testReferencedEmptyIdsAreLeftIntact(string $table): void
    {
        $this->isolatedDatabase->table($table)->insert(['id_user' => '']);
        $before = $this->isolatedDatabase->table('users')->get()->getResultArray();
        $failure = null;
        try {
            (new RepairEmptyUserIds(Database::forge($this->isolatedDatabase)))->up();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringContainsString('masih dipakai', $failure->getMessage());
        $this->assertSame($before, $this->isolatedDatabase->table('users')->get()->getResultArray());
        $this->assertSame(1, $this->isolatedDatabase->table($table)->where('id_user', '')->countAllResults());
    }

    public static function referencingProfiles(): array
    {
        return ['siswa' => ['siswa'], 'guru' => ['guru']];
    }
}
