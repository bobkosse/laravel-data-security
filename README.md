# Laravel Data Security (Privacy Trait & Audit)
This package provides a lightweight solution for handling sensitive data within Laravel Eloquent models. It automatically encrypts specific fields, decrypts them only when explicitly requested, and includes an audit tool to inspect privacy coverage across your application.

## Features
- **Automatic field encryption:** Encrypts sensitive model attributes before they are stored in the database.
- **Automatic field decryption:** Decrypts privacy fields only when privacy is explicitly revealed.
- **Safe default masking:** Privacy fields return `[ENCRYPTED]` by default to prevent accidental data exposure.
- **Bulk write support:** Works with model-based bulk operations such as `insert()`, `insertOrIgnore()`, `upsert()`, and `where()->update()`.
- **Duplicate encryption protection:** Prevents already encrypted values from being encrypted again.
- **Clear decryption errors:** Throws a `PrivacyDecryptionException` when encrypted values cannot be decrypted.
- **Privacy audit command:** Scans your models and reports which ones use the privacy trait and which privacy fields they define.
- **Interactive encryption command:** Encrypt existing data for newly added privacy fields.
- **Attribute-based configuration:** Use PHP 8 attributes for cleaner model definitions.

## Installation
1. Add the package to your project.
2. Register the `PrivacyAuditCommand` in your console kernel if it is not auto-discovered.

## Usage
### 1. Prepare your model
Add the `HasPrivacy` trait to any Eloquent model containing sensitive data. Define the fields that should be protected using the `#[Protect]` attribute.

```php
use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;

#[Protect(fields: ['phone_number', 'address', 'social_security_number'])]
class PatientProfile extends Model
{
  use HasPrivacy;
}
```

### 2. How it works

#### Saving data
When a value is assigned to a field listed in the `#[Protect]` attribute, the trait automatically encrypts it before it reaches the database.

This works with:

- `fill()`
- `create()`
- `save()`
- `update()`
- `forceFill()`

It also supports bulk model operations:

- `insert()`
- `insertOrIgnore()`
- `upsert()`
- `where()->update()`

#### Revealing data
To access the decrypted value, explicitly reveal privacy first.

#### Reading data
By default, privacy fields return `[ENCRYPTED]` when accessed.

```php
use BobKosse\DataSecurity\Exceptions\PrivacyDecryptionException;

$profile = PatientProfile::find(1);

// Returns "[ENCRYPTED]"
echo $profile->phone_number;

// Returns the decrypted value (e.g., "+31 6 12345678")
$profile->revealPrivacy(true);
echo $profile->phone_number;
```

This can also be used in combination with authorization policies to ensure sensitive data is only accessible by 
authorized users.

### 3. ```HasPrivacy``` works on all Laravel Eloquent models
The trait is designed for Eloquent models and does not affect raw database queries.

Supported Eloquent-based writes include:

- `fill()`
- `create()`
- `save()`
- `update()`
- `forceFill()`
- `insert()`
- `insertOrIgnore()`
- `upsert()`
- `where()->update()`

## Commands

### Privacy Audit Command
The package includes a console command that scans a directory for Eloquent models and reports which ones use the privacy trait.

```bash
php artisan privacy:audit app/Models
```

The command shows:
- **Model:** the full class name of the model
- **Has Privacy Trait:** whether the trait is implemented
- **Privacy Fields:** the fields currently configured for encryption

### Privacy Encrypt Field Command
When you add privacy protection to an existing field that already contains plain-text data, you can use this command to encrypt it.

```bash
php artisan privacy:encrypt-field
```

This interactive command will:
1. Ask you to select a model.
2. Ask you to select a field from that model.
3. Automatically update the model file to add the `#[Protect]` attribute and `HasPrivacy` trait if they are missing.
4. Encrypt all existing plain-text values for that field in the database.

## Important notes

- **User model safety:** The trait intentionally avoids running on the default `User` model to prevent accidental locking out of authentication data.
- **Database column size:** Ensure privacy columns can store encrypted strings, typically using `TEXT` or `BLOB`.
- **Raw SQL is out of scope:** Direct `DB::table()` or raw SQL statements bypass the trait.
