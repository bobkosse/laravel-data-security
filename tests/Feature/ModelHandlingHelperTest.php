<?php

use BobKosse\DataSecurity\Helpers\ModelHandlingHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->helper = new ModelHandlingHelper;
    $this->testPath = base_path('tests/TmpHelperModels');

    if (File::exists($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
    File::makeDirectory($this->testPath, 0755, true);
});

afterEach(function () {
    if (File::exists($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('returns empty array when path does not exist', function () {
    $result = $this->helper->getModels('/non/existent/path');

    expect($result)->toBe([]);
});

it('skips non-php files', function () {
    File::put($this->testPath.'/NotPhpFile.txt', 'This is not a PHP file');
    File::put($this->testPath.'/ConfigFile.json', '{"key": "value"}');

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('skips files without valid PHP class', function () {
    File::put($this->testPath.'/InvalidPhp.php', '<?php echo "Not a class";');
    File::put($this->testPath.'/EmptyFile.php', '');

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('skips files where class does not exist', function () {
    $content = '<?php
    namespace Tests\\TmpHelperModels;

    class NonExistentClass extends SomeOtherClass {
        // This class extends something that does not exist
    }';

    File::put($this->testPath.'/NonExistentClass.php', $content);

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('skips classes that are not eloquent models', function () {
    // Test 1: Regular class without parent
    $regularClassContent = '<?php
        namespace Tests\\TmpHelperModels;

        class RegularClass {
            public function someMethod() {
                return "not a model";
            }
        }';

    // Test 2: Class that extends something else (not Model)
    $otherClassContent = '<?php
        namespace Tests\\TmpHelperModels;

        class CustomBaseClass {
            public function baseMethod() {
                return "base";
            }
        }

        class ClassExtendsOther extends CustomBaseClass {
            public function childMethod() {
                return "child";
            }
        }';

    // Test 3: Class that implements interface but doesn't extend Model
    $interfaceClassContent = '<?php
        namespace Tests\\TmpHelperModels;

        interface SomeInterface {
            public function doSomething();
        }

        class ClassImplementsInterface implements SomeInterface {
            public function doSomething() {
                return "doing something";
            }
        }';

    File::put($this->testPath.'/RegularClass.php', $regularClassContent);
    File::put($this->testPath.'/OtherClasses.php', $otherClassContent);
    File::put($this->testPath.'/InterfaceClass.php', $interfaceClassContent);

    require_once $this->testPath.'/RegularClass.php';
    require_once $this->testPath.'/OtherClasses.php';
    require_once $this->testPath.'/InterfaceClass.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('skips models ending with User', function () {
    $content = '<?php
    namespace Tests\\TmpHelperModels;

    use Illuminate\\Database\\Eloquent\\Model;

    class AdminUser extends Model {
        protected $table = "admin_users";
    }';

    File::put($this->testPath.'/AdminUser.php', $content);

    require_once $this->testPath.'/AdminUser.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('includes valid eloquent models', function () {
    $content = '<?php
    namespace Tests\\TmpHelperModels;

    use Illuminate\\Database\\Eloquent\\Model;

    class ValidModel extends Model {
        protected $table = "valid_models";
    }';

    File::put($this->testPath.'/ValidModel.php', $content);

    require_once $this->testPath.'/ValidModel.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toHaveCount(1);
    expect($result[0])->toBe('Tests\\TmpHelperModels\\ValidModel');
});

it('handles files without namespace', function () {
    $content = '<?php

    class GlobalScopeModel extends Illuminate\\Database\\Eloquent\\Model {
        protected $table = "global_models";
    }';

    File::put($this->testPath.'/GlobalScopeModel.php', $content);

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBeArray();
});

it('handles multiple models in same directory', function () {
    $model1 = '<?php
    namespace Tests\\TmpHelperModels;

    use Illuminate\\Database\\Eloquent\\Model;

    class FirstModel extends Model {
        protected $table = "first_models";
    }';

    $model2 = '<?php
    namespace Tests\\TmpHelperModels;

    use Illuminate\\Database\\Eloquent\\Model;

    class SecondModel extends Model {
        protected $table = "second_models";
    }';

    File::put($this->testPath.'/FirstModel.php', $model1);
    File::put($this->testPath.'/SecondModel.php', $model2);

    require_once $this->testPath.'/FirstModel.php';
    require_once $this->testPath.'/SecondModel.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toHaveCount(2);
    expect($result)->toContain('Tests\\TmpHelperModels\\FirstModel');
    expect($result)->toContain('Tests\\TmpHelperModels\\SecondModel');
});

it('handles malformed class syntax that returns null className', function () {
    // Test 1: Class keyword without name
    $noClassNameContent = '<?php
            namespace Tests\\TmpHelperModels;

            use Illuminate\\Database\\Eloquent\\Model;

            class {
                // Invalid: class without name
            }';

    // Test 2: Class keyword followed by non-string tokens
    $invalidClassNameContent = '<?php
            namespace Tests\\TmpHelperModels;

            use Illuminate\\Database\\Eloquent\\Model;

            class 123InvalidName extends Model {
                // Invalid: class name starting with number
            }';

    // Test 3: Incomplete class declaration
    $incompleteClassContent = '<?php
            namespace Tests\\TmpHelperModels;

            use Illuminate\\Database\\Eloquent\\Model;

            class';

    // Test 4: Class with syntax errors in name area
    $syntaxErrorContent = '<?php
            namespace Tests\\TmpHelperModels;

            use Illuminate\\Database\\Eloquent\\Model;

            class $InvalidName extends Model {
                // Invalid: class name starting with $
            }';

    File::put($this->testPath.'/NoClassName.php', $noClassNameContent);
    File::put($this->testPath.'/InvalidClassName.php', $invalidClassNameContent);
    File::put($this->testPath.'/IncompleteClass.php', $incompleteClassContent);
    File::put($this->testPath.'/SyntaxError.php', $syntaxErrorContent);

    $result = $this->helper->getModels($this->testPath);
    expect($result)->toBe([]);
});

it('handles files with class keyword but no valid class name tokens', function () {
    $malformedContent = '<?php
            namespace Tests\\TmpHelperModels;

            class ( ) extends Something {
                // Malformed class declaration with parentheses instead of name
            }';

    File::put($this->testPath.'/MalformedClass.php', $malformedContent);

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('handles files with class keyword followed by only non-T_STRING tokens', function () {
    $nonStringTokensContent = '<?php
            namespace Tests\\TmpHelperModels;

            class /* comment */ { /* immediately opening brace */
                public function test() {}
            }';

    File::put($this->testPath.'/NonStringTokens.php', $nonStringTokensContent);

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBe([]);
});

it('handles complex namespace parsing scenarios', function () {
    // Test 1: Simple namespace (most tokens will be arrays)
    $simpleNamespaceContent = '<?php
            namespace App\\Models;

            use Illuminate\\Database\\Eloquent\\Model;

            class SimpleNamespaceModel extends Model {
                protected $table = "simple_models";
            }';

    // Test 2: Namespace with backslashes and complex structure
    $complexNamespaceContent = '<?php
            namespace App\\Sub\\Module\\Models;

            use Illuminate\\Database\\Eloquent\\Model;

            class ComplexNamespaceModel extends Model {
                protected $table = "complex_models";
            }';

    // Test 3: Namespace with spaces and comments (edge case)
    $spacedNamespaceContent = '<?php
            namespace  App\\Models\\Special  ;  // comment

            use Illuminate\\Database\\Eloquent\\Model;

            class SpacedNamespaceModel extends Model {
                protected $table = "spaced_models";
            }';

    File::put($this->testPath.'/SimpleNamespaceModel.php', $simpleNamespaceContent);
    File::put($this->testPath.'/ComplexNamespaceModel.php', $complexNamespaceContent);
    File::put($this->testPath.'/SpacedNamespaceModel.php', $spacedNamespaceContent);

    require_once $this->testPath.'/SimpleNamespaceModel.php';
    require_once $this->testPath.'/ComplexNamespaceModel.php';
    require_once $this->testPath.'/SpacedNamespaceModel.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toHaveCount(3);
    expect($result)->toContain('App\\Models\\SimpleNamespaceModel');
    expect($result)->toContain('App\\Sub\\Module\\Models\\ComplexNamespaceModel');
    expect($result)->toContain('App\\Models\\Special\\SpacedNamespaceModel');
});

it('handles namespace with mixed token types', function () {
    $mixedTokenContent = '<?php
            namespace Tests\\TmpHelperModels\\Deep\\Nested\\Structure;

            use Illuminate\\Database\\Eloquent\\Model;

            class MixedTokenModel extends Model {
                protected $table = "mixed_token_models";
            }';

    File::put($this->testPath.'/MixedTokenModel.php', $mixedTokenContent);

    require_once $this->testPath.'/MixedTokenModel.php';

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toHaveCount(1);
    expect($result[0])->toBe('Tests\\TmpHelperModels\\Deep\\Nested\\Structure\\MixedTokenModel');
});

it('handles edge cases in namespace parsing', function () {
    $edgeCaseContent = '<?php
            namespace Tests\\TmpHelperModels\\{Special};

            use Illuminate\\Database\\Eloquent\\Model;

            class EdgeCaseModel extends Model {
                protected $table = "edge_case_models";
            }';

    File::put($this->testPath.'/EdgeCaseModel.php', $edgeCaseContent);

    $result = $this->helper->getModels($this->testPath);

    expect($result)->toBeArray();
});

it('returns false for non-existent class', function () {
    $result = $this->helper->modelUsesHasPrivacy('NonExistentClass');

    expect($result)->toBeFalse();
});

it('returns false for non-model class', function () {
    $result = $this->helper->modelUsesHasPrivacy('stdClass');

    expect($result)->toBeFalse();
});

it('returns false when file exists but cannot be read', function () {
    $content = '<?php
            namespace Tests\\TmpHelperModels;

            use Illuminate\\Database\\Eloquent\\Model;

            class UnreadableModel extends Model {
                protected $table = "unreadable_models";
            }';

    $filePath = $this->testPath.'/UnreadableModel.php';
    File::put($filePath, $content);
    require_once $filePath;

    File::partialMock();

    File::shouldReceive('exists')
        ->with($filePath)
        ->once()
        ->andReturn(true);

    File::shouldReceive('get')
        ->with($filePath)
        ->once()
        ->andThrow(new ErrorException('Permission denied'));

    $result = $this->helper->addPrivacyFieldToModel('Tests\\TmpHelperModels\\UnreadableModel', 'test_field');

    expect($result)->toBeFalse();
});

it('returns empty array for non-existent class', function () {
    $result = $this->helper->getPrivacyFields('NonExistentClass');

    expect($result)->toBe([]);
});

it('returns empty array for non-model class', function () {
    $result = $this->helper->getPrivacyFields('stdClass');

    expect($result)->toBe([]);
});

it('returns false when reflection cannot get file path', function () {
    $result = $this->helper->addPrivacyFieldToModel('stdClass', 'test_field');

    expect($result)->toBeFalse();
});

it('returns false when model file does not exist', function () {
    $className = 'Tests\\TmpHelperModels\\InMemoryModel';

    $classCode = "
    namespace Tests\\TmpHelperModels;
    use Illuminate\\Database\\Eloquent\\Model;
    class InMemoryModel extends Model {
        protected \$table = 'in_memory_models';
    }";

    eval($classCode);

    $result = $this->helper->addPrivacyFieldToModel($className, 'test_field');

    expect($result)->toBeFalse();
});

it('successfully adds privacy field to model when file exists and is readable', function () {
    $content = '<?php
    namespace Tests\\TmpHelperModels;

    use Illuminate\\Database\\Eloquent\\Model;

    class WorkingModel extends Model {
        protected $table = "working_models";
    }';

    $filePath = $this->testPath.'/WorkingModel.php';
    File::put($filePath, $content);
    require_once $filePath;

    $result = $this->helper->addPrivacyFieldToModel('Tests\\TmpHelperModels\\WorkingModel', 'email');

    expect($result)->toBeTrue();

    $updatedContent = File::get($filePath);
    expect($updatedContent)->toContain('#[Protect(fields: [\'email\'])]');
    expect($updatedContent)->toContain('use BobKosse\\DataSecurity\\Attributes\\Protect;');
    expect($updatedContent)->toContain('use BobKosse\\DataSecurity\\Traits\\HasPrivacy;');
    expect($updatedContent)->toContain('use HasPrivacy;');
});

it('adds hasPrivacy import when model has no existing use statements', function () {
    $content = '<?php
        namespace Tests\\TmpHelperModels;

        class ModelWithoutUseForHasPrivacy extends \Illuminate\Database\Eloquent\\Model {
            protected $table = "models_without_use_for_has_privacy";
        }';

    $filePath = $this->testPath.'/ModelWithoutUseForHasPrivacy.php';
    File::put($filePath, $content);
    require_once $filePath;

    $result = $this->helper->addPrivacyFieldToModel('Tests\\TmpHelperModels\\ModelWithoutUseForHasPrivacy', 'email');

    expect($result)->toBeTrue();

    $updatedContent = File::get($filePath);

    expect($updatedContent)->toContain('use BobKosse\\DataSecurity\\Attributes\\Protect;');
    expect($updatedContent)->toContain('use BobKosse\\DataSecurity\\Traits\\HasPrivacy;');
    expect($updatedContent)->toContain('#[Protect(fields: [\'email\'])]');
    expect($updatedContent)->toContain('use HasPrivacy;');

    expect($updatedContent)->toMatch('/namespace Tests\\\\TmpHelperModels;\s*use BobKosse\\\\DataSecurity\\\\Attributes\\\\Protect;\s*\s*use BobKosse\\\\DataSecurity\\\\Traits\\\\HasPrivacy;\s*\s*#\[Protect/');
});

it('returns false when model has no privacy fields', function () {
    $result = $this->helper->fieldAlreadyExistsInPrivacyFields('stdClass', 'email');

    expect($result)->toBeFalse();
});

it('tests addHasPrivacyImport method directly with no existing use statements', function () {
    $content = '<?php
namespace Tests\TmpHelperModels;

class TestModel extends \Illuminate\Database\Eloquent\Model {
    protected $table = "test_models";
}';

    $reflection = new ReflectionMethod($this->helper, 'addHasPrivacyImport');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($this->helper, $content);

    expect($result)->toContain('use BobKosse\\DataSecurity\\Traits\\HasPrivacy;');

    expect($result)->toMatch('/namespace Tests\\\\TmpHelperModels;\s*use BobKosse\\\\DataSecurity\\\\Traits\\\\HasPrivacy;\s*\s*class TestModel/');
});

it('returns true when model uses HasPrivacy trait', function () {
    $result = $this->helper->modelUsesHasPrivacy('Tests\\MockModels\\ProtectedModel');

    expect($result)->toBeTrue();
});

it('returns false when model does not use HasPrivacy trait', function () {
    $result = $this->helper->modelUsesHasPrivacy('Tests\\MockModels\\UnprotectedModel');

    expect($result)->toBeFalse();
});
