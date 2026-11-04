<?php

use App\Database\Migrations\CreateAbsensi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-15-000001_CreateAbsensi.php';

final class RfidAbsensiTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;
    private array $originalConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'rfid_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE rfid_siswa (
            id_siswa INTEGER PRIMARY KEY, id_user TEXT, nama_siswa TEXT, sn TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE rfid_guru (
            id_guru INTEGER PRIMARY KEY, nama TEXT, sn TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE rfid_users (
            id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, type TEXT
        )');
        $this->isolatedDatabase->table('users')->insertBatch([
            ['id_user' => $this->userUuid(1)], ['id_user' => $this->userUuid(2)],
        ]);
        $this->isolatedDatabase->table('siswa')->insertBatch([
            ['id_siswa' => 1, 'id_user' => $this->userUuid(1), 'nama_siswa' => 'Siswa Satu', 'sn' => '001234'],
            ['id_siswa' => 2, 'id_user' => $this->userUuid(2), 'nama_siswa' => 'Siswa Dua', 'sn' => '1234'],
        ]);
        $this->isolatedDatabase->table('guru')->insert(['id_guru' => 1, 'nama' => 'Guru', 'sn' => 'GURU001']);
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

    public function testCardTapChecksInDebouncesThenChecksOutAndStops(): void
    {
        $before = $this->nowWib();
        $first = $this->jsonPost('/api/absensi/tap', ['sn' => '001234', 'id_siswa' => 2, 'check_in' => '2000-01-01 00:00:00']);
        $first->assertStatus(201);
        $body = $this->body($first);
        $this->assertSame('check_in', $body['action']);
        $this->assertSame(1, $body['data']['id_siswa']);
        $this->assertSame('001234', $body['data']['sn']);
        $this->assertSame($this->userUuid(1), $body['data']['id_user']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['data']['id_absensi']);
        $this->assertGreaterThanOrEqual($before, $body['data']['check_in']);
        $this->assertLessThanOrEqual($this->nowWib(), $body['data']['check_in']);
        $this->assertSame(substr($body['data']['check_in'], 0, 10), $body['data']['tanggal']);
        $this->assertNull($body['data']['check_out']);

        $this->jsonPost('/api/absensi/tap', ['sn' => '001234'])->assertStatus(409);
        $this->assertNull($this->row()['check_out']);

        $older = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->modify('-11 seconds')->format('Y-m-d H:i:s');
        $this->isolatedDatabase->table('absensi')->where('id_absensi', $body['data']['id_absensi'])->update(['check_in' => $older]);
        $second = $this->jsonPost('/api/absensi/tap', ['sn' => '001234']);
        $second->assertStatus(200);
        $secondBody = $this->body($second);
        $this->assertSame('check_out', $secondBody['action']);
        $this->assertSame($body['data']['id_absensi'], $secondBody['data']['id_absensi']);
        $this->assertSame($older, $secondBody['data']['check_in']);
        $this->assertNotNull($secondBody['data']['check_out']);
        $this->assertSame($secondBody['data']['check_out'], $secondBody['data']['update_date']);

        $this->jsonPost('/api/absensi/tap', ['sn' => '001234'])->assertStatus(409);
        $this->assertSame($secondBody['data']['check_out'], $this->row()['check_out']);
        $this->assertSame(1, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public function testPulangModeChecksOutStudentsBackToBackWithoutDelay(): void
    {
        // Siswa A dan B sudah absen masuk barusan; mode pulang langsung memproses A lalu B.
        foreach (['001234', '1234'] as $card) {
            $this->jsonPost('/api/absensi', ['sn' => $card, 'jenis_absensi' => 'masuk'])->assertStatus(201);
        }

        $a = $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => 'pulang']);
        $a->assertStatus(200);
        $this->assertSame('check_out', $this->body($a)['action']);
        $this->assertSame('Siswa Satu', $this->body($a)['data']['nama_siswa']);
        $b = $this->jsonPost('/api/absensi', ['sn' => '1234', 'jenis_absensi' => 'pulang']);
        $b->assertStatus(200);
        $this->assertSame('Siswa Dua', $this->body($b)['data']['nama_siswa']);

        // Tap ulang A pada mode pulang tidak mengubah jam pulang yang sudah tercatat.
        $checkOut = $this->body($a)['data']['check_out'];
        $again = $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => 'pulang']);
        $again->assertStatus(409);
        $this->assertStringContainsString('sudah absen pulang', $this->body($again)['message']);
        $this->assertSame($checkOut, $this->isolatedDatabase->table('absensi')
            ->where('id_user', $this->userUuid(1))->get()->getRow()->check_out);
    }

    public function testMasukModeStudentsBackToBackAndNoAccidentalCheckOut(): void
    {
        $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => 'masuk'])->assertStatus(201);
        $this->jsonPost('/api/absensi', ['sn' => '1234', 'jenis_absensi' => 'masuk'])->assertStatus(201);

        $older = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->isolatedDatabase->table('absensi')->update(['check_in' => $older]);
        $again = $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => ' MASUK ']);
        $again->assertStatus(409);
        $this->assertStringContainsString('sudah absen masuk', $this->body($again)['message']);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->where('check_out IS NOT NULL')->countAllResults());
    }

    public function testPulangModeWithoutCheckInDoesNotCreateAttendance(): void
    {
        $response = $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => 'pulang']);

        $response->assertStatus(409);
        $this->assertStringContainsString('belum absen masuk', $this->body($response)['message']);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    /** @dataProvider invalidAttendanceTypes */
    public function testUnknownAttendanceTypeIsRejected(mixed $type): void
    {
        $response = $this->jsonPost('/api/absensi', ['sn' => '001234', 'jenis_absensi' => $type]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('jenis_absensi', $this->body($response)['errors']);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function invalidAttendanceTypes(): array
    {
        return ['izin' => ['izin'], 'empty' => [''], 'number' => [1], 'array' => [['masuk']]];
    }

    public function testUnknownCardInPulangModeIsNotFound(): void
    {
        $this->jsonPost('/api/absensi', ['sn' => 'TIDAKADA', 'jenis_absensi' => 'pulang'])->assertStatus(404);
    }

    public function testBaseEndpointDispatchesCardSnAndPreservesLeadingZeros(): void
    {
        $first = $this->post('/api/absensi', ['sn' => '001234']);
        $first->assertStatus(201);
        $this->assertSame(1, $this->body($first)['data']['id_siswa']);
        $second = $this->post('/api/absensi', ['sn' => '1234']);
        $second->assertStatus(201);
        $this->assertSame(2, $this->body($second)['data']['id_siswa']);
    }

    public function testScannerWhitespaceIsTrimmed(): void
    {
        $response = $this->post('/api/absensi/tap', ['sn' => " 001234\r\n"]);
        $response->assertStatus(201);
        $this->assertSame(1, $this->body($response)['data']['id_siswa']);
    }

    public function testUnknownAndTeacherCardsCannotCreateStudentAttendance(): void
    {
        foreach (['UNKNOWN', 'GURU001'] as $sn) {
            $this->post('/api/absensi/tap', ['sn' => $sn])->assertStatus(404);
        }
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public function testDuplicateCardRegistrationIsRejectedInsteadOfPickingAStudent(): void
    {
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->update(['sn' => '001234']);
        $this->post('/api/absensi/tap', ['sn' => '001234'])->assertStatus(409);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    /** @dataProvider invalidCards */
    public function testInvalidCardsDoNotWriteAttendance(mixed $sn): void
    {
        $this->jsonPost('/api/absensi/tap', ['sn' => $sn])->assertStatus(422);
        $this->assertSame(0, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public static function invalidCards(): array
    {
        return [
            'null' => [null], 'empty' => [''], 'whitespace' => ['   '],
            'number' => [1234], 'boolean' => [true], 'array' => [['001234']],
            'too long' => [str_repeat('A', 51)], 'control character' => ["001\n234"],
        ];
    }

    public function testMissingCardAndMalformedJsonAreRejected(): void
    {
        $this->post('/api/absensi/tap', [])->assertStatus(422);
        foreach (['{', '[]', 'null'] as $body) {
            $this->withHeaders(['Content-Type' => 'application/json'])->withBody($body)
                ->post('/api/absensi/tap')->assertStatus(400);
        }
    }

    public function testPreviousDayAttendanceDoesNotPreventTodaysCheckIn(): void
    {
        $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => '10000000-0000-4000-8000-000000000001',
            'id_user' => $this->userUuid(1), 'sn' => '001234', 'tanggal' => $yesterday,
            'check_in' => $yesterday . ' 07:00:00', 'check_out' => $yesterday . ' 15:00:00',
            'created_date' => $yesterday . ' 07:00:00', 'update_date' => $yesterday . ' 15:00:00',
        ]);
        $response = $this->post('/api/absensi/tap', ['sn' => '001234']);
        $response->assertStatus(201);
        $this->assertSame('check_in', $this->body($response)['action']);
        $this->assertSame(2, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public function testCardUidAllowsExactlyFiftyCharacters(): void
    {
        $sn = str_repeat('A', 50);
        $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->update(['sn' => $sn]);
        $response = $this->post('/api/absensi/tap', ['sn' => $sn]);
        $response->assertStatus(201);
        $this->assertSame($sn, $this->body($response)['data']['sn']);
        $this->assertSame($sn, $this->row()['sn']);
    }

    public function testLegacyUniqueCardConstraintReturnsConflictInsteadOfServerError(): void
    {
        $this->isolatedDatabase->query('CREATE UNIQUE INDEX legacy_absensi_unique ON rfid_absensi (sn)');
        $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => '10000000-0000-4000-8000-000000000001',
            'id_user' => $this->userUuid(1), 'sn' => '001234', 'tanggal' => $yesterday,
            'check_in' => $yesterday . ' 07:00:00', 'check_out' => $yesterday . ' 15:00:00',
            'created_date' => $yesterday . ' 07:00:00', 'update_date' => $yesterday . ' 15:00:00',
        ]);

        $response = $this->post('/api/absensi/tap', ['sn' => '001234']);
        $response->assertStatus(409);
        $this->assertStringContainsString('sn', strtolower($this->body($response)['message']));
        $this->assertSame(1, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    private function userUuid(int $studentId): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $studentId);
    }

    private function jsonPost(string $route, array $input): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post($route);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function row(): array
    {
        return $this->isolatedDatabase->table('absensi')->where('id_user', $this->userUuid(1))->get()->getRowArray();
    }

    private function nowWib(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}
