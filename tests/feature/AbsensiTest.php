<?php

use App\Database\Migrations\CreateAbsensi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-15-000001_CreateAbsensi.php';

final class AbsensiTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;
    private int $attendanceSequence = 0;

    /** @var array<string, BaseConnection> */
    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => 'test_',
            'DBDebug' => true,
            'foreignKeys' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE test_siswa (
            id_siswa INTEGER PRIMARY KEY, id_user TEXT, nama_siswa TEXT, status TEXT,
            created_date TEXT, update_date TEXT, sn TEXT, type TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE test_users (
            id_user VARCHAR(36) PRIMARY KEY, username TEXT, password TEXT, nama TEXT, type TEXT
        )');
        $this->isolatedDatabase->table('users')->insertBatch([
            ['id_user' => $this->userUuid(1), 'username' => 'student1', 'password' => 'must-not-be-returned'],
            ['id_user' => $this->userUuid(2), 'username' => 'student2', 'password' => 'must-not-be-returned'],
        ]);
        $this->isolatedDatabase->table('siswa')->insertBatch([
            ['id_siswa' => 1, 'id_user' => $this->userUuid(1), 'nama_siswa' => 'Siswa Pertama', 'status' => 'aktif', 'sn' => 'S001', 'type' => 'siswa'],
            ['id_siswa' => 2, 'id_user' => $this->userUuid(2), 'nama_siswa' => 'Siswa Kedua', 'status' => 'aktif', 'sn' => 'S002', 'type' => 'siswa'],
        ]);
        (new CreateAbsensi(Database::forge('tests')))->up();
        $this->isolatedDatabase->table('users')->insert([
            'id_user' => 99, 'username' => 'teacher', 'nama' => 'Guru', 'type' => 'guru',
        ]);
        $this->withSession(['auth_user' => ['id_user' => 99, 'type' => 'guru']]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);

        parent::tearDown();
    }

    public function testEmptyAttendanceListReturnsAnEmptyArray(): void
    {
        $response = $this->get('/api/absensi');

        $response->assertStatus(200);
        $this->assertSame([], $this->body($response)['data']);
    }

    public function testListAndDetailIncludeStudentIdentityWithoutAccountFields(): void
    {
        $first = $this->seedAttendance(1, '2026-10-13');
        $second = $this->seedAttendance(2, '2026-10-14');
        $third = $this->seedAttendance(1, '2026-10-14');
        $response = $this->get('/api/absensi');

        $response->assertStatus(200);
        $rows = $this->body($response)['data'];
        $this->assertSame([$third, $second, $first], array_column($rows, 'id_absensi'));
        $this->assertSame('Siswa Pertama', $rows[0]['nama_siswa']);
        $this->assertSame('S001', $rows[0]['sn']);
        $this->assertArrayNotHasKey('password', $rows[0]);
        $this->assertArrayNotHasKey('username', $rows[0]);

        $detail = $this->get('/api/absensi/' . $second);
        $detail->assertStatus(200);
        $detail->assertJSONFragment(['data' => ['id_siswa' => 2, 'nama_siswa' => 'Siswa Kedua', 'sn' => 'S002']]);
        $this->get('/api/absensi/' . $this->attendanceUuid(999))->assertStatus(404);
        $this->get('/api/absensi/999')->assertStatus(422);
        $this->get('/api/absensi/0')->assertStatus(422);
    }

    /** @dataProvider checkInRoutes */
    public function testCheckInAcceptsJsonAndFormOnBothRoutes(string $route, bool $json): void
    {
        $response = $json
            ? $this->jsonPost($route, '{"id_siswa":1}')
            : $this->post($route, ['id_siswa' => '1']);

        $response->assertStatus(201);
        $row = $this->body($response)['data'];
        $this->assertSame(1, $row['id_siswa']);
        $this->assertSame($this->userUuid(1), $row['id_user']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $row['id_absensi']);
        $this->assertSame('Siswa Pertama', $row['nama_siswa']);
        $this->assertSame('S001', $row['sn']);
        $this->assertNull($row['check_out']);
        $this->assertSame(1, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    /** @dataProvider checkInRoutes */
    public function testCheckInAcceptsUserUuidAndPersistsTheExistingSchema(string $route, bool $json): void
    {
        $input = ['id_user' => $this->userUuid(2)];
        $response = $json
            ? $this->jsonPost($route, json_encode($input, JSON_THROW_ON_ERROR))
            : $this->post($route, $input);

        $response->assertStatus(201);
        $row = $this->body($response)['data'];
        $this->assertSame($this->userUuid(2), $row['id_user']);
        $this->assertSame(2, $row['id_siswa']);
        $this->assertSame('S002', $row['sn']);
        $stored = $this->isolatedDatabase->table('absensi')->get()->getRowArray();
        $this->assertSame($row['id_absensi'], $stored['id_absensi']);
        $this->assertSame($row['id_user'], $stored['id_user']);
        $this->assertSame('S002', $stored['sn']);
        $this->assertArrayNotHasKey('id_siswa', $stored);
    }

    /** @dataProvider invalidUserIds */
    public function testCheckInRejectsMalformedOrUnknownUserIds(mixed $id, int $status): void
    {
        $this->jsonPost('/api/absensi', json_encode(['id_user' => $id], JSON_THROW_ON_ERROR))->assertStatus($status);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function invalidUserIds(): array
    {
        return [
            'null' => [null, 422], 'zero' => [0, 422], 'text' => ['invalid', 422],
            'array' => [[1], 422], 'invalid UUID' => ['00000000-0000-4000-8000-00000000000Z', 422],
            'unknown UUID' => ['00000000-0000-4000-8000-000000000999', 404],
        ];
    }

    /** @dataProvider incompleteStudentRecords */
    public function testCheckInRejectsIncompleteStoredStudentIdentity(string $field, mixed $value): void
    {
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->update([$field => $value]);

        $this->post('/api/absensi/check-in', ['id_siswa' => 1])->assertStatus(409);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function incompleteStudentRecords(): array
    {
        return [
            'missing user' => ['id_user', null],
            'unknown user' => ['id_user', '00000000-0000-4000-8000-000000000999'],
            'invalid user' => ['id_user', 'invalid'],
            'missing card' => ['sn', null],
            'empty card' => ['sn', ''],
            'oversized card' => ['sn', str_repeat('A', 51)],
            'control character in card' => ['sn', "S0\n01"],
        ];
    }

    public function testAttendanceKeepsTheRecordedCardWhenStudentCardChanges(): void
    {
        $response = $this->post('/api/absensi/check-in', ['id_siswa' => 1]);
        $response->assertStatus(201);
        $attendanceId = $this->body($response)['data']['id_absensi'];
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->update(['sn' => 'NEW_CARD']);

        $detail = $this->get('/api/absensi/' . $attendanceId);
        $detail->assertStatus(200);
        $this->assertSame('S001', $this->body($detail)['data']['sn']);
        $this->assertSame('S001', $this->isolatedDatabase->table('absensi')->get()->getRowArray()['sn']);
    }

    public static function checkInRoutes(): array
    {
        return [
            'base JSON' => ['/api/absensi', true],
            'base form' => ['/api/absensi', false],
            'alias JSON' => ['/api/absensi/check-in', true],
            'alias form' => ['/api/absensi/check-in', false],
        ];
    }

    public function testCheckInUsesServerWibTimestampsAndIgnoresClientFields(): void
    {
        $before = $this->nowWib();
        $response = $this->jsonPost('/api/absensi', json_encode([
            'id_siswa' => 1,
            'id_absensi' => $this->attendanceUuid(9876),
            'id_guru' => 99,
            'tanggal' => '2000-01-01',
            'check_in' => '2000-01-01 01:02:03',
            'check_out' => '2000-01-01 04:05:06',
            'created_date' => '2000-01-01 01:02:03',
            'update_date' => '2000-01-01 01:02:03',
            'nama_siswa' => 'Changed by client',
            'password' => 'must-not-be-returned',
        ], JSON_THROW_ON_ERROR));
        $after = $this->nowWib();

        $response->assertStatus(201);
        $row = $this->body($response)['data'];
        $this->assertGreaterThanOrEqual($before, $row['check_in']);
        $this->assertLessThanOrEqual($after, $row['check_in']);
        $this->assertSame(substr($row['check_in'], 0, 10), $row['tanggal']);
        $this->assertSame($row['check_in'], $row['created_date']);
        $this->assertSame($row['check_in'], $row['update_date']);
        $this->assertNull($row['check_out']);
        $this->assertNotSame($this->attendanceUuid(9876), $row['id_absensi']);
        $this->assertSame('Siswa Pertama', $row['nama_siswa']);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('id_guru', $row);
    }

    /** @dataProvider invalidCheckInIds */
    public function testCheckInRejectsMissingMalformedOrUnknownStudentIds(array $input, int $status): void
    {
        $response = $this->jsonPost('/api/absensi', json_encode($input, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));

        $response->assertStatus($status);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function invalidCheckInIds(): array
    {
        return [
            'missing' => [[], 422],
            'null' => [['id_siswa' => null], 422],
            'zero' => [['id_siswa' => 0], 422],
            'negative' => [['id_siswa' => -1], 422],
            'text' => [['id_siswa' => 'abc'], 422],
            'fraction' => [['id_siswa' => 1.5], 422],
            'boolean' => [['id_siswa' => true], 422],
            'array' => [['id_siswa' => [1]], 422],
            'overflow' => [['id_siswa' => '9999999999999999999999999'], 422],
            'unknown' => [['id_siswa' => 999], 404],
        ];
    }

    /** @dataProvider invalidJsonBodies */
    public function testBothWriteEndpointsRejectInvalidJsonBodies(string $route, string $body): void
    {
        $this->jsonPost($route, $body)->assertStatus(400);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function invalidJsonBodies(): array
    {
        $cases = [];
        foreach (['/api/absensi', '/api/absensi/check-out'] as $route) {
            foreach (['malformed' => '{', 'array' => '[1]', 'empty array' => '[]', 'scalar' => '1', 'null' => 'null', 'empty' => ''] as $name => $body) {
                $cases[$route . ' ' . $name] = [$route, $body];
            }
        }

        return $cases;
    }

    public function testDuplicateCheckInReturnsConflictAndKeepsOneRecord(): void
    {
        $first = $this->post('/api/absensi', ['id_siswa' => 1]);
        $first->assertStatus(201);
        $this->post('/api/absensi/check-in', ['id_siswa' => 1])->assertStatus(409);
        $this->assertSame(1, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    /** @dataProvider databaseConstraintCases */
    public function testMigrationEnforcesUniqueDayAndUserForeignKey(bool $duplicate): void
    {
        $this->seedAttendance(1, '2026-10-14');

        $this->expectException(DatabaseException::class);
        $this->seedAttendance($duplicate ? 1 : 999, '2026-10-14');
    }

    public static function databaseConstraintCases(): array
    {
        return ['duplicate user and day' => [true], 'missing user' => [false]];
    }

    public function testCheckOutPreservesCheckInAndRejectsSecondCheckOut(): void
    {
        $today = substr($this->nowWib(), 0, 10);
        $id = $this->seedAttendance(1, $today, '00:00:00');
        $before = $this->nowWib();
        $response = $this->jsonPost('/api/absensi/check-out', json_encode([
            'id_absensi' => $id,
            'id_siswa' => 2,
            'check_in' => '1999-01-01 00:00:00',
            'check_out' => '1999-01-01 00:00:00',
        ], JSON_THROW_ON_ERROR));
        $after = $this->nowWib();

        $response->assertStatus(200);
        $row = $this->body($response)['data'];
        $this->assertSame(1, $row['id_siswa']);
        $this->assertSame($today . ' 00:00:00', $row['check_in']);
        $this->assertSame($today . ' 00:00:00', $row['created_date']);
        $this->assertGreaterThanOrEqual($before, $row['check_out']);
        $this->assertLessThanOrEqual($after, $row['check_out']);
        $this->assertSame($row['check_out'], $row['update_date']);
        $this->jsonPost('/api/absensi/check-out', json_encode(['id_absensi' => $id], JSON_THROW_ON_ERROR))->assertStatus(409);
        $this->assertSame($row['check_out'], $this->isolatedDatabase->table('absensi')->where('id_absensi', $id)->get()->getRowArray()['check_out']);
    }

    public function testFormCheckOutAcceptsAnAttendanceId(): void
    {
        $id = $this->seedAttendance(1, substr($this->nowWib(), 0, 10), '00:00:00');

        $this->post('/api/absensi/check-out', ['id_absensi' => (string) $id])->assertStatus(200);
    }

    public function testPreviousDayAttendanceCannotBeCheckedOutWithTodaysTime(): void
    {
        $id = $this->seedAttendance(1, '2000-01-01');

        $this->jsonPost('/api/absensi/check-out', json_encode(['id_absensi' => $id], JSON_THROW_ON_ERROR))
            ->assertStatus(409);
        $this->assertNull($this->isolatedDatabase->table('absensi')->where('id_absensi', $id)
            ->get()->getRowArray()['check_out']);
    }

    /** @dataProvider invalidCheckOutIds */
    public function testCheckOutRejectsInvalidOrMissingAttendanceIds(array $input, int $status): void
    {
        $this->jsonPost('/api/absensi/check-out', json_encode($input, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR))->assertStatus($status);
    }

    public static function invalidCheckOutIds(): array
    {
        return [
            'missing' => [[], 422],
            'zero' => [['id_absensi' => 0], 422],
            'text' => [['id_absensi' => 'abc'], 422],
            'array' => [['id_absensi' => [1]], 422],
            'integer' => [['id_absensi' => 999], 422],
            'invalid UUID' => [['id_absensi' => '00000000-0000-4000-8000-00000000000Z'], 422],
            'unknown' => [['id_absensi' => '10000000-0000-4000-8000-000000000999'], 404],
        ];
    }

    public function testListFiltersByStudentDateAndAttendanceId(): void
    {
        $first = $this->seedAttendance(1, '2026-10-13');
        $second = $this->seedAttendance(1, '2026-10-14');
        $third = $this->seedAttendance(2, '2026-10-14');

        foreach ([
            'id_siswa=1' => [$second, $first],
            'id_user=' . $this->userUuid(1) => [$second, $first],
            'id_user=' . $this->userUuid(1) . '&tanggal=2026-10-14' => [$second],
            'id_user=' . $this->userUuid(999) => [],
            'tanggal=2026-10-14' => [$third, $second],
            'id_siswa=1&tanggal=2026-10-14' => [$second],
            'id_siswa=999' => [],
        ] as $query => $ids) {
            $response = $this->get('/api/absensi?' . $query);
            $response->assertStatus(200);
            $this->assertSame($ids, array_column($this->body($response)['data'], 'id_absensi'));
        }
        $response = $this->get('/api/absensi?id_absensi=' . $second);
        $response->assertStatus(200);
        $this->assertSame($second, $this->body($response)['data']['id_absensi']);
        $this->get('/api/absensi?id_absensi=' . $this->attendanceUuid(999))->assertStatus(404);
        $this->get('/api/absensi?id_absensi=999')->assertStatus(422);
        $this->get('/api/absensi?id_absensi=' . $second . '&id_siswa=2')->assertStatus(404);
    }

    /** @dataProvider invalidFilters */
    public function testListRejectsMalformedFilters(string $query): void
    {
        $this->get('/api/absensi?' . $query)->assertStatus(422);
    }

    public static function invalidFilters(): array
    {
        return [
            'user zero' => ['id_user=0'],
            'user text' => ['id_user=nope'],
            'user array' => ['id_user[]=1'],
            'student zero' => ['id_siswa=0'],
            'student text' => ['id_siswa=nope'],
            'student array' => ['id_siswa[]=1'],
            'attendance zero' => ['id_absensi=0'],
            'attendance text' => ['id_absensi=nope'],
            'attendance array' => ['id_absensi[]=1'],
            'date format' => ['tanggal=14-10-2026'],
            'impossible date' => ['tanggal=2026-02-30'],
            'empty date' => ['tanggal='],
            'date array' => ['tanggal[]=2026-10-14'],
        ];
    }

    private function seedAttendance(int $studentId, string $date, string $time = '07:00:00'): string
    {
        $id = $this->attendanceUuid(++$this->attendanceSequence);
        $this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => $id,
            'id_user' => $this->userUuid($studentId),
            'sn' => $studentId === 1 ? 'S001' : 'S002',
            'tanggal' => $date,
            'check_in' => $date . ' ' . $time,
            'check_out' => null,
            'created_date' => $date . ' ' . $time,
            'update_date' => $date . ' ' . $time,
        ]);

        return $id;
    }

    private function userUuid(int $studentId): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $studentId);
    }

    private function attendanceUuid(int $sequence): string
    {
        return sprintf('10000000-0000-4000-8000-%012d', $sequence);
    }

    private function jsonPost(string $route, string $body): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])->withBody($body)->post($route);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function nowWib(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}
