<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use CodeIgniter\Throttle\Throttler;
use Config\Database;
use Config\Services;

final class ForgotPasswordTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $isolatedDatabase;

    /** @var array<string, BaseConnection> */
    private array $originalConnections = [];

    private array $originalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => 'test_',
            'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $this->isolatedDatabase->query('CREATE TABLE test_kelas (id_kelas INTEGER PRIMARY KEY, nama_kelas TEXT)');
        $this->isolatedDatabase->table('kelas')->insert(['id_kelas' => 1, 'nama_kelas' => 'X RPL 1']);
        $this->isolatedDatabase->query('CREATE TABLE test_users (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, username TEXT UNIQUE,
            nama TEXT, password TEXT, sn TEXT, type TEXT,
            created_date TEXT, update_date TEXT
        )');
        $this->isolatedDatabase->query('CREATE TABLE test_siswa (
            id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36), nama_siswa TEXT NOT NULL,
            status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT, id_kelas INTEGER
        )');
        $this->isolatedDatabase->query('CREATE TABLE test_guru (
            id_user VARCHAR(36) PRIMARY KEY NOT NULL, nama TEXT NOT NULL,
            sn TEXT, type TEXT, status TEXT NOT NULL, created_date TEXT, update_date TEXT
        )');
        foreach ([7 => ['registered_user', '001234'], 42 => ['another_user', '005678']] as $id => [$username, $sn]) {
            $this->isolatedDatabase->table('users')->insert([
                'id_user' => $id,
                'username' => $username,
                'nama' => 'User ' . $id,
                'password' => password_hash('OldPassword123!', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => $sn,
                'type' => 'siswa',
                'created_date' => '2026-01-01 00:00:00',
                'update_date' => '2026-01-01 00:00:00',
            ]);
        }
        $this->originalUsers = $this->users();
        Services::injectMock('throttler', (new Throttler(service('cache')))->setTestTime(time()));
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    /** @dataProvider bodyFormats */
    public function testResetsOnlyMatchingRegisteredAccountWithoutOldPassword(bool $json): void
    {
        $before = $this->nowWib();
        $input = $this->validInput() + ['id_user' => 42, 'type' => 'admin', 'update_date' => '2000-01-01 00:00:00'];
        $input['username'] = ' registered_user ';
        $input['sn'] = ' 001234 ';
        $response = $json
            ? $this->jsonPatch(json_encode($input, JSON_THROW_ON_ERROR))
            : $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->withBody(http_build_query($input))->patch('/api/forgot_password');

        $response->assertStatus(200);
        $response->assertJSONExact(['status' => 200, 'message' => 'Password berhasil diubah.']);
        $users = $this->users();
        $this->assertCount(2, $users);
        $this->assertTrue(password_verify($input['new_password'], $users[0]['password']));
        $this->assertFalse(password_verify('OldPassword123!', $users[0]['password']));
        $this->assertNotSame($input['new_password'], $users[0]['password']);
        $this->assertGreaterThanOrEqual($before, $users[0]['update_date']);
        $this->assertLessThanOrEqual($this->nowWib(), $users[0]['update_date']);
        $expected = array_replace($this->originalUsers[0], [
            'password' => $users[0]['password'],
            'update_date' => $users[0]['update_date'],
        ]);
        $this->assertSame($expected, $users[0]);
        $this->assertSame($this->originalUsers[1], $users[1]);
    }

    public static function bodyFormats(): array
    {
        return ['JSON' => [true], 'urlencoded PATCH' => [false]];
    }

    public function testRegistrationSnCanResetPasswordAndNewPasswordCanLogin(): void
    {
        $this->post('/api/register', [
            'username' => 'new_registered_user',
            'nama' => 'User Baru',
            'password' => 'ForgottenPassword123!',
            'password_confirmation' => 'ForgottenPassword123!',
            'sn' => '00009999',
            'type' => 'siswa',
            'id_kelas' => '1',
        ])->assertStatus(201);

        $this->jsonPatch(json_encode([
            'username' => 'new_registered_user',
            'sn' => '00009999',
            'new_password' => 'ReplacementPassword123!',
        ], JSON_THROW_ON_ERROR))->assertStatus(200);

        $this->withHeaders([])->withBody('')->post('/api/login', [
            'username' => 'new_registered_user',
            'password' => 'ReplacementPassword123!',
            'type' => 'siswa',
        ])->assertJSONFragment(['status' => 200, 'message' => 'Login berhasil']);
        $this->withHeaders([])->withBody('')->post('/api/login', [
            'username' => 'new_registered_user',
            'password' => 'ForgottenPassword123!',
            'type' => 'siswa',
        ])->assertJSONExact(['status' => 400, 'message' => 'Username/password salah']);
    }

    /** @dataProvider mismatchedCredentials */
    public function testWrongAccountOrSnCannotResetAnyPassword(string $username, string $sn): void
    {
        $input = array_replace($this->validInput(), ['username' => $username, 'sn' => $sn]);
        $response = $this->jsonPatch(json_encode($input, JSON_THROW_ON_ERROR));

        $response->assertStatus(401);
        $response->assertJSONExact(['status' => 401, 'message' => 'Username atau SN tidak sesuai.']);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public static function mismatchedCredentials(): array
    {
        return [
            'wrong SN' => ['registered_user', '999999'],
            'another account SN' => ['registered_user', '005678'],
            'another username' => ['another_user', '001234'],
            'unregistered username' => ['not_registered', '001234'],
            'lost leading zeros' => ['registered_user', '1234'],
        ];
    }

    /** @dataProvider invalidInputs */
    public function testInvalidInputsCannotResetAnyPassword(array $input): void
    {
        $response = $this->jsonPatch(json_encode($input, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $response->assertStatus(400);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public static function invalidInputs(): array
    {
        $valid = ['username' => 'registered_user', 'sn' => '001234', 'new_password' => 'NewPassword123!'];

        return [
            'empty object' => [[]],
            'missing username' => [array_diff_key($valid, ['username' => true])],
            'missing SN' => [array_diff_key($valid, ['sn' => true])],
            'old password without SN' => [array_diff_key($valid, ['sn' => true]) + ['password' => 'OldPassword123!']],
            'missing new password' => [array_diff_key($valid, ['new_password' => true])],
            'username array' => [array_replace($valid, ['username' => ['registered_user']])],
            'username whitespace' => [array_replace($valid, ['username' => '   '])],
            'username too long' => [array_replace($valid, ['username' => str_repeat('a', 256)])],
            'SN array' => [array_replace($valid, ['sn' => ['001234']])],
            'SN numeric' => [array_replace($valid, ['sn' => 1234])],
            'SN null' => [array_replace($valid, ['sn' => null])],
            'SN empty' => [array_replace($valid, ['sn' => ''])],
            'SN whitespace' => [array_replace($valid, ['sn' => '   '])],
            'SN too long' => [array_replace($valid, ['sn' => str_repeat('a', 101)])],
            'SN control characters' => [array_replace($valid, ['sn' => "001\n234"])],
            'new password array' => [array_replace($valid, ['new_password' => ['NewPassword123!']])],
            'new password numeric' => [array_replace($valid, ['new_password' => 12345678])],
            'new password too short' => [array_replace($valid, ['new_password' => '1234567'])],
            'new password too long' => [array_replace($valid, ['new_password' => str_repeat('a', 73)])],
            'new password null byte' => [array_replace($valid, ['new_password' => "Password\0value"])],
        ];
    }

    /** @dataProvider malformedBodies */
    public function testMalformedJsonCannotResetAnyPassword(string $body): void
    {
        $this->jsonPatch($body)->assertStatus(400);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public static function malformedBodies(): array
    {
        return ['malformed' => ['{"sn":'], 'list' => ['[]'], 'null' => ['null']];
    }

    public function testAccountWithoutRegisteredSnCannotBeReset(): void
    {
        $this->isolatedDatabase->table('users')->where('id_user', 7)->update(['sn' => null]);
        $before = $this->users();
        $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR))->assertStatus(401);
        $this->assertSame($before, $this->users());
    }

    public function testSameSnOnAnotherAccountDoesNotChangeUsernameScope(): void
    {
        $this->isolatedDatabase->table('users')->where('id_user', 42)->update(['sn' => '001234']);
        $otherUser = $this->users()[1];
        $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR))->assertStatus(200);

        $this->assertTrue(password_verify('NewPassword123!', $this->users()[0]['password']));
        $this->assertSame($otherUser, $this->users()[1]);
    }

    public function testCredentialsFromQueryStringAreNotAccepted(): void
    {
        $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])->withBody('')
            ->patch('/api/forgot_password?' . http_build_query($this->validInput()))->assertStatus(400);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public function testTooManyAccountAttemptsAreLimitedAndCanRetryLater(): void
    {
        $input = array_replace($this->validInput(), ['sn' => 'wrong-sn']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->jsonPatch(json_encode($input, JSON_THROW_ON_ERROR))->assertStatus(401);
        }

        $response = $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR));
        $response->assertStatus(429);
        $this->assertSame('60', $response->response()->getHeaderLine('Retry-After'));
        $this->assertSame($this->originalUsers, $this->users());

        $throttler = service('throttler');
        $throttler->setTestTime($throttler->time() + 60);
        $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR))->assertStatus(200);
    }

    public function testIpLimitPreventsUnlimitedAttemptsAcrossAccounts(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $input = array_replace($this->validInput(), ['username' => 'unknown_user_' . $attempt]);
            $this->jsonPatch(json_encode($input, JSON_THROW_ON_ERROR))->assertStatus(401);
        }

        $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR))->assertStatus(429);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public function testDatabaseFailureIsNotReportedAsSuccessfulReset(): void
    {
        $this->isolatedDatabase->query("CREATE TRIGGER reject_password_reset
            BEFORE UPDATE OF password ON test_users
            BEGIN SELECT RAISE(ABORT, 'private database failure detail'); END");

        $response = $this->jsonPatch(json_encode($this->validInput(), JSON_THROW_ON_ERROR));
        $response->assertStatus(500);
        $response->assertJSONExact(['status' => 500, 'message' => 'Terjadi kesalahan saat mengubah password.']);
        $this->assertSame($this->originalUsers, $this->users());
    }

    private function validInput(): array
    {
        return ['username' => 'registered_user', 'sn' => '001234', 'new_password' => 'NewPassword123!'];
    }

    private function users(): array
    {
        return $this->isolatedDatabase->table('users')->orderBy('CAST(id_user AS INTEGER)', 'ASC', false)->get()->getResultArray();
    }

    private function jsonPatch(string $body): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])->withBody($body)->patch('/api/forgot_password');
    }

    private function nowWib(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}
