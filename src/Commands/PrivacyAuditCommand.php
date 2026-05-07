<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Commands;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

class PrivacyAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'privacy:audit {scan?}';

    /**
     * @var string
     */
    protected $description = 'Overview of all Eloquent models and their privacy fields. Use privacy:audit [custom models directory] or just privacy:audit to scan the app/Models directory.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scan = $this->argument('scan');

        if (empty($scan)) {
            $this->info('Use standard Laravel models folder: app/Models');
            $scan = 'app/Models';
        }

        return $this->handleAction($scan);
    }

    /**
     * Handle the action based on the scan option.
     *
     * @param  string  $scan  The scan option.
     * @return int The exit code.
     */
    private function handleAction(string $scan): int
    {
        $scanPath = $this->resolveScanPath((string) $scan);

        if (! File::isDirectory($scanPath)) {
            $this->error("Scan directory not found: {$scanPath}");

            return self::FAILURE;
        }

        $files = File::allFiles($scanPath);
        $rows = [];

        foreach ($files as $file) {
            $data = $this->handleFiles($file, $scanPath);
            if (count($data) > 0) {
                $rows[] = $data;
            }
        }

        if ($rows === []) {
            $this->info('No Eloquent models found in the selected scan directory.');

            return self::SUCCESS;
        }

        $this->table(['Model', 'Has Privacy Trait', 'Privacy Fields'], $rows);

        return self::SUCCESS;
    }

    /**
     * Handle files and extract relevant information.
     *
     * @param  SplFileInfo  $file  The file to handle.
     * @param  string  $scanPath  The scan directory path.
     * @return array The extracted information for the file.
     */
    private function handleFiles(SplFileInfo $file, string $scanPath): array
    {
        $filePath = $this->resolveFilePath($file);

        $relativePath = str_replace('\\', '/', $this->resolveRelativePath($scanPath, $filePath));

        if (pathinfo($relativePath, PATHINFO_EXTENSION) !== 'php') {
            return [];
        }

        $className = $this->getClassNameFromFile($filePath);

        if ($className === null || ! class_exists($className) || ! is_subclass_of($className, Model::class)) {
            return [];
        }

        $reflection = new ReflectionClass($className);
        $usesTrait = in_array(HasPrivacy::class, $reflection->getTraitNames(), true);

        $fields = '-';

        if ($usesTrait) {
            $fields = implode(', ', $this->getPrivacyFieldsFromReflection($reflection));
        }

        return [
            'Model' => $reflection->getName(),
            'Has Privacy Trait' => $usesTrait ? '<fg=green>Yes</>' : '<fg=red>No</>',
            'Privacy Fields' => $fields,
        ];
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

    /**
     * Resolve the scan path based on the provided option.
     *
     * @param  string  $scanOption  The scan option.
     * @return string|null The resolved scan path or null if invalid.
     */
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

    /**
     * Check if the provided path is an absolute path.
     *
     * @param  string  $path  The path to check.
     * @return bool True if the path is absolute, false otherwise.
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    /**
     * Resolve the file path.
     *
     * @param  object  $file  The file object to resolve the path for.
     * @return string|null The resolved file path or null if not found.
     */
    protected function resolveFilePath(object $file): ?string
    {
        return match (true) {
            method_exists($file, 'getRealPath') && ($path = $file->getRealPath()) !== '' && is_string($path) => $path,
            method_exists($file, 'getPathname') && ($path = $file->getPathname()) !== '' && is_string($path) => $path,
            method_exists($file, '__toString') && ($path = (string) $file) !== '' => $path,
            default => null,
        };
    }

    /**
     * Resolve the relative path of a file based on a given base path.
     *
     * @param  string  $basePath  The base path to resolve against.
     * @param  string  $filePath  The full file path to be resolved.
     * @return string The relative path of the file or the file's basename if the base path does not match.
     */
    protected function resolveRelativePath(string $basePath, string $filePath): string
    {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $filePath = str_replace('\\', '/', $filePath);

        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }

        return basename($filePath);
    }

    /**
     * Extract the class name from a PHP file's contents.
     *
     * @param  string  $filePath  The full file path to extract the class name from.
     * @return string|null The class name or null if not found.
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);

        if (! $contents) {
            return null;
        }

        $namespace = '';
        $class = null;

        foreach (token_get_all($contents) as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            match ($token[0]) {
                T_NAMESPACE => $namespace = $this->parseNamespace(token_get_all($contents), $i + 1),
                T_CLASS => $class = $this->parseClassName(token_get_all($contents), $i + 1),
                default => null,
            };

            if ($class !== null) {
                break;
            }
        }

        return $class ? trim("$namespace\\$class", '\\') : null;
    }

    /**
     * Parse the namespace from a PHP file's tokenized content.
     *
     * @param  array  $tokens  The tokenized content of the PHP file.
     * @param  int  $startIndex  The index to start parsing from.
     * @return string The parsed namespace.
     */
    protected function parseNamespace(array $tokens, int $startIndex): string
    {
        $namespace = '';

        for ($i = $startIndex; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            $namespace .= match (true) {
                is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) => $token[1],
                $token === '\\' => '\\',
                default => '',
            };
        }

        return trim($namespace, '\\');
    }

    /**
     * Parse the class name from a PHP file's tokenized content.
     *
     * @param  array  $tokens  The tokenized content of the PHP file.
     * @param  int  $startIndex  The index to start parsing from.
     * @return string|null The parsed class name or null if not found.
     */
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
