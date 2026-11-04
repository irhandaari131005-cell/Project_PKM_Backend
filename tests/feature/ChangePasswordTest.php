<?php

use App\Controllers\Auth;
use App\Models\M_forgot;
use App\Models\M_users;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\App;
use Config\Database;

final class ChangePasswordTest extends CIUnitTestCase
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
        $this->isolatedDatabase->table('users')->insertBatch([
            [
                'id_user' => 7,
                'username' => 'registered_user',
                'nama' => 'User Terdaftar',
                'password' => password_hash('OldPassword123!', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => '001234',
                'type' => 'siswa',
                'created_date' => '2026-01-01 00:00:00',
                'update_date' => '2026-01-01 00:00:00',
            ],
            [
                'id_user' => 42,
                'username' => 'another_user',
                'nama' => 'User Lain',
                'password' => password_hash('OtherPassword123!', PASSWORD_BCRYPT, ['cost' => 4]),
                'sn' => '005678',
                'type' => 'guru',
                'created_date' => '2026-01-01 00:00:00',
                'update_date' => '2026-01-01 00:00:00',
            ],
        ]);
        $this->originalUsers = $this->users();
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);

        parent::tearDown();
    }

    /** @dataProvider passwordRoutesAndFormats */
    public function testChangesOnlyTheVerifiedAccount(string $route, bool $json): void
    {
        $before = $this->nowWib();
        $input = $this->validInput() + ['id_user' => 42, 'type' => 'admin', 'update_date' => '2000-01-01 00:00:00'];
        $input['username'] = ' registered_user ';
        $response = $json
            ? $this->jsonPatch($route, json_encode($input, JSON_THROW_ON_ERROR))
            : $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->withBody(http_build_query($input))->patch($route);

        $response->assertStatus(200);
        $response->assertJSONExact(['status' => 200, 'message' => 'Password berhasil diubah.']);
        $users = $this->users();
        $this->assertCount(2, $users);
        $this->assertTrue(password_verify($input['new_password'], $users[0]['password']));
        $this->assertFalse(password_verify($input['password'], $users[0]['password']));
        $this->assertNotSame($input['new_password'], $users[0]['password']);
        $this->assertGreaterThanOrEqual($before, $users[0]['update_date']);
        $this->assertLessThanOrEqual($this->nowWib(), $users[0]['update_date']);
        unset($users[0]['password'], $users[0]['update_date']);
        $originalUser = $this->originalUsers[0];
        unset($originalUser['password'], $originalUser['update_date']);
        $this->assertSame($originalUser, $users[0]);
        $this->assertSame($this->originalUsers[1], $users[1]);
    }

    public static function passwordRoutesAndFormats(): array
    {
        return [
            'change JSON' => ['/api/change_password', true],
            'change form' => ['/api/change_password', false],
        ];
    }

    public function testAccountCreatedThroughRegisterCanChangePasswordAndLogin(): void
    {
        $this->post('/api/register', [
            'username' => 'new_registered_user',
            'nama' => 'User Baru',
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
            'sn' => '009999',
            'type' => 'siswa',
            'id_kelas' => '1',
        ])->assertStatus(201);

        $this->jsonPatch('/api/change_password', json_encode([
            'username' => 'new_registered_user',
            'password' => 'InitialPassword123!',
            'new_password' => 'ReplacementPassword123!',
        ], JSON_THROW_ON_ERROR))->assertStatus(200);

        // Clear JSON request state before testing the existing form-based login.
        $this->withHeaders([])->withBody('')->post('/api/login', [
            'username' => 'new_registered_user',
            'password' => 'ReplacementPassword123!',
            'type' => 'siswa',
        ])->assertJSONFragment(['status' => 200, 'message' => 'Login berhasil']);
        $this->withHeaders([])->withBody('')->post('/api/login', [
            'username' => 'new_registered_user',
            'password' => 'InitialPassword123!',
            'type' => 'siswa',
        ])->assertJSONExact(['status' => 400, 'message' => 'Username/password salah']);
    }

    public function testWrongOldPasswordCannotChangeAnyAccount(): void
    {
        $input = $this->validInput();
        $input['password'] = 'WrongPassword123!';

        $response = $this->jsonPatch('/api/change_password', json_encode($input, JSON_THROW_ON_ERROR));
        $response->assertStatus(401);
        $response->assertJSONFragment(['status' => 401]);
        $this->assertSame($this->originalUsers, $this->users());
    }

    /** @dataProvider invalidOldPasswordBytes */
    public function testOldPasswordCannotBypassVerificationWithTruncationOrNullBytes(string $password): void
    {
        $this->isolatedDatabase->table('users')->where('id_user', 7)->update([
            'password' => password_hash(str_repeat('A', 72), PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $before = $this->users();
        $input = array_replace($this->validInput(), ['password' => $password]);
        $this->jsonPatch('/api/change_password', json_encode($input, JSON_THROW_ON_ERROR))->assertStatus(400);
        $this->assertSame($before, $this->users());
    }

    public static function invalidOldPasswordBytes(): array
    {
        return [
            'bcrypt truncation' => [str_repeat('A', 72) . 'extra'],
            'null byte' => [str_repeat('A', 72) . "\0"],
            'embedded null byte' => ["OldPassword123!\0extra"],
        ];
    }

    public function testUnknownAccountIsNotCreated(): void
    {
        $input = $this->validInput();
        $input['username'] = 'not_registered';

        $response = $this->jsonPatch('/api/change_password', json_encode($input, JSON_THROW_ON_ERROR));
        $response->assertStatus(404);
        $response->assertJSONFragment(['status' => 404]);
        $this->assertSame($this->originalUsers, $this->users());
    }

    /** @dataProvider invalidInputs */
    public function testInvalidInputCannotChangeAnyAccount(array $input): void
    {
        $response = $this->jsonPatch('/api/change_password', json_encode($input, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $response->assertStatus(400);
        $response->assertJSONFragment(['status' => 400]);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public static function invalidInputs(): array
    {
        $valid = ['username' => 'registered_user', 'password' => 'OldPassword123!', 'new_password' => 'NewPassword123!'];

        return [
            'empty body' => [[]],
            'missing username' => [array_diff_key($valid, ['username' => true])],
            'missing ownership proof' => [array_diff_key($valid, ['password' => true])],
            'missing new password' => [array_diff_key($valid, ['new_password' => true])],
            'username array' => [array_replace($valid, ['username' => ['registered_user']])],
            'old password array' => [array_replace($valid, ['password' => ['OldPassword123!']])],
            'new password array' => [array_replace($valid, ['new_password' => ['NewPassword123!']])],
            'numeric username' => [array_replace($valid, ['username' => 123])],
            'numeric old password' => [array_replace($valid, ['password' => 123])],
            'numeric new password' => [array_replace($valid, ['new_password' => 12345678])],
            'blank username' => [array_replace($valid, ['username' => '   '])],
            'empty old password' => [array_replace($valid, ['password' => ''])],
            'null old password' => [array_replace($valid, ['password' => null])],
            'empty new password' => [array_replace($valid, ['new_password' => ''])],
            'short new password' => [array_replace($valid, ['new_password' => '1234567'])],
            'over bcrypt limit' => [array_replace($valid, ['new_password' => str_repeat('a', 73)])],
            'multibyte over byte limit' => [array_replace($valid, ['new_password' => str_repeat("\u{00E9}", 37)])],
            'null byte' => [array_replace($valid, ['new_password' => "Password\0value"])],
        ];
    }

    /** @dataProvider invalidJsonBodies */
    public function testMalformedOrNonObjectJsonCannotChangeAnyAccount(string $body): void
    {
        $this->jsonPatch('/api/change_password', $body)->assertStatus(400);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public static function invalidJsonBodies(): array
    {
        return [
            'malformed' => ['{"username":'],
            'empty' => [''],
            'list' => ['[]'],
            'null' => ['null'],
            'scalar' => ['"username"'],
        ];
    }

    public function testQueryParametersCannotSupplyCredentials(): void
    {
        $route = '/api/change_password?' . http_build_query($this->validInput());
        $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withBody('')->patch($route)->assertStatus(400);
        $this->assertSame($this->originalUsers, $this->users());
    }

    /** @dataProvider acceptedPasswords */
    public function testAcceptedPasswordLimitsAndSpacesArePreserved(string $newPassword): void
    {
        $input = array_replace($this->validInput(), ['new_password' => $newPassword]);
        $this->jsonPatch('/api/change_password', json_encode($input, JSON_THROW_ON_ERROR))->assertStatus(200);

        $this->assertTrue(password_verify($newPassword, $this->users()[0]['password']));
    }

    public static function acceptedPasswords(): array
    {
        return [
            'minimum' => ['abcdefgh'],
            'maximum bytes' => [str_repeat('a', 72)],
            'multibyte maximum bytes' => [str_repeat("\u{00E9}", 36)],
            'surrounding spaces' => ['  NewPassword123!  '],
        ];
    }

    public function testDatabaseFailureReturnsGenericErrorWithoutChangingPasswords(): void
    {
        $this->isolatedDatabase->query("CREATE TRIGGER reject_password_update
            BEFORE UPDATE OF password ON test_users
            BEGIN SELECT RAISE(ABORT, 'private database failure detail'); END");

        $response = $this->jsonPatch('/api/change_password', json_encode($this->validInput(), JSON_THROW_ON_ERROR));
        $response->assertStatus(500);
        $response->assertJSONExact([
            'status' => 500,
            'message' => 'Terjadi kesalahan saat mengubah password.',
        ]);
        $this->assertSame($this->originalUsers, $this->users());
    }

    public function testFalseUpdateResultIsNotReportedAsSuccess(): void
    {
        $model = $this->getMockBuilder(M_users::class)->onlyMethods(['update'])->getMock();
        $model->expects($this->once())->method('update')->with(7, $this->isType('array'))->willReturn(false);
        $request = new IncomingRequest(
            config(App::class),
            new URI('http://example.com/api/change_password'),
            json_encode($this->validInput(), JSON_THROW_ON_ERROR),
            new UserAgent(),
        );
        $request->setHeader('Content-Type', 'application/json');
        $controller = new Auth();
        $controller->initController($request, service('response'), service('logger'));
        $this->setPrivateProperty($controller, 'm_user', $model);

        $response = $controller->changePassword();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($this->originalUsers, $this->users());
    }

    public function testLegacyModelReadsRegisteredAccountsWithoutSeparateForgotTable(): void
    {
        $user = (new M_forgot())->find(7);

        $this->assertSame($this->originalUsers[0], $user);
    }

    private function validInput(): array
    {
        return ['username' => 'registered_user', 'password' => 'OldPassword123!', 'new_password' => 'NewPassword123!'];
    }

    private function users(): array
    {
        return $this->isolatedDatabase->table('users')->orderBy('CAST(id_user AS INTEGER)', 'ASC', false)->get()->getResultArray();
    }

    private function jsonPatch(string $route, string $body): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'application/json'])->withBody($body)->patch($route);
    }

    private function nowWib(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}
