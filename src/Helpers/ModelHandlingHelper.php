<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Helpers;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\SplFileInfo;

class ModelHandlingHelper
{
    /**
     * Retrieves all models from the specified directory path.
     *
     * @param  string  $path  The directory path to search for models.
     * @return array An array of model class names.
     */
    public function getModels(string $path): array
    {
        if (! File::isDirectory($path)) {
            return [];
        }

        return collect(File::allFiles($path))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file) => $this->getClassNameFromFile($file->getRealPath()))
            ->filter(function (?string $className) {
                return $className
                    && is_subclass_of($className, Model::class)
                    && class_basename(substr($className, -4)) !== 'User';
            })
            ->values()
            ->toArray();
    }

    /**
     * Extracts the class name from a PHP file using token_get_all.
     *
     * @param  string  $filePath  The path to the PHP file.
     * @return ?string The class name if found, null otherwise.
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $tokens = token_get_all(file_get_contents($filePath));
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }
            match ($token[0]) {
                T_NAMESPACE => $namespace = $this->parseNamespace($tokens, $index + 1),
                T_CLASS => $className = $this->parseClassName($tokens, $index + 1),
                default => null,
            };

            if (isset($className)) {
                return $namespace ? $namespace.'\\'.$className : $className;
            }
        }

        return null;
    }

    /**
     * Parses the namespace from the given tokens starting at the specified index.
     *
     * @param  array  $tokens  The array of tokens.
     * @param  int  $startIndex  The index to start parsing from.
     * @return string The parsed namespace.
     */
    private function parseNamespace(array $tokens, int $startIndex): string
    {
        $namespace = '';

        for ($i = $startIndex; isset($tokens[$i]) && $tokens[$i] !== ';'; $i++) {
            $namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return trim($namespace);
    }

    /**
     * Parses the class name from the given tokens starting at the specified index.
     *
     * @param  array  $tokens  The array of tokens.
     * @param  int  $startIndex  The index to start parsing from.
     * @return string|null The parsed class name, or null if not found.
     */
    private function parseClassName(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex; isset($tokens[$i]); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                return $tokens[$i][1];
            }

            if ($tokens[$i] === '{') {
                break;
            }
        }

        return null;
    }

    /**
     * Adds a privacy field to the specified model class.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @param  string  $field  The name of the privacy field to add.
     * @return bool True if the field was successfully added, false otherwise.
     */
    public function addPrivacyFieldToModel(string $modelClass, string $field): bool
    {
        $filePath = $this->getFilePath($modelClass);

        if (! $filePath) {
            return false;
        }

        try {
            $contents = File::get($filePath);

            $contents = $this->addProtectImport($contents);
            $contents = $this->addHasPrivacyImport($contents);
            if (preg_match('/#\[Protect\(fields:\s*\[(.*?)\]\)\]/s', $contents, $matches)) {
                $contents = $this->updateProtectAttribute($contents, $field);
            } else {
                $contents = $this->addProtectAttribute($contents, $field);
            }

            $contents = $this->addHasPrivacyTrait($contents);

            return File::put($filePath, $contents) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retrieves the file path for the given model class.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @return bool|string The file path if found, false otherwise.
     */
    private function getFilePath(string $modelClass): bool|string
    {
        $reflection = new \ReflectionClass($modelClass);
        $filePath = $reflection->getFileName();

        if (! $filePath || ! File::exists($filePath)) {
            return false;
        }

        return $filePath;
    }

    /**
     * Updates the protect attribute in the given model file contents with the specified field.
     *
     * @param  string  $contents  The contents of the model file.
     * @param  string  $field  The field to add to the protect attribute.
     * @return string The updated contents with the protect attribute updated.
     */
    protected function updateProtectAttribute(string $contents, string $field): string
    {
        return preg_replace_callback(
            '/#\[Protect\(fields:\s*\[(.*?)\]\)\]/s',
            fn (array $matches): string => $this->formatProtectAttribute($matches[1], $field),
            $contents
        ) ?? $contents;
    }

    /**
     * Formats the protect attribute with the given fields string and new field.
     *
     * @param  string  $fieldsString  The existing fields string.
     * @param  string  $newField  The field to add to the protect attribute.
     * @return string The formatted protect attribute string.
     */
    private function formatProtectAttribute(string $fieldsString, string $newField): string
    {
        preg_match_all("/['\"]([^'\"]+)['\"]/", $fieldsString, $matches);
        $fields = $matches[1] ?? [];

        if (! in_array($newField, $fields, true)) {
            $fields[] = $newField;
        }

        $formatted = collect($fields)
            ->map(fn (string $f) => "'$f'")
            ->implode(', ');

        return "#[Protect(fields: [$formatted])]";
    }

    /**
     * Adds a protect attribute to the given model file contents with the specified field.
     *
     * @param  string  $contents  The contents of the model file.
     * @param  string  $field  The field to add to the protect attribute.
     * @return string The updated contents with the protect attribute added.
     */
    protected function addProtectAttribute(string $contents, string $field): string
    {
        $pattern = '/^(\s*)(class\s+\w+)/m';

        $result = preg_replace_callback($pattern, function (array $matches) use ($field): string {
            return $matches[1]."#[Protect(fields: ['{$field}'])]\n".$matches[1].$matches[2];
        }, $contents, 1);

        return $result ?? $contents;
    }

    /**
     * Adds the Protect import to the given model file contents.
     *
     * @param  string  $contents  The contents of the model file.
     * @return string The updated contents with the Protect import added.
     */
    protected function addProtectImport(string $contents): string
    {
        $import = 'use BobKosse\DataSecurity\Attributes\Protect;';

        if (str_contains($contents, $import)) {
            return $contents;
        }

        return preg_replace_callback(
            '/(namespace\s+[^;]+;)/',
            fn (array $matches): string => "{$matches[1]}\n\n{$import}",
            $contents,
            1
        ) ?? $contents;
    }

    /**
     * Adds the HasPrivacy import to the given model file contents.
     *
     * @param  string  $contents  The contents of the model file.
     * @return string The updated contents with the HasPrivacy import added.
     */
    protected function addHasPrivacyImport(string $contents): string
    {
        $import = 'use BobKosse\DataSecurity\Traits\HasPrivacy;';

        if (str_contains($contents, $import)) {
            return $contents;
        }

        $pattern = '/(namespace\s+[^;]+;\s*)(.*?)(\r?\n\s*(?:#\[.*?\]\s*)?class\s+)/s';

        return preg_replace_callback($pattern, function (array $matches) use ($import): string {
            $beforeClass = $matches[2];

            if (preg_match('/use\s+[^;]+;/', $beforeClass)) {
                return $matches[1].trim($beforeClass)."\n".$import."\n".$matches[3];
            }

            return $matches[1].$import."\n\n".$matches[3];
        }, $contents, 1) ?? $contents;
    }

    /**
     * Adds the HasPrivacy trait to the given model file contents.
     *
     * @param  string  $contents  The contents of the model file.
     * @return string The updated contents with the HasPrivacy trait added.
     */
    protected function addHasPrivacyTrait(string $contents): string
    {
        if (str_contains($contents, 'use HasPrivacy;')) {
            return $contents;
        }

        $pattern = '/(class\s+\w+\s*(?:extends\s+[^{]+)?\{)/';

        return preg_replace_callback($pattern, function (array $matches): string {
            return $matches[1]."\n    use HasPrivacy;";
        }, $contents, 1) ?? $contents;
    }

    /**
     * Checks if the given model class uses the HasPrivacy trait.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @return bool True if the model uses HasPrivacy, false otherwise.
     */
    public function modelUsesHasPrivacy(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        $reflection = new \ReflectionClass($modelClass);

        if (! $reflection->isSubclassOf(Model::class)) {
            return false;
        }

        return in_array(HasPrivacy::class, $reflection->getTraitNames(), true);
    }

    /**
     * Retrieves the privacy fields defined in the given model class.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @return array An array of privacy field names.
     */
    public function getPrivacyFields(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $reflection = new \ReflectionClass($modelClass);

        if (! $reflection->isSubclassOf(Model::class)) {
            return [];
        }

        $attributes = $reflection->getAttributes(Protect::class);

        if (empty($attributes)) {
            return [];
        }

        return $attributes[0]->newInstance()->fields;
    }

    /**
     * Retrieves the non-privacy fields defined in the given model class.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @return array An array of non-privacy field names.
     */
    public function getModelFields(string $modelClass): array
    {
        $model = new $modelClass;

        return Schema::getColumnListing($model->getTable());
    }

    /**
     * Checks if the given field already exists in the privacy fields of the model.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @param  string  $field  The field name to check.
     * @return bool True if the field exists in privacy fields, false otherwise.
     */
    public function fieldAlreadyExistsInPrivacyFields(string $modelClass, string $field): bool
    {
        $privacyFields = $this->getPrivacyFields($modelClass);

        return in_array($field, $privacyFields, true);
    }
}
