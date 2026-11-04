<?php

use App\Database\Migrations\AddUniqueUserCredentials;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-04-000001_AddUniqueUserCredentials.php';

final class UniqueUserCredentialsMigrationTest extends CIUnitTestCase
{
    private BaseConnection $isolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'uniq_', 'DBDebug' => true,
        ], false);
        $this->isolatedDatabase->query('CREATE TABLE uniq_users (
            id_user VARCHAR(36) PRIMARY KEY, username TEXT NOT NULL, sn TEXT
        )');
        $this->isolatedDatabase->table('users')->insertBatch([
            ['id_user' => 'a', 'username' => 'budi', 'sn' => '001'],
            ['id_user' => 'b', 'username' => 'ani', 'sn' => null],
            ['id_user' => 'c', 'username' => 'cici', 'sn' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        parent::tearDown();
    }

    private function migration(): AddUniqueUserCredentials
    {
        return new AddUniqueUserCredentials(Database::forge($this->isolatedDatabase));
    }

    /** @dataProvider duplicateRows */
    public function testDuplicateUsernameOrSnIsRejectedByTheDatabase(array $row): void
    {
        $this->migration()->up();
        $this->migration()->up(); // idempotent

        $this->expectException(DatabaseException::class);
        $this->isolatedDatabase->table('users')->insert($row);
    }

    public static function duplicateRows(): array
    {
        return [
            'username' => [['id_user' => 'd', 'username' => 'budi', 'sn' => '999']],
            'sn' => [['id_user' => 'd', 'username' => 'dodi', 'sn' => '001']],
        ];
    }

    public function testAccountsWithoutSnAreStillAllowed(): void
    {
        $this->migration()->up();
        $this->isolatedDatabase->table('users')->insert(['id_user' => 'd', 'username' => 'dodi', 'sn' => null]);
        $this->assertSame(4, $this->isolatedDatabase->table('users')->countAllResults());
    }

    public function testExistingDuplicatesAbortWithoutChangingData(): void
    {
        $this->isolatedDatabase->table('users')->insert(['id_user' => 'd', 'username' => 'budi', 'sn' => '002']);

        try {
            $this->migration()->up();
            $this->fail('Migrasi seharusnya berhenti saat ada username ganda.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('users.username', $e->getMessage());
        }
        $this->assertSame(4, $this->isolatedDatabase->table('users')->countAllResults());
    }

    public function testDownRemovesTheKeys(): void
    {
        $this->migration()->up();
        $this->migration()->down();
        $this->isolatedDatabase->table('users')->insert(['id_user' => 'd', 'username' => 'budi', 'sn' => '001']);
        $this->assertSame(4, $this->isolatedDatabase->table('users')->countAllResults());
    }
}
