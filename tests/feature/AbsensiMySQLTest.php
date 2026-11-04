<?php

use App\Database\Migrations\AllowDailyAttendanceCards;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-22-000001_AllowDailyAttendanceCards.php';

final class AbsensiMySQLTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const USER_ID = 'a0b12345-1234-4234-8234-123456789abc';
    private const PRIOR_ATTENDANCE_ID = 'b0c12345-1234-4234-8234-123456789abc';
    private const CARD_SN = '001234';

    private ?BaseConnection $isolatedDatabase = null;
    private array $originalConnections = [];
    private bool $connectionsReplaced = false;
    private array $temporaryTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('TEST_MYSQL_ABSENSI') !== '1') {
            $this->markTestSkipped('Set TEST_MYSQL_ABSENSI=1 to test attendance using temporary MySQL tables.');
        }

        $this->originalConnections = Database::getConnections();
        $configuration = config(Database::class)->default;
        $configuration['pConnect'] = false;
        $configuration['strictOn'] = true;
        $this->isolatedDatabase = Database::connect($configuration, false);
        $this->assertSame('MySQLi', $this->isolatedDatabase->DBDriver);

        // Only copy source schemas. All fixture writes use a fresh, randomized
        // prefix on this nonpersistent connection, never the application tables.
        $fixturePrefix = 'attendance_' . bin2hex(random_bytes(4)) . '_';
        foreach (['users', 'siswa', 'guru', 'kelas'] as $table) {
            $source = $this->isolatedDatabase->escapeIdentifiers($this->isolatedDatabase->prefixTable($table));
            $fixture = $this->isolatedDatabase->escapeIdentifiers($fixturePrefix . $table);
            $this->assertNotFalse($this->isolatedDatabase->query('CREATE TEMPORARY TABLE ' . $fixture . ' LIKE ' . $source));
            $this->temporaryTables[] = $fixturePrefix . $table;
        }
        $this->isolatedDatabase->setPrefix($fixturePrefix);

        // Reproduce the supplied columns, collations and indexes, including the
        // problematic UNIQUE(sn). MySQL temporary tables do not support FKs.
        $attendanceTable = $this->isolatedDatabase->escapeIdentifiers($fixturePrefix . 'absensi');
        $this->assertNotFalse($this->isolatedDatabase->query('CREATE TEMPORARY TABLE ' . $attendanceTable . ' (
            id_absensi CHAR(36) NOT NULL,
            id_user VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
            tanggal DATE NOT NULL,
            check_in DATETIME NOT NULL,
            check_out DATETIME DEFAULT NULL,
            created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            update_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sn VARCHAR(50) NOT NULL,
            PRIMARY KEY (id_absensi),
            UNIQUE KEY unique_user_tanggal (id_user, tanggal),
            UNIQUE KEY absensi_unique (sn),
            KEY idx_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'));
        $this->temporaryTables[] = $fixturePrefix . 'absensi';

        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->connectionsReplaced = true;
        $this->seedStudent();
        $teacherId = '00000000-0000-4000-8000-000000000099';
        $this->isolatedDatabase->table('users')->insert([
            'id_user' => $teacherId, 'username' => 'mysql_teacher', 'nama' => 'Guru',
            'password' => password_hash('TemporaryTeacher123!', PASSWORD_DEFAULT), 'sn' => 'MYSQL-TEACHER',
            'type' => 'guru', 'created_date' => '2026-01-01 00:00:00', 'update_date' => '2026-01-01 00:00:00',
        ]);
        $this->withSession(['auth_user' => ['id_user' => $teacherId, 'type' => 'guru']]);
    }

    protected function tearDown(): void
    {
        // Closing the isolated connection drops every temporary fixture table.
        if ($this->isolatedDatabase !== null) {
            $this->isolatedDatabase->close();
        }
        if ($this->connectionsReplaced) {
            $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        }

        parent::tearDown();
    }

    public function testOriginalUniqueCardConflictAndMigratedUuidAttendanceLifecycle(): void
    {
        $timezone = new DateTimeZone('Asia/Jakarta');
        $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('yesterday', $timezone))->format('Y-m-d');
        $this->assertNotFalse($this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => self::PRIOR_ATTENDANCE_ID,
            'id_user' => self::USER_ID,
            'tanggal' => $yesterday,
            'check_in' => $yesterday . ' 07:00:00',
            'check_out' => null,
            'sn' => self::CARD_SN,
        ]));

        // The original schema must return a controlled conflict for a card that
        // already appears on another date, without changing the previous row.
        $this->jsonPost('/api/absensi/tap', ['sn' => self::CARD_SN])->assertStatus(409);
        $this->assertSame(1, $this->isolatedDatabase->table('absensi')->countAllResults());

        $this->applyDailyCardMigration();
        $this->applyDailyCardMigration();
        $indexes = $this->isolatedDatabase->getIndexData('absensi');
        foreach ($indexes as $index) {
            $this->assertFalse($index->type === 'UNIQUE' && $index->fields === ['sn']);
        }
        $this->assertArrayHasKey('unique_user_tanggal', $indexes);

        $response = $this->jsonPost('/api/absensi/tap', [
            'sn' => ' ' . self::CARD_SN . "\r\n",
            'id_siswa' => 999,
            'id_user' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'id_absensi' => self::PRIOR_ATTENDANCE_ID,
            'tanggal' => '2000-01-01',
        ]);
        $response->assertStatus(201);
        $body = $this->body($response);
        $row = $body['data'];
        $this->assertSame('check_in', $body['action']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $row['id_absensi']);
        $this->assertNotSame(self::PRIOR_ATTENDANCE_ID, $row['id_absensi']);
        $this->assertSame(self::USER_ID, $row['id_user']);
        $this->assertSame('1', (string) $row['id_siswa']);
        $this->assertSame(self::CARD_SN, $row['sn']);
        $this->assertSame($today, $row['tanggal']);
        $this->assertNull($row['check_out']);
        $this->assertNoAccountSecrets($row);

        $attendanceId = $row['id_absensi'];
        $stored = $this->isolatedDatabase->table('absensi')->where('id_absensi', $attendanceId)->get()->getRowArray();
        $this->assertNotNull($stored);
        $this->assertSame(self::USER_ID, $stored['id_user']);
        $this->assertSame(self::CARD_SN, $stored['sn']);
        $this->assertArrayNotHasKey('id_siswa', $stored);
        $this->assertSame(2, $this->isolatedDatabase->table('absensi')->countAllResults());

        foreach ([
            '/api/absensi/' . $attendanceId,
            '/api/absensi?id_absensi=' . $attendanceId,
            '/api/absensi?id_absensi=' . $attendanceId . '&id_user=' . strtoupper(self::USER_ID),
            '/api/absensi?id_absensi=' . $attendanceId . '&id_siswa=1',
        ] as $url) {
            $read = $this->getJson($url);
            $read->assertStatus(200);
            $this->assertSame($attendanceId, $this->body($read)['data']['id_absensi']);
            $this->assertNoAccountSecrets($this->body($read)['data']);
        }

        $this->jsonPost('/api/absensi/tap', ['sn' => self::CARD_SN])->assertStatus(409);
        $olderCheckIn = (new DateTimeImmutable('now', $timezone))->modify('-11 seconds')->format('Y-m-d H:i:s');
        $this->assertNotFalse($this->isolatedDatabase->table('absensi')->where('id_absensi', $attendanceId)
            ->update(['check_in' => $olderCheckIn]));
        $checkout = $this->jsonPost('/api/absensi/tap', ['sn' => self::CARD_SN]);
        $checkout->assertStatus(200);
        $checkoutBody = $this->body($checkout);
        $this->assertSame('check_out', $checkoutBody['action']);
        $this->assertSame($attendanceId, $checkoutBody['data']['id_absensi']);
        $this->assertSame($olderCheckIn, $checkoutBody['data']['check_in']);
        $this->assertNotNull($checkoutBody['data']['check_out']);
        $this->assertSame($checkoutBody['data']['check_out'], $checkoutBody['data']['update_date']);
        $this->jsonPost('/api/absensi/tap', ['sn' => self::CARD_SN])->assertStatus(409);

        $explicitCheckout = $this->jsonPost('/api/absensi/check-out', ['id_absensi' => self::PRIOR_ATTENDANCE_ID]);
        $explicitCheckout->assertStatus(200);
        $this->assertSame(self::PRIOR_ATTENDANCE_ID, $this->body($explicitCheckout)['data']['id_absensi']);
        $this->jsonPost('/api/absensi/check-out', ['id_absensi' => self::PRIOR_ATTENDANCE_ID])->assertStatus(409);
        $this->jsonPost('/api/absensi/check-in', ['id_user' => self::USER_ID])->assertStatus(409);
        $this->jsonPost('/api/absensi/tap', ['sn' => str_repeat('A', 51)])->assertStatus(422);

        $history = $this->getJson('/api/absensi/riwayat?tanggal=' . $today . '&id_kelas=1');
        $history->assertStatus(200);
        $historyBody = $this->body($history);
        $this->assertSame(1, $historyBody['meta']['total']);
        $this->assertCount(1, $historyBody['data']);
        $historyRow = $historyBody['data'][0];
        $this->assertSame($attendanceId, $historyRow['id_absensi']);
        $this->assertSame('MySQL Attendance Student', $historyRow['nama_siswa']);
        $this->assertSame('X RPL 1', $historyRow['nama_kelas']);
        $this->assertSame('hadir', $historyRow['status_kehadiran']);
        $this->assertNoAccountSecrets($historyRow);
        $this->assertArrayNotHasKey('id_user', $historyRow);
        $this->assertArrayNotHasKey('sn', $historyRow);
        $this->assertSame(2, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    private function seedStudent(): void
    {
        $this->assertNotFalse($this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1']));
        $this->assertNotFalse($this->isolatedDatabase->table('users')->insert([
            'id_user' => self::USER_ID,
            'username' => 'mysql_attendance_student',
            'nama' => 'MySQL Attendance Student',
            'password' => password_hash('TemporaryAttendance123!', PASSWORD_DEFAULT),
            'sn' => self::CARD_SN,
            'type' => 'siswa',
            'created_date' => '2026-01-01 00:00:00',
            'update_date' => '2026-01-01 00:00:00',
        ]));
        $this->assertNotFalse($this->isolatedDatabase->table('siswa')->insert([
            'id_siswa' => 1,
            'id_user' => self::USER_ID,
            'id_kelas' => 1,
            'nama_siswa' => 'MySQL Attendance Student',
            'status' => 'aktif',
            'sn' => self::CARD_SN,
            'type' => 'siswa',
            'created_date' => '2026-01-01 00:00:00',
            'update_date' => '2026-01-01 00:00:00',
        ]));
    }

    private function applyDailyCardMigration(): void
    {
        // SHOW TABLES omits temporary tables. Prime this fixture connection's
        // schema cache so the migration's tableExists guard sees its own tables.
        $this->isolatedDatabase->dataCache['table_names'] = $this->temporaryTables;
        (new AllowDailyAttendanceCards(Database::forge('tests')))->up();
    }

    private function jsonPost(string $url, array $input): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post($url);
    }

    private function getJson(string $url): TestResponse
    {
        return $this->withHeaders([])->withBody('')->get($url);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNoAccountSecrets(array $row): void
    {
        foreach (['password', 'username', 'password_confirmation'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
    }
}
