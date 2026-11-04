<?php

use App\Database\Migrations\CreateAbsensi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-10-15-000001_CreateAbsensi.php';

final class RiwayatAbsensiTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;
    private array $originalConnections = [];
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'history_', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE history_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT)');
        $this->isolatedDatabase->query('CREATE TABLE history_siswa (
            id_siswa INTEGER PRIMARY KEY, nama_siswa TEXT, id_kelas INTEGER, sn TEXT, id_user TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE history_users (
            id_user VARCHAR(36) PRIMARY KEY, username TEXT, nama TEXT, type TEXT
        )');
        (new CreateAbsensi(Database::forge('tests')))->up();
        foreach ([1, 2, 3, 4] as $studentId) {
            $this->isolatedDatabase->table('users')->insert(['id_user' => $this->userUuid($studentId)]);
        }
        $this->isolatedDatabase->table('kelas')->insertBatch([
            ['id_kelas' => 1, 'nama_kelas' => 'X RPL 1'],
            ['id_kelas' => 2, 'nama_kelas' => 'XI TKJ 1'],
        ]);
        $this->isolatedDatabase->table('siswa')->insertBatch([
            ['id_siswa' => 1, 'nama_siswa' => 'Akbar', 'id_kelas' => 1, 'sn' => 'SECRET_1', 'id_user' => $this->userUuid(1)],
            ['id_siswa' => 2, 'nama_siswa' => 'Budi', 'id_kelas' => 2, 'sn' => 'SECRET_2', 'id_user' => $this->userUuid(2)],
            ['id_siswa' => 3, 'nama_siswa' => 'Citra', 'id_kelas' => 1, 'sn' => 'SECRET_3', 'id_user' => $this->userUuid(3)],
            ['id_siswa' => 4, 'nama_siswa' => 'Dewi', 'id_kelas' => null, 'sn' => 'SECRET_4', 'id_user' => $this->userUuid(4)],
        ]);
        foreach ([1, 2, 4] as $studentId) {
            $this->addAttendance($studentId, $this->today);
        }
        $this->addAttendance(3, '2000-01-01');
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

    public function testDefaultDateListsOnlyStudentsWithAttendanceAndIncludesTheirClass(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:5174'])->get('/api/absensi/riwayat');
        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(3, $body['data']);
        $this->assertSame(3, $body['meta']['total']);
        $byStudent = array_column($body['data'], null, 'id_siswa');
        $this->assertArrayNotHasKey(3, $byStudent);
        $this->assertSame('Akbar', $byStudent[1]['nama_siswa']);
        $this->assertSame('X RPL 1', $byStudent[1]['nama_kelas']);
        $this->assertSame('XI TKJ 1', $byStudent[2]['nama_kelas']);
        $this->assertNull($byStudent[4]['nama_kelas']);
        foreach ($body['data'] as $row) {
            $this->assertSame($this->today, $row['tanggal']);
            $this->assertSame('hadir', $row['status_kehadiran']);
            foreach (['password', 'sn', 'id_user'] as $privateField) {
                $this->assertArrayNotHasKey($privateField, $row);
            }
        }
        $this->assertSame('http://localhost:5174', $response->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->response()->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertSame(4, $this->isolatedDatabase->table('absensi')->countAllResults());
    }

    public function testClassAndDateFiltersReturnOnlyMatchingAttendance(): void
    {
        $response = $this->get('/api/absensi/riwayat?id_kelas=1&tanggal=' . $this->today);
        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Akbar'], array_column($body['data'], 'nama_siswa'));
        $this->assertSame(['X RPL 1'], array_column($body['data'], 'nama_kelas'));
        $this->assertSame(1, $body['meta']['total']);

        $past = $this->get('/api/absensi/riwayat?id_kelas=1&tanggal=2000-01-01');
        $past->assertStatus(200);
        $this->assertSame(['Citra'], array_column(json_decode($past->getJSON(), true)['data'], 'nama_siswa'));
        $empty = $this->get('/api/absensi/riwayat?id_kelas=2&tanggal=2000-01-01');
        $empty->assertStatus(200);
        $empty->assertJSONFragment(['data' => [], 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0, 'from' => null, 'to' => null,
        ]]);
    }

    public function testPaginationDoesNotRepeatStudentsOrLoseFilters(): void
    {
        for ($studentId = 5; $studentId <= 30; $studentId++) {
            $this->isolatedDatabase->table('users')->insert(['id_user' => $this->userUuid($studentId)]);
            $this->isolatedDatabase->table('siswa')->insert([
                'id_user' => $this->userUuid($studentId), 'sn' => 'SECRET_' . $studentId,
                'id_siswa' => $studentId, 'nama_siswa' => sprintf('Siswa %02d', $studentId), 'id_kelas' => 1,
            ]);
            $this->addAttendance($studentId, $this->today);
        }
        $pages = [];
        foreach ([1, 2] as $page) {
            $response = $this->get('/api/absensi/riwayat?id_kelas=1&tanggal=' . $this->today . '&page=' . $page);
            $response->assertStatus(200);
            $body = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(27, $body['meta']['total']);
            $this->assertSame(2, $body['meta']['last_page']);
            $this->assertSame($page, $body['meta']['current_page']);
            $this->assertSame(($page - 1) * 25 + 1, $body['meta']['from']);
            $this->assertSame([1], array_values(array_unique(array_column($body['data'], 'id_kelas'))));
            $pages[$page] = array_column($body['data'], 'id_absensi');
        }
        $this->assertCount(25, $pages[1]);
        $this->assertCount(2, $pages[2]);
        $this->assertSame([], array_values(array_intersect($pages[1], $pages[2])));
        $outside = $this->get('/api/absensi/riwayat?id_kelas=1&page=99');
        $outside->assertStatus(200);
        $this->assertSame([], json_decode($outside->getJSON(), true)['data']);
    }

    /** @dataProvider invalidFilters */
    public function testInvalidFiltersHaveFieldErrors(string $query, string $field): void
    {
        $response = $this->get('/api/absensi/riwayat?' . $query);
        $response->assertStatus(422);
        $this->assertArrayHasKey($field, json_decode($response->getJSON(), true)['errors']);
    }

    public static function invalidFilters(): array
    {
        return [
            'empty class' => ['id_kelas=', 'id_kelas'], 'zero class' => ['id_kelas=0', 'id_kelas'],
            'unknown class' => ['id_kelas=999', 'id_kelas'], 'array class' => ['id_kelas[]=1', 'id_kelas'],
            'negative class' => ['id_kelas=-1', 'id_kelas'], 'injection class' => ['id_kelas=1%20OR%201=1', 'id_kelas'],
            'impossible date' => ['tanggal=2026-02-30', 'tanggal'], 'empty date' => ['tanggal=', 'tanggal'],
            'array date' => ['tanggal[]=2026-10-21', 'tanggal'], 'wrong format' => ['tanggal=21-10-2026', 'tanggal'],
            'zero page' => ['page=0', 'page'], 'array page' => ['page[]=1', 'page'],
            'fraction page' => ['page=1.5', 'page'], 'overflow page' => ['page=2147483648', 'page'],
        ];
    }

    public function testDatabaseFailureReturnsGenericJsonError(): void
    {
        $this->isolatedDatabase->query('DROP TABLE history_absensi');
        $response = $this->get('/api/absensi/riwayat');
        $response->assertStatus(500);
        $response->assertJSONExact([
            'status' => 500, 'message' => 'Terjadi kesalahan saat mengambil riwayat absensi.',
        ]);
    }

    private function userUuid(int $studentId): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $studentId);
    }

    private function addAttendance(int $studentId, string $date): void
    {
        $this->isolatedDatabase->table('absensi')->insert([
            'id_absensi' => sprintf('10000000-0000-4000-8000-%012d', $studentId),
            'id_user' => $this->userUuid($studentId), 'sn' => 'SECRET_' . $studentId,
            'tanggal' => $date, 'check_in' => $date . ' 07:00:00',
            'check_out' => null, 'created_date' => $date . ' 07:00:00', 'update_date' => $date . ' 07:00:00',
        ]);
    }
}
