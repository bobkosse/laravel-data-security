<?php

declare(strict_types=1);
use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Exceptions\PrivacyDecryptionException;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('test_customers');
    Schema::dropIfExists('users');

    Schema::create('test_customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('address');
        $table->string('internal_note'); // Non-private field
        $table->timestamps();
    });

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username');
        $table->string('email');
        $table->timestamps();
    });
});

#[Protect(fields: ['name', 'email', 'address'])]
class TestCustomer extends Model
{
    use HasPrivacy;

    protected $table = 'test_customers';

    protected $fillable = [
        'name', 'email', 'address', 'internal_note',
    ];
}

#[Protect(fields: ['username', 'email'])]
class User extends Model
{
    use HasPrivacy;

    protected $table = 'users';

    protected $fillable = [
        'username', 'email',
    ];
}

#[Protect(fields: ['email'])]
class NonModel
{
    use HasPrivacy;

    public function __construct(public string $email) {}
}

it('encrypts sensitive data in the database', closure: function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    $rawDbData = DB::table('test_customers')->where('id', $customer->id)->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect(strlen($rawDbData->name))->toBeGreaterThan(50);

    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect(strlen($rawDbData->email))->toBeGreaterThan(50);

    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect(strlen($rawDbData->address))->toBeGreaterThan(50);
});

it('returns encrypted placeholder by default', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    expect($customer->name)->toBe('[ENCRYPTED]');
    expect($customer->email)->toBe('[ENCRYPTED]');
    expect($customer->address)->toBe('[ENCRYPTED]');
    expect($customer->internal_note)->toBe('This is a secret note');
});

it('only shows real data when explicitly revealed', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    expect($customer->name)->toBe('[ENCRYPTED]');
    expect($customer->email)->toBe('[ENCRYPTED]');
    expect($customer->address)->toBe('[ENCRYPTED]');
    expect($customer->internal_note)->toBe('This is a secret note');

    $customer->revealPrivacy(true);

    expect($customer->name)->toBe('John Doe');
    expect($customer->email)->toBe('john@doe.com');
    expect($customer->address)->toBe('123 Road Avenue');
    expect($customer->internal_note)->toBe('This is a secret note');
});

it('shows encrypted data after set back to reveal false state', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    $customer->revealPrivacy(true);

    expect($customer->name)->toBe('John Doe');
    expect($customer->email)->toBe('john@doe.com');
    expect($customer->address)->toBe('123 Road Avenue');
    expect($customer->internal_note)->toBe('This is a secret note');

    $customer->revealPrivacy(false);

    expect($customer->name)->toBe('[ENCRYPTED]');
    expect($customer->email)->toBe('[ENCRYPTED]');
    expect($customer->address)->toBe('[ENCRYPTED]');
    expect($customer->internal_note)->toBe('This is a secret note');
});

it('should not be usable on the User model of Laravel', function () {
    $user = User::create([
        'username' => 'johndoe',
        'email' => 'john@doe.com',
    ]);

    expect($user->username)->toBe('johndoe');
    expect($user->email)->toBe('john@doe.com');

    $user->revealPrivacy(true);

    expect($user->username)->toBe('johndoe');
    expect($user->email)->toBe('john@doe.com');
});

it('should only run on Laravel models', function () {
    $nonModel = new NonModel('john@doe.com');
    expect($nonModel->email)->toBe('john@doe.com');
    $nonModel->revealPrivacy(true);
    expect($nonModel->email)->toBe('john@doe.com');
    $nonModel->revealPrivacy(false);
    expect($nonModel->email)->toBe('john@doe.com');
});

it('should log an alert if HasPrivacy is used on User model', function () {
    Log::shouldReceive('alert')
        ->atLeast()
        ->once()
        ->with(Mockery::on(function ($message) {
            return str_contains($message, 'Privacy is not active for this model');
        }));

    $model = new User;
    $model->getAttribute('any_key');
});

