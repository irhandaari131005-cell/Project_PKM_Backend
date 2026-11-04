<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class RegistrationMySQLTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private ?BaseConnection $isolatedDatabase = null;

    private array $originalConnections = [];

    private bool $connectionsReplaced = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('TEST_MYSQL_REGISTRATION') !== '1') {
            $this->markTestSkipped('Set TEST_MYSQL_REGISTRATION=1 to test temporary copies of the MySQL schemas.');
        }

        $this->originalConnections = Database::getConnections();
        $configuration = config(Database::class)->default;
        $configuration['pConnect'] = false;
        $this->isolatedDatabase = Database::connect($configuration, false);
        $this->assertSame('MySQLi', $this->isolatedDatabase->DBDriver);

        // Hanya skema yang disalin ke tabel sementara dengan prefix terpisah.
        // Semua model dan validasi memakai koneksi/prefix fixture ini.
        $fixturePrefix = 'registration_' . bin2hex(random_bytes(4)) . '_';
        foreach (['users', 'siswa', 'guru', 'kelas'] as $table) {
            $physicalTable = $this->isolatedDatabase->escapeIdentifiers(
                $this->isolatedDatabase->prefixTable($table),
            );
            $fixtureTable = $this->isolatedDatabase->escapeIdentifiers($fixturePrefix . $table);
            $this->assertNotFalse($this->isolatedDatabase->query(
                'CREATE TEMPORARY TABLE ' . $fixtureTable . ' LIKE ' . $physicalTable,
            ));
        }
        $this->isolatedDatabase->setPrefix($fixturePrefix);

        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->connectionsReplaced = true;
        $this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1']);
    }

    protected function tearDown(): void
    {
        // Closing this nonpersistent connection also drops all temporary tables.
        if ($this->isolatedDatabase !== null) {
            $this->isolatedDatabase->close();
        }
        if ($this->connectionsReplaced) {
            $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        }

        parent::tearDown();
    }

    public function testRegistrationAndLoginWithTheRealMySQLSchemas(): void
    {
        $this->assertNotFalse($this->isolatedDatabase->table('users')->insert([
            'id_user' => '',
            'username' => 'mysql_legacy_blank',
            'nama' => 'Legacy Blank ID Fixture',
            'password' => password_hash('LegacyPassword123!', PASSWORD_DEFAULT),
            'sn' => 'MYSQL-LEGACY',
            'type' => 'siswa',
            'created_date' => '2026-01-01 00:00:00',
            'update_date' => '2026-01-01 00:00:00',
        ]));

        $identities = [];
        foreach (['siswa', 'guru'] as $type) {
            $input = [
                'nama' => 'MySQL ' . $type,
                'username' => 'mysql_registration_' . $type,
                'password' => 'RegistrationPassword123!',
                'password_confirmation' => 'RegistrationPassword123!',
                'sn' => 'MYSQL-REG-' . strtoupper($type),
                'type' => $type,
                'id_kelas' => '1',
                'id_user' => '00000000-0000-4000-8000-000000000099',
            ];
            $registration = $this->withSession([])
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode($input, JSON_THROW_ON_ERROR))
                ->post('/api/register');
            $registration->assertStatus(201);
            $registration->assertJSONExact([
                'status' => 201, 'message' => 'Registrasi berhasil', 'redirect' => '/login',
            ]);
            $this->assertNull(session()->get('auth_user'));

            $user = $this->isolatedDatabase->table('users')
                ->where('username', $input['username'])->get()->getRowArray();
            $this->assertNotNull($user);
            $this->assertMatchesRegularExpression(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $user['id_user'],
            );
            $this->assertNotSame($input['id_user'], $user['id_user']);
            $this->assertTrue(password_verify($input['password'], $user['password']));

            $profile = $this->isolatedDatabase->table($type)
                ->where('id_user', $user['id_user'])->get()->getRowArray();
            $this->assertNotNull($profile);
            $this->assertSame($user['id_user'], $profile['id_user']);
            $this->assertSame($input['nama'], $profile[$type === 'siswa' ? 'nama_siswa' : 'nama']);
            $this->assertSame($input['sn'], $profile['sn']);
            if ($type === 'siswa') {
                $this->assertSame('1', (string) $profile['id_kelas']);
            }

            $identities[$type] = [
                'id_user' => $user['id_user'], 'username' => $input['username'],
                'nama' => $input['nama'], 'type' => $type,
            ];
        }

        $this->assertNotSame($identities['siswa']['id_user'], $identities['guru']['id_user']);
        $this->assertSame(3, $this->isolatedDatabase->table('users')->countAllResults());
        $this->assertSame(1, $this->isolatedDatabase->table('siswa')->countAllResults());
        $this->assertSame(1, $this->isolatedDatabase->table('guru')->countAllResults());
        $this->assertSame('', $this->isolatedDatabase->table('users')
            ->where('username', 'mysql_legacy_blank')->get()->getRowArray()['id_user']);

        foreach ($identities as $identity) {
            $login = $this->withSession([])
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode([
                    'username' => $identity['username'], 'password' => 'RegistrationPassword123!',
                    'type' => $identity['type'],
                ], JSON_THROW_ON_ERROR))
                ->post('/api/login');
            $login->assertStatus(200);
            $login->assertJSONExact([
                'status' => 200, 'message' => 'Login berhasil',
                'redirect' => '/dashboard', 'data' => $identity,
            ]);

            $this->withSession()->withHeaders([])->withBody('')->get('/api/me')
                ->assertJSONExact([
                    'status' => 200, 'message' => 'User terautentikasi', 'data' => $identity,
                ]);

            $dashboard = $this->withSession()->withHeaders([])->withBody('')->get('/api/dashboard');
            $dashboard->assertStatus(200);
            $rows = json_decode($dashboard->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertCount($identity['type'] === 'siswa' ? 1 : 3, $rows);
            $this->assertContains($identity['id_user'], array_column($rows, 'id_user'));
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey('password', $row);
                $this->assertArrayNotHasKey('sn', $row);
            }

            $this->withSession()->withHeaders([])->withBody('')->get('/api/logout')
                ->assertJSONExact([
                    'status' => 200, 'message' => 'Logout berhasil', 'redirect' => '/login',
                ]);
            $this->withSession()->withHeaders([])->withBody('')->get('/api/me')->assertStatus(401);
        }
    }
}
