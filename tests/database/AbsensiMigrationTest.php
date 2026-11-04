<?php

use App\Database\Migrations\AllowDailyAttendanceCards;
use App\Database\Migrations\CreateAbsensi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-15-000001_CreateAbsensi.php';
require_once APPPATH . 'Database/Migrations/2026-10-22-000001_AllowDailyAttendanceCards.php';

final class AbsensiMigrationTest extends CIUnitTestCase
{
    private const USER_ID = '47c3f276-a10a-4e3e-a6cd-fb14a305fdeb';

    private BaseConnection $isolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'attendance_', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->isolatedDatabase->query('CREATE TABLE attendance_users (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, username TEXT NOT NULL
        )');
        $this->isolatedDatabase->table('users')->insert([
            'id_user' => self::USER_ID, 'username' => 'existing-user',
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        parent::tearDown();
    }

    public function testFreshSchemaUsesTextIdsAndAllowsDailyCardReuse(): void
    {
        $migration = new CreateAbsensi(Database::forge($this->isolatedDatabase));
        $migration->up();
        $migration->up();
        $this->insertAttendance('2026-10-21');
        $this->insertAttendance('2026-10-22');

        $fields = array_column($this->isolatedDatabase->getFieldData('absensi'), null, 'name');
        $this->assertSame('char', strtolower($fields['id_absensi']->type));
        $this->assertSame('varchar', strtolower($fields['id_user']->type));
        $this->assertSame('varchar', strtolower($fields['sn']->type));
        $this->assertFalse($fields['sn']->nullable);
        $this->assertArrayNotHasKey('id_siswa', $fields);
        $this->assertSame(2, $this->isolatedDatabase->table('absensi')->countAllResults());
        $row = $this->isolatedDatabase->table('absensi')->get()->getRowArray();
        $this->assertNotNull($row['created_date']);
        $this->assertNotNull($row['update_date']);
        $indexes = $this->isolatedDatabase->getIndexData('absensi');
        $this->assertSame(['id_user', 'tanggal'], $indexes['unique_user_tanggal']->fields);
        $this->assertSame('UNIQUE', $indexes['unique_user_tanggal']->type);
        $this->assertSame('INDEX', $indexes['idx_absensi_sn']->type);
        $this->assertSame(['tanggal'], $indexes['idx_tanggal']->fields);
    }

    public function testExistingSchemaAndDataSurviveBothMigrationsAndRollback(): void
    {
        $this->createExistingSchema();
        $this->insertAttendance('2026-10-21');
        $before = $this->isolatedDatabase->table('absensi')->get()->getResultArray();
        $foreignKeys = $this->isolatedDatabase->getForeignKeyData('absensi');
        $create = new CreateAbsensi(Database::forge($this->isolatedDatabase));
        $create->up();
        $this->assertSame($before, $this->isolatedDatabase->table('absensi')->get()->getResultArray());
        $this->assertArrayHasKey('absensi_unique', $this->isolatedDatabase->getIndexData('absensi'));

        $migration = new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase));
        $migration->up();
        $indexes = $this->isolatedDatabase->getIndexData('absensi');
        $this->assertArrayNotHasKey('absensi_unique', $indexes);
        $primary = array_values(array_filter($indexes, static fn ($index) => $index->type === 'PRIMARY'));
        $this->assertSame(['id_absensi'], $primary[0]->fields);
        $this->assertSame('UNIQUE', $indexes['unique_user_tanggal']->type);
        $this->assertSame('INDEX', $indexes['idx_absensi_sn']->type);
        $this->assertEquals($foreignKeys, $this->isolatedDatabase->getForeignKeyData('absensi'));
        $this->assertSame($before, $this->isolatedDatabase->table('absensi')->get()->getResultArray());
        $this->insertAttendance('2026-10-22');