it('encrypts sensitive data when using fill and save', function () {
    $customer = new TestCustomer;
    $customer->fill([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);
    $customer->save();

    $rawDbData = DB::table('test_customers')->where('id', $customer->id)->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('encrypts sensitive data when using insert', function () {
    TestCustomer::insert([
        [
            'name' => 'John Doe',
            'email' => 'john@doe.com',
            'address' => '123 Road Avenue',
            'internal_note' => 'This is a secret note',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rawDbData = DB::table('test_customers')->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('encrypts sensitive data when using upsert', function () {
    TestCustomer::upsert([
        [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@doe.com',
            'address' => '123 Road Avenue',
            'internal_note' => 'This is a secret note',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ], ['id'], ['name', 'email', 'address', 'internal_note', 'updated_at']);

    $rawDbData = DB::table('test_customers')->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('throws a PrivacyDecryptionException when decryption fails', function () {
    Crypt::shouldReceive('decryptString')
        ->once()
        ->andThrow(new Exception('Decrypt failed'));

    $model = new TestCustomer;
    $model->setRawAttributes([
        'email' => 'encrypted-value',
    ]);
    $model->revealPrivacy(true);

    $model->getAttribute('email');
})->throws(PrivacyDecryptionException::class);

it('returns null for null privacy values without decrypting them', function () {
    $model = new TestCustomer;

    $model->setRawAttributes([
        'email' => null,
    ]);

    expect($model->getAttribute('email'))->toBeNull();

    $model->revealPrivacy(true);

    expect($model->getAttribute('email'))->toBeNull();
});
it('encrypts sensitive data when using insert via the model builder', function () {
    TestCustomer::insert([
        [
            'name' => 'John Doe',
            'email' => 'john@doe.com',
            'address' => '123 Road Avenue',
            'internal_note' => 'This is a secret note',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rawDbData = DB::table('test_customers')->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('encrypts sensitive data when using insertOrIgnore via the model builder', function () {
    TestCustomer::insertOrIgnore([
        [
            'name' => 'John Doe',
            'email' => 'john@doe.com',
            'address' => '123 Road Avenue',
            'internal_note' => 'This is a secret note',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rawDbData = DB::table('test_customers')->first();

    expect($rawDbData->name)->not->toBe('John Doe');
    expect($rawDbData->email)->not->toBe('john@doe.com');
    expect($rawDbData->address)->not->toBe('123 Road Avenue');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('encrypts sensitive data when using update via the model builder', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    TestCustomer::where('id', $customer->id)->update([
        'name' => 'Jane Doe',
        'email' => 'jane@doe.com',
        'address' => '456 Another Street',
    ]);

    $rawDbData = DB::table('test_customers')->where('id', $customer->id)->first();

    expect($rawDbData->name)->not->toBe('Jane Doe');
    expect($rawDbData->email)->not->toBe('jane@doe.com');
    expect($rawDbData->address)->not->toBe('456 Another Street');
    expect($rawDbData->internal_note)->toBe('This is a secret note');
});

it('encrypts data when updating ALL records without a where clause', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    TestCustomer::where('id', 1)->update(['email' => 'new@all.com']);

    $rawDbData = DB::table('test_customers')->pluck('email');

    $rawDbData->each(fn ($email) => expect($email)->not->toBe('new@all.com'));
});

it('does not double-encrypt already encrypted data', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    $encryptedEmail = DB::table('test_customers')
        ->where('id', 1)->value('email');

    TestCustomer::where('id', 1)
        ->update(['email' => $encryptedEmail]);

    $freshRawData = DB::table('test_customers')
        ->where('id', 1)->first();

    expect($freshRawData->email)->toBe($encryptedEmail);
});

it('leaves non-private fields as plaintext during bulk update', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    TestCustomer::where('id', 1)->update(['internal_note' => 'Updated the note']);

    $rawDbData = DB::table('test_customers')->where('id', $customer->id)->first();

    expect($rawDbData->internal_note)->toBe('Updated the note');
});

it('handles mixed private and public fields correctly in one update', function () {
    $customer = TestCustomer::create([
        'name' => 'John Doe',
        'email' => 'john@doe.com',
        'address' => '123 Road Avenue',
        'internal_note' => 'This is a secret note',
    ]);

    TestCustomer::where('id', 1)->update([
        'internal_note' => 'Not a secret note',
        'email' => 'b@b.com',
    ]);

    $rawDbData = DB::table('test_customers')->where('id', 1)->first();

    expect($rawDbData->internal_note)->toBe('Not a secret note');
    expect($rawDbData->email)->not->toBe('b@b.com');
});

it('keeps null privacy values as null without encrypting them', function () {
    $customer = new TestCustomer;

    $customer->setAttribute('email', '');

    expect($customer->getRawOriginal('email'))->toBeNull();
    expect($customer->getAttribute('email'))->toBe('[ENCRYPTED]');

    $customer->revealPrivacy(true);

    expect($customer->getAttribute('email'))->toBe('');
});
