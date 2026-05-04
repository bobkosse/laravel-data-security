<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Commands;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

class PrivacyAuditCommand extends Command
{
    protected $signature = 'privacy:audit {scan?}';

    protected $description = 'Overview of all Eloquent models and their privacy settings';

    public function handle(): int
    {
        $scan = $this->argument('scan');

        if (empty($scan)) {
            $this->error('Scan directory not specified. Use:');
            $this->error('php artisan privacy:audit app/Models');
            $this->error('to scan the app/Models directory.');

            return self::FAILURE;
        }

        $scanPath = $this->resolveScanPath((string) $scan);

        if (! File::isDirectory($scanPath)) {
            $this->error("Scan directory not found: {$scanPath}");

            return self::FAILURE;
        }

        $files = File::allFiles($scanPath);
        $rows = [];

        foreach ($files as $file) {
            $filePath = $this->resolveFilePath($file);

            $relativePath = str_replace('\\', '/', $this->resolveRelativePath($scanPath, $filePath));

            if (pathinfo($relativePath, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $className = $this->getClassNameFromFile($filePath);

            if ($className === null) {
                continue;
            }

            if (! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            $usesTrait = in_array(HasPrivacy::class, $reflection->getTraitNames(), true);

            $fields = '-';

            if ($usesTrait) {
                $fields = implode(', ', $this->getPrivacyFieldsFromReflection($reflection));
            }

            $rows[] = [
                'Model' => $reflection->getName(),
                'Has Privacy Trait' => $usesTrait ? '<fg=green>Yes</>' : '<fg=red>No</>',
                'Privacy Fields' => $fields,
            ];
        }

        if ($rows === []) {
            $this->info('No Eloquent models found in the selected scan directory.');

            return self::SUCCESS;
        }

        $this->table(['Model', 'Has Privacy Trait', 'Privacy Fields'], $rows);

        return self::SUCCESS;
    }

    /**
     * Get privacy fields from class reflection using the Protect attribute
     */
    protected function getPrivacyFieldsFromReflection(ReflectionClass $reflection): array
    {
        $attributes = $reflection->getAttributes(Protect::class);

        if (empty($attributes)) {
            return [];
        }

        return $attributes[0]->newInstance()->fields;
    }

    protected function resolveScanPath(string $scanOption): ?string
    {
        if ($scanOption !== '') {
            if ($this->isAbsolutePath($scanOption)) {
                return rtrim($scanOption, DIRECTORY_SEPARATOR);
            }

            return rtrim(base_path($scanOption), DIRECTORY_SEPARATOR);
        }

        return rtrim(base_path('src'), DIRECTORY_SEPARATOR);
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    protected function resolveFilePath(object $file): ?string
    {
        if (method_exists($file, 'getRealPath')) {
            $path = $file->getRealPath();

            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        if (method_exists($file, 'getPathname')) {
            $path = $file->getPathname();

            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        if (method_exists($file, '__toString')) {
            $path = (string) $file;

            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    protected function resolveRelativePath(string $basePath, string $filePath): string
    {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $filePath = str_replace('\\', '/', $filePath);

        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }

        return basename($filePath);
    }

    protected function getClassNameFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $class = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = $this->parseNamespace($tokens, $i + 1);
            }

            if ($tokens[$i][0] === T_CLASS) {
                $class = $this->parseClassName($tokens, $i + 1);

                if ($class !== null) {
                    break;
                }
            }
        }

        if ($class === null) {
            return null;
        }

        return $namespace !== '' ? $namespace.'\\'.$class : $class;
    }

    protected function parseNamespace(array $tokens, int $startIndex): string
    {
        $namespace = '';

        for ($i = $startIndex; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace .= $tokens[$i][1];
            } elseif ($tokens[$i] === '\\') {
                $namespace .= '\\';
            } elseif ($tokens[$i] === ';' || $tokens[$i] === '{') {
                break;
            }
        }

        return trim($namespace, '\\');
    }

    protected function parseClassName(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                return $tokens[$i][1];
            }
        }

        return null;
    }
}
