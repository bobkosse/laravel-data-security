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
    /**
     * @var string
     */
    protected $signature = 'privacy:encrypt-field';

    /**
     * @var string
     */
    protected $description = 'Add an existing field to the encrypted fields list';

    private ModelHandlingHelper $modelHandlingHelper;

    private IsEncryptedHelper $isEncryptedHelper;

    public function __construct(ModelHandlingHelper $modelHandlingHelper, IsEncryptedHelper $isEncryptedHelper)
    {
        $this->modelHandlingHelper = $modelHandlingHelper;
        $this->isEncryptedHelper = $isEncryptedHelper;
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelName = $this->askModelSelection();
        if (! $modelName) {
            return self::FAILURE;
        }

        $field = $this->askFieldSelection($modelName);
        if (! $field) {
            return self::FAILURE;
        }

        if (! $this->validateInput($modelName, $field)) {
            return self::FAILURE;
        }

        $model = new $modelName;
        if ($this->handleAlreadyEncrypted($modelName, $model, $field)) {
            return self::SUCCESS;
        }

        $rowCounter = $this->runEncryption($modelName, $model, $field);
        if ($rowCounter === -1) {
            return self::FAILURE;
        }

        $this->modelHandlingHelper->addPrivacyFieldToModel($modelName, $field);
        $this->showFinalReport($modelName, $field, $rowCounter);

        return self::SUCCESS;
    }

    /**
     * Asks the user to select a model from the available models.
     */
    private function askModelSelection(): ?string
    {
        $paths = config('data-security.paths', [app_path('Models')]);
        $models = [];
        foreach ($paths as $path) {
            $models = array_merge($models, $this->modelHandlingHelper->getModels($path));
        }

        if (count($models) === 0) {
            $this->error('No models found');

            return null;
        }

        return $this->choice('Which model do you want to update?', $models);
    }

    /**
     * Asks the user to select a field from the available fields.
     *
     * @param  string  $modelName  The name of the model to select a field for
     */
    private function askFieldSelection(string $modelName): ?string
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'privacy_id'];
        $privacyFields = $this->modelHandlingHelper->getPrivacyFields($modelName);

        $available = array_values(array_filter(
            $this->modelHandlingHelper->getModelFields($modelName),
            fn (string $f): bool => ! in_array($f, $excluded, true) && ! in_array($f, $privacyFields, true)
        ));

        if (count($available) === 0) {
            $this->error('No valid fields available to encrypt.');

            return null;
        }

        return $this->choice('Which field do you want to encrypt?', $available);
    }

    /**
     * Validates the input provided by the user.
     *
     * @param  string  $modelName  The name of the model to validate the field for
     * @param  string  $field  The name of the field to validate
     */
    private function validateInput(string $modelName, string $field): bool
    {
        if ($this->modelHandlingHelper->fieldAlreadyExistsInPrivacyFields($modelName, $field)) {
            $this->error("Field {$field} already exists in privacyFields");

            return false;
        }

        if (! Schema::hasColumn((new $modelName)->getTable(), $field)) {
            $this->error("Field {$field} does not exist in database");

            return false;
        }

        return true;
    }

    /**
     * Handles the case when the field is already encrypted.
     */
    private function handleAlreadyEncrypted(string $model_name, $model, string $field): bool
    {
        $row = $model->where($field, '!=', null)->first();

        if ($row !== null && $this->isEncryptedHelper->isAlreadyEncrypted($row->$field)) {
            if (! $this->confirm("Field {$field} already appears encrypted. Do you want to add it to privacyFields if {$model_name}?")) {
                $this->error("Field {$field} is already encrypted.");
            } else {
                $this->modelHandlingHelper->addPrivacyFieldToModel($model_name, $field);
                $this->info("Field {$field} added to privacyFields of model {$model_name}");
            }

            return true;
        }

        return false;
    }

    /**
     * Runs the encryption process for the specified field in the model.
     *
     * @param  string  $model_name  The name of the model to encrypt the field in.
     * @param  object  $model  The model instance to encrypt the field in.
     * @param  string  $field  The name of the field to encrypt.
     * @return int The number of rows encrypted.
     *
     * @TODO: There is something wrong with the encryption process. It only enctypts the fields on the first run. On
     *        new runs it only change the model code, but doesn't any encryption. Need to investigate why this is
     *        happening.
     */
    private function runEncryption(string $model_name, $model, string $field): int
    {
        $row_counter = 0;
        try {
            DB::transaction(function () use (&$row_counter, $model_name, $model, $field) {
                $model::query()->chunkById(100, function ($rows) use (&$row_counter, $model_name, $field) {
                    foreach ($rows as $row) {
                        $value = $row->getAttribute($field);

                        if ($value === null || $this->isEncryptedHelper->isAlreadyEncrypted($value)) {
                            continue;
                        }

                        if ($this->modelHandlingHelper->modelUsesHasPrivacy($model_name)) {
                            $row->setAttribute($field, $value);
                        } else {
                            $row->setAttribute($field, Crypt::encryptString((string) $value));
                            $row_counter++;
                        }

                        $row->save();
                    }
                });
            });

            return $row_counter;
        } catch (\Throwable $e) {
            $this->error("Encryption failed: {$e->getMessage()}");

            return -1;
        }
    }

    /**
     * Displays the final report after encryption is completed.
     *
     * @param  string  $model_name  The name of the model being processed.
     * @param  string  $field  The name of the field that was encrypted.
     * @param  int  $row_counter  The number of rows that were successfully encrypted.
     */
    private function showFinalReport(string $model_name, string $field, int $row_counter): void
    {
        $this->info('Encryption completed');
        $this->info('-----------------------------------------------------');
        $this->info("Enctypted {$row_counter} fields in {$model_name}.");
        $this->info('-----------------------------------------------------');
        $this->info("The following fields are encrypted in the model '{$model_name}':");

        $privacyFields = $this->modelHandlingHelper->getPrivacyFields($model_name);
        if (! in_array($field, $privacyFields, true)) {
            $privacyFields[] = $field;
        }
        foreach ($privacyFields as $f) {
            $this->info('- '.$f);
        }
    }
}
