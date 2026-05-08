<?php

declare(strict_types=1);

namespace Tests\Feature;

use BobKosse\DataSecurity\Commands\PrivacyAuditCommand;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tests\Feature\TmpEncryptModels\PrivateEmail;

it('scans the directory and finds models and outputs the result', function () {
    $modelsDir = __DIR__.'/../MockModels';

    $this->artisan('privacy:audit', [
        'scan' => $modelsDir,
    ])
        ->expectsTable(['Model', 'Has Privacy Trait', 'Privacy Fields'], [
            ['Tests\MockModels\ProtectedModel', 'Yes', 'email, phone'],
            ['Tests\MockModels\UnprotectedModel', 'No', '-'],
        ])
        ->assertExitCode(0);
});

it('should give a clear message if the path is not correct ', function () {
    $modelsDir = __DIR__.'/../NonModels';

    $this->artisan('privacy:audit', [
        'scan' => $modelsDir,
    ])
        ->expectsOutput('Scan directory not found: '.$modelsDir)
        ->assertExitCode(1);
});

it('skips files that are not php files', function () {
    $tempDir = __DIR__.'/tmp-scan';
    $content = '# not a php file';

    File::ensureDirectoryExists($tempDir);
    File::put($tempDir.'/README.md', $content);

    $this->artisan('privacy:audit', [
        'scan' => $tempDir,
    ])
        ->expectsOutput('No Eloquent models found in the selected scan directory.')
        ->assertExitCode(0);

    File::deleteDirectory($tempDir);
});

it('skips php files without a class', function () {
    $tempDir = __DIR__.'/tmp-scan';
    $content = "<?php\n\nnamespace Tests\\Tmp;\n";

    File::ensureDirectoryExists($tempDir);
    File::put($tempDir.'/NoClass.php', $content);

    $this->artisan('privacy:audit', [
        'scan' => $tempDir,
    ])
        ->expectsOutput('No Eloquent models found in the selected scan directory.')
        ->assertExitCode(0);

    File::deleteDirectory($tempDir);
});

it('skips php files with a class that is not autoloadable', function () {
    $tempDir = sys_get_temp_dir().'/privacy-audit-'.uniqid();
    $content = '<?php

namespace Tests\Tmp;

class GhostModel
{
}';

    File::ensureDirectoryExists($tempDir);
    File::put($tempDir.'/GhostModel.php', $content);

    $this->artisan('privacy:audit', [
        'scan' => $tempDir,
    ])
        ->expectsOutput('No Eloquent models found in the selected scan directory.')
        ->assertExitCode(0);

    File::deleteDirectory($tempDir);
});

it('skips php files with classes that are not eloquent models', function () {
    $tempDir = sys_get_temp_dir().'/privacy-audit-'.uniqid();
    File::ensureDirectoryExists($tempDir);

    $content = '<?php

namespace Tests\Tmp;

class PlainClass
{
}';
    $filePath = $tempDir.'/PlainClass.php';

    File::put($filePath, $content);

    require_once $filePath;

    $this->artisan('privacy:audit', [
        'scan' => $tempDir,
    ])
        ->expectsOutput('No Eloquent models found in the selected scan directory.')
        ->assertExitCode(0);

    File::deleteDirectory($tempDir);
});

it('resolves a relative scan path against the base path', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicResolveScanPath(string $scanOption): ?string
        {
            return $this->resolveScanPath($scanOption);
        }
    };

    $expected = rtrim(base_path('tests/MockModels'), DIRECTORY_SEPARATOR);

    expect($command->publicResolveScanPath('tests/MockModels'))->toBe($expected);
});

it('uses src as default scan path when no scan option is given', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicResolveScanPath(string $scanOption): ?string
        {
            return $this->resolveScanPath($scanOption);
        }
    };

    $expected = rtrim(base_path('src'), DIRECTORY_SEPARATOR);

    expect($command->publicResolveScanPath(''))->toBe($expected);
});

it('returns the pathname when getRealPath is not usable', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicResolveFilePath(object $file): ?string
        {
            return $this->resolveFilePath($file);
        }
    };

    $file = new class
    {
        public function getRealPath(): string|false
        {
            return false;
        }

        public function getPathname(): string
        {
            return '/tmp/example.php';
        }
    };

    expect($command->publicResolveFilePath($file))->toBe('/tmp/example.php');
});

it('returns the string value when getPathname is not usable', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicResolveFilePath(object $file): ?string
        {
            return $this->resolveFilePath($file);
        }
    };

    $file = new class
    {
        public function getRealPath(): string|false
        {
            return false;
        }

        public function getPathname(): string|false
        {
            return false;
        }

        public function __toString(): string
        {
            return '/tmp/fallback.php';
        }
    };

    expect($command->publicResolveFilePath($file))->toBe('/tmp/fallback.php');
});

it('returns null when no file path can be resolved', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicResolveFilePath(object $file): ?string
        {
            return $this->resolveFilePath($file);
        }
    };

    $file = new class {};

    expect($command->publicResolveFilePath($file))->toBeNull();
});

it('returns null when the file cannot be read', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicGetClassNameFromFile(string $filePath): ?string
        {
            return $this->getClassNameFromFile($filePath);
        }
    };

    $missingFile = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.php';

    $previousHandler = set_error_handler(function (): bool {
        return true;
    });

    try {
        expect($command->publicGetClassNameFromFile($missingFile))->toBeNull();
    } finally {
        restore_error_handler();
    }
});

it('parses a namespace with namespace separators', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicParseNamespace(array $tokens, int $startIndex): string
        {
            return $this->parseNamespace($tokens, $startIndex);
        }
    };

    $tokens = [
        [T_NAMESPACE, 'namespace'],
        [T_STRING, 'Tests'],
        '\\',
        [T_STRING, 'Tmp'],
        ';',
    ];

    expect($command->publicParseNamespace($tokens, 1))->toBe('Tests\\Tmp');
});

it('returns null when no class name token is found', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicParseClassName(array $tokens, int $startIndex): ?string
        {
            return $this->parseClassName($tokens, $startIndex);
        }
    };

    $tokens = [
        [T_WHITESPACE, ' '],
        ';',
        '{',
        [T_WHITESPACE, ' '],
    ];

    expect($command->publicParseClassName($tokens, 0))->toBeNull();
});

it('runs on the default app/Models folder when no scan directory is specified', function () {
    $this->artisan('privacy:audit', [])
        ->expectsOutput('Use standard Laravel models folder: app/Models')
        ->assertExitCode(0);
});

it('returns empty privacy fields when model has no Protect attribute', function () {
    $model = new PrivateEmail;

    expect($model->getPrivacyFields())->toBe([]);
});

it('returns empty privacy fields when model has HasPrivacy trait but no Protect attribute', function () {
    $command = new class extends PrivacyAuditCommand
    {
        public function publicGetPrivacyFieldsFromReflection(\ReflectionClass $reflection): array
        {
            return $this->getPrivacyFieldsFromReflection($reflection);
        }
    };

    $testModel = new class extends Model
    {
        use HasPrivacy;

        protected $table = 'test_table';
    };

    $reflection = new \ReflectionClass($testModel);
    $result = $command->publicGetPrivacyFieldsFromReflection($reflection);

    expect($result)->toBe([]);
});
