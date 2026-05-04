<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Commands;

use BobKosse\DataSecurity\Helpers\IsEncryptedHelper;
use BobKosse\DataSecurity\Helpers\ModelHandlingHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrivacyEncryptFieldCommand extends Command
{
    protected $signature = 'privacy:encrypt-field';

    protected $description = 'Add an existing field to the encrypted fields list';

    private $modelHandlingHelper;

    private $isEncryptedHelper;

    public function __construct(ModelHandlingHelper $modelHandlingHelper, IsEncryptedHelper $isEncryptedHelper)
    {
        $this->modelHandlingHelper = $modelHandlingHelper;
        $this->isEncryptedHelper = $isEncryptedHelper;
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = config('data-security.paths', [app_path('Models')]);
        $models = [];
        foreach ($paths as $path) {
            $models = array_merge($models, $this->modelHandlingHelper->getModels($path));
        }

        if (count($models) == 0) {
            $this->error('No models found');

            return self::FAILURE;
        }
        $model_name = $this->choice('Which model do you want to update?', $models);

        $excludedFields = ['id', 'created_at', 'updated_at', 'deleted_at', 'privacy_id'];
        $privacyFields = $this->modelHandlingHelper->getPrivacyFields($model_name);

        $availableFields = array_values(array_filter(
            $this->modelHandlingHelper->getModelFields($model_name),
            fn (string $field): bool => ! in_array($field, $excludedFields, true) &&
                ! in_array($field, $privacyFields, true)
        ));

        if (count($availableFields) == 0) {
            $this->error('No valid fields available to encrypt.');

            return self::FAILURE;
        }

        $field = $this->choice('Which field do you want to encrypt?', $availableFields);

        $model = new $model_name;
        $protectedFields = $this->modelHandlingHelper->getPrivacyFields($model_name);

        if ($this->modelHandlingHelper->fieldAlreadyExistsInPrivacyFields($model_name, $field)) {
            $this->error("Field {$field} already exists in privacyFields");

            return self::FAILURE;
        }

        if (! Schema::hasColumn($model->getTable(), $field)) {
            $this->error("Field {$field} does not exist in database");

            return self::FAILURE;
        }

        $row = $model->where($field, '!=', null)->first();
        if ($row !== null && $this->isEncryptedHelper->isAlreadyEncrypted($row->$field)) {

            if (! $this->confirm("Field {$field} already appears encrypted. Do you want to add it to privacyFields if {$model_name}?")) {
                $this->error("Field {$field} is already encrypted.");
            } else {
                $this->modelHandlingHelper->addPrivacyFieldToModel($model_name, $field);
                $this->info("Field {$field} added to privacyFields of model {$model_name}");
            }

            return self::SUCCESS;

        }

        $row_counter = 0;
        try {
            DB::transaction(function () use (&$row_counter, $model_name, $model, $field) {
                $query = $model::query();

                $query->chunkById(100, function ($rows) use (&$row_counter, $model_name, $field) {
                    foreach ($rows as $row) {
                        $value = $row->getAttribute($field);

                        if ($value === null || $this->isEncryptedHelper->isAlreadyEncrypted($value)) {
                            continue;
                        }

                        if ($this->modelHandlingHelper->modelUsesHasPrivacy($model_name)) {
                            $row->setAttribute($field, $value);
                        } else {
                            $row->setAttribute($field, Crypt::encryptString((string) $value));
                        }

                        $row_counter++;
                        $row->save();
                    }
                });
            });
        } catch (\Throwable $e) {
            $this->error("Encryption failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->modelHandlingHelper->addPrivacyFieldToModel($model_name, $field);

        $this->info('Encryption completed');
        $this->info('-----------------------------------------------------');
        $this->info("Enctypted {$row_counter} fields in {$model_name}.");
        $this->info('-----------------------------------------------------');
        $this->info("The following fields are encrypted in the model '{$model_name}':");
        $privacyFields = $this->modelHandlingHelper->getPrivacyFields($model_name);
        if (! in_array($field, $privacyFields, true)) {
            $privacyFields[] = $field;
        }
        foreach ($privacyFields as $field) {
            $this->info('- '.$field);
        }

        return self::SUCCESS;
    }
}