        $migration->up();
        $migration->down();
        $create->down();
        $this->assertSame(2, $this->isolatedDatabase->table('absensi')->countAllResults());
        $this->assertEquals($indexes, $this->isolatedDatabase->getIndexData('absensi'));
    }

    /** @dataProvider constraintCases */
    public function testUserDayAndForeignKeyConstraintsRemainEnforced(bool $existingSchema, string $violation): void
    {
        if ($existingSchema) {
            $this->createExistingSchema();
        } else {
            (new CreateAbsensi(Database::forge($this->isolatedDatabase)))->up();
        }
        (new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase)))->up();
        $this->insertAttendance('2026-10-21');

        $this->expectException(DatabaseException::class);
        if ($violation === 'duplicate') {
            $this->insertAttendance('2026-10-21', self::USER_ID, '00000000-0000-4000-8000-000000000003');
        } elseif ($violation === 'orphan') {
            $this->insertAttendance('2026-10-22', '00000000-0000-4000-8000-000000000999');
        } elseif ($violation === 'primary') {
            $this->insertAttendance('2026-10-22', self::USER_ID, '00000000-0000-4000-8000-000000000021');
        } else {
            $this->isolatedDatabase->table('users')->where('id_user', self::USER_ID)->delete();
        }
    }

    public static function constraintCases(): array
    {
        return [
            'new duplicate user/day' => [false, 'duplicate'],
            'existing duplicate user/day' => [true, 'duplicate'],
            'new missing user' => [false, 'orphan'],
            'existing missing user' => [true, 'orphan'],
            'new duplicate primary key' => [false, 'primary'],
            'existing duplicate primary key' => [true, 'primary'],
            'new restricted user deletion' => [false, 'delete'],
            'existing restricted user deletion' => [true, 'delete'],
        ];
    }

    public function testForeignKeyUpdateStillCascades(): void
    {
        $this->createExistingSchema();
        $this->insertAttendance('2026-10-21');
        (new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase)))->up();
        $newId = '00000000-0000-4000-8000-000000000002';
        $this->isolatedDatabase->table('users')->where('id_user', self::USER_ID)->update(['id_user' => $newId]);
        $this->assertSame($newId, $this->isolatedDatabase->table('absensi')->get()->getRowArray()['id_user']);
    }

    public function testAlreadyIndexedSchemaDoesNotGainDuplicateIndexes(): void
    {
        (new CreateAbsensi(Database::forge($this->isolatedDatabase)))->up();
        $indexes = $this->isolatedDatabase->getIndexData('absensi');
        $migration = new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase));
        $migration->up();
        $migration->up();
        $this->assertEquals($indexes, $this->isolatedDatabase->getIndexData('absensi'));
    }

    public function testUnrelatedUniqueIndexesArePreserved(): void
    {
        $this->createExistingSchema();
        $forge = Database::forge($this->isolatedDatabase);
        $forge->addUniqueKey(['sn', 'tanggal'], 'custom_card_day');
        $forge->processIndexes('absensi');
        (new AllowDailyAttendanceCards($forge))->up();
        $indexes = $this->isolatedDatabase->getIndexData('absensi');
        $this->assertSame('UNIQUE', $indexes['custom_card_day']->type);
        $this->assertSame(['sn', 'tanggal'], $indexes['custom_card_day']->fields);
        $this->assertArrayNotHasKey('absensi_unique', $indexes);
    }

    public function testLegacyTableIsLeftUntouched(): void
    {
        $this->isolatedDatabase->query('CREATE TABLE attendance_absensi (id_absensi INTEGER PRIMARY KEY, id_siswa INTEGER)');
        $this->isolatedDatabase->table('absensi')->insert(['id_absensi' => 1, 'id_siswa' => 7]);
        $this->isolatedDatabase->resetDataCache();
        (new CreateAbsensi(Database::forge($this->isolatedDatabase)))->up();
        (new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase)))->up();
        $this->assertSame(['id_absensi' => 1, 'id_siswa' => 7], $this->isolatedDatabase->table('absensi')->get()->getRowArray());
    }

    public function testMissingAttendanceTableRequiresNoChange(): void
    {
        (new AllowDailyAttendanceCards(Database::forge($this->isolatedDatabase)))->up();
        $this->assertFalse($this->isolatedDatabase->tableExists('absensi'));
    }

    private function createExistingSchema(): void
    {
        // Reproduce the supplied schema independently of CreateAbsensi::up().
        $forge = Database::forge($this->isolatedDatabase);
        $forge->addField([
            'id_absensi' => ['type' => 'CHAR', 'constraint' => 36, 'null' => false],
            'id_user' => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => false],
            'tanggal' => ['type' => 'DATE', 'null' => false],
            'check_in' => ['type' => 'DATETIME', 'null' => false],
            'check_out' => ['type' => 'DATETIME', 'null' => true],
            'created_date' => ['type' => 'DATETIME', 'null' => false, 'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP')],
            'update_date' => ['type' => 'DATETIME', 'null' => false, 'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP')],
            'sn' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
        ]);
        $forge->addKey('id_absensi', true);
        $forge->addUniqueKey(['id_user', 'tanggal'], 'unique_user_tanggal');
        $forge->addUniqueKey('sn', 'absensi_unique');
        $forge->addKey('tanggal', false, false, 'idx_tanggal');
        $forge->addForeignKey('id_user', 'users', 'id_user', 'CASCADE', 'RESTRICT');
        $forge->createTable('absensi');
    }

    private function insertAttendance(string $date, string $userId = self::USER_ID, ?string $id = null): void
    {
        $this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => $id ?? '00000000-0000-4000-8000-0000000000' . substr($date, -2),
            'id_user' => $userId, 'tanggal' => $date,
            'check_in' => $date . ' 07:00:00', 'sn' => 'S001',
        ]);
    }
}
