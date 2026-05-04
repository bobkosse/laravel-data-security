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
    public function getModels(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $models = [];

        foreach (File::allFiles($path) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->getClassNameFromFile($file->getRealPath() ?: $file->getPathname());

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            if (substr($className, -4) === 'User') {
                continue;
            }

            $models[] = $className;
        }

        return array_values($models);
    }

    private function getClassNameFromFile(string $filePath): ?string
    {
        $contents = File::get($filePath);

        if ($contents === '') {
            return null;
        }

        $namespace = null;
        $className = null;

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_NAMESPACE) {
                $namespace = $this->parseNamespace($tokens, $i + 1);
            }

            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                $className = $this->parseClassName($tokens, $i + 1);
                break;
            }
        }

        if ($className === null) {
            return null;
        }

        return $namespace ? $namespace.'\\'.$className : $className;
    }

    private function parseNamespace(array $tokens, int $startIndex): string
    {
        $namespace = '';

        for ($i = $startIndex, $count = count($tokens); $i < $count; $i++) {
            if (is_string($tokens[$i]) && $tokens[$i] === ';') {
                break;
            }

            if (is_array($tokens[$i])) {
                $namespace .= $tokens[$i][1];
            } else {
                $namespace .= $tokens[$i];
            }
        }

        return trim($namespace);
    }

    private function parseClassName(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                return $tokens[$i][1];
            }
        }

        return null;
    }

    public function addPrivacyFieldToModel(string $modelClass, string $field): bool
    {
        $reflection = new \ReflectionClass($modelClass);
        $filePath = $reflection->getFileName();

        if (! $filePath || ! File::exists($filePath)) {
            return false;
        }

        $contents = File::get($filePath);

        if ($contents === false) {
            return false;
        }

        // Check if the model already has a Protect attribute
        if (preg_match('/#\[Protect\(fields:\s*\[(.*?)\]\)\]/s', $contents, $matches)) {
            $contents = $this->updateProtectAttribute($contents, $field);
        } else {
            $contents = $this->addProtectAttribute($contents, $field);
        }

        $contents = $this->addProtectImport($contents);
        $contents = $this->addHasPrivacyImport($contents);
        $contents = $this->addHasPrivacyTrait($contents);

        File::put($filePath, $contents);

        return true;
    }

    protected function updateProtectAttribute(string $contents, string $field): string
    {
        return preg_replace_callback(
            '/#\[Protect\(fields:\s*\[(.*?)\]\)\]/s',
            function (array $matches) use ($field): string {
                $fieldsString = $matches[1];

                // Parse existing fields
                preg_match_all("/['\"]([^'\"]+)['\"]/", $fieldsString, $existingMatches);
                $existingFields = $existingMatches[1] ?? [];

                // Add new field if not already present
                if (! in_array($field, $existingFields, true)) {
                    $existingFields[] = $field;
                }

                // Format fields
                $formattedFields = array_map(
                    static fn (string $item): string => "'{$item}'",
                    $existingFields
                );

                return '#[Protect(fields: ['.implode(', ', $formattedFields).'])]';
            },
            $contents
        ) ?? $contents;
    }

    protected function addProtectAttribute(string $contents, string $field): string
    {
        $pattern = '/(class\s+\w+)/';

        return preg_replace_callback($pattern, function (array $matches) use ($field): string {
            return "#[Protect(fields: ['{$field}'])]\n".$matches[1];
        }, $contents, 1) ?? $contents;
    }

    protected function addProtectImport(string $contents): string
    {
        $import = 'use BobKosse\DataSecurity\Attributes\Protect;';

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

    public function getPrivacyFields(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $reflection = new \ReflectionClass($modelClass);

        if (! $reflection->isSubclassOf(Model::class)) {
            return [];
        }

        // Get privacy fields from the Protect attribute
        $attributes = $reflection->getAttributes(Protect::class);

        if (empty($attributes)) {
            return [];
        }

        return $attributes[0]->newInstance()->fields;
    }

    public function getModelFields(string $modelClass): array
    {
        $model = new $modelClass;

        return Schema::getColumnListing($model->getTable());
    }

    public function fieldAlreadyExistsInPrivacyFields(string $modelClass, string $field): bool
    {
        $privacyFields = $this->getPrivacyFields($modelClass);

        return in_array($field, $privacyFields, true);
    }
}
