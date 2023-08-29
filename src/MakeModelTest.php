<?php

namespace WallaceMaxters\Testudo;

use Illuminate\Support\Arr;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;

use function sprintf;
use function str;

#[AsCommand(name: 'testudo:make-model-test')]
class MakeModelTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'testudo:make-model-test {model} {--force} {--namespace=App\\Models\\} {--path=tests/Unit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates a testcase file for a specific eloquent model';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $namespace = $this->option('namespace');

        $class = $this->argument('model');

        $this->generateFile($namespace, $class);
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath(string $stub)
    {
        if (file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))) {
            return $customPath;
        }

        return dirname(__DIR__) . $stub;
    }

    /**
     * Generate file
     *
     * @param string $namespace
     * @param string $class
     * @return string
     */
    protected function generateFile(string $namespace, string $class): string
    {
        $basePath = str($this->option('path'))->rtrim('\\')->finish('/Models/');

        $target = $basePath . $class . 'Test.php';

        $file = $this->laravel['files'];

        if ($file->exists($target) && !$this->option('force')) {
            $this->line("File {$target} already exists.");
            return $target;
        }

        $replacements = $this->getPreparedReplacements($namespace, $class);

        $template = $file->get(
            $this->resolveStubPath('/stubs/ModelTest.stub')
        );

        $file->makeDirectory(dirname($target), force: true, recursive: true);

        $file->put($target, strtr($template, $replacements));

        $this->info("Model Test $target is generated!");

        return $target;
    }

    /**
     * Get the replacements with the key tags
     *
     * @param string $namespace
     * @param string $class
     * @return array<string, string>
     */
    protected function getPreparedReplacements(string $namespace, string $class): array
    {
        return Arr::mapWithKeys(
            $this->getReplacements($namespace, $class),
            fn ($value, $key) => [sprintf('{{ %s }}', $key) => $value]
        );
    }

    /**
     * Get the replacements key-value for template
     *
     * @param string $namespace
     * @param string $classBaseName
     * @return array
     */
    protected function getReplacements(string $namespace, string $classBaseName): array
    {
        $class = rtrim($namespace, '\\') . '\\' . $classBaseName;

        /**
         * @var Model
         */
        $model = new $class;

        $this->fillModel($model);


        $keyValue = [
            'classWithNamespace' => $class,
            'class'              => $classBaseName,
            'tableName'          => $model->getTable(),
            'keyName'            => $model->getKeyName(),
            'namespace'          => $namespace,
            'extends'            => '\Tests\TestCase',
            'code'               => null,
            'fill'               => var_export($model->getAttributes(), true)
        ];

        foreach ($this->getRelations($model) as $name => $relationType) {

            $relationClass = class_basename($relationType);

            $keyValue['code'] .= <<<PHP

                public function test_{$name}_relationship()
                {
                    return \$this->assertInstanceOf({$relationClass}::class, \$this->model->{$name}());
                }

            PHP;
        }

        $attributes = $this->getAttributes($model);

        foreach ($attributes as $name => $value) {

            if (is_bool($value)) {

                $assertName = $value ? 'assertTrue' : 'assertFalse';

                $keyValue['code'] .= <<<PHP

                public function test_{$name}_attribute()
                {
                    return \$this->{$assertName}(\$this->model->{$name});
                }

            PHP;
                continue;
            }


            $keyValue['code'] .= <<<PHP

                public function test_{$name}_attribute()
                {
                    return \$this->assertEquals('{$value}', \$this->model->{$name});
                }

            PHP;
        }

        foreach ($this->getScopes($model) as $scope) {

            $keyValue['code'] .= <<<PHP

                public function test_{$scope}_scope()
                {
                    return \$this->assertInstanceOf(Builder::class, {$classBaseName}::{$scope}());
                }

            PHP;
        }

        return $keyValue;
    }

    /**
     * Find all relationships and your return type from Model
     *
     * @param Model $model
     * @return array<string, string>
     */
    protected function getRelations(Model $model): array
    {
        $relationships = [];

        $reflect = new ReflectionClass($model);

        foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectMethod) {

            $name = $reflectMethod->getName();

            // try to get relationship via return type
            $returnType = $reflectMethod->getReturnType()?->getName();

            if ($reflectMethod->hasReturnType() && is_subclass_of($returnType, Relation::class, true)) {
                $relationships[$name] = $returnType;
            } elseif ($this->methodIsRelationship($model, $reflectMethod)) {
                try {
                    $relationships[$name] = get_class($model->{$name}());
                } catch(\Throwable) {
                    // not to do
                }
            }
        }

        return $relationships;
    }

    /**
     * Check if method of model is a relationship
     *
     * @param Model $model
     * @param ReflectionMethod $reflect
     * @return boolean
     */
    protected function methodIsRelationship(Model $model, ReflectionMethod $reflect): bool
    {
        $name = $reflect->getName();

        if (str($name)->endswith('Attribute') || str($name)->startsWith('scope')) {
            return false;
        }

        return $reflect->getNumberOfParameters() === 0 && $reflect->class === get_class($model);
    }

    /**
     * Get Attributes for model
     *
     * @param Model $model
     * @return array
     */
    protected function getAttributes(Model $model): array
    {
        $reflect = new ReflectionClass($model);

        $relationships = [];

        $factory = method_exists($model, 'factory');

        foreach ($reflect->getMethods() as $reflectMethod) {

            $name = $reflectMethod->getName();
            $type = $reflectMethod->getReturnType()?->getName();

            if ($reflectMethod->class !== $reflect->getName()) {
                continue;
            }

            if ($type === Attribute::class) {
                $key = str($name)->snake()->toString();
            }
            elseif ($attribute = str($name)->match('/^get(.*)Attribute$/')->toString()) {
                $key = str($attribute)->snake()->toString();
            } else {
                continue;
            }

            try {
                $relationships[$key] = $factory ? $model->{$key} : null;
            } catch (\Throwable)  {
                $relationships[$key] = null;
            }

        }

        return $relationships;
    }

    protected function getScopes(Model $model): array
    {
        $reflect = new ReflectionClass($model);

        $scopes = [];

        foreach ($reflect->getMethods() as $reflectMethod) {

            $name = $reflectMethod->getName();

            if ($reflectMethod->class !== $reflect->getName()) {
                continue;
            }

            if (! str_starts_with($name, 'scope')) continue;

            $scope = str($name)->substr(5)->toString();

            $scope = strtolower($scope[0]) . substr($scope, 1);

            $scopes[] = $scope;

        }

        return $scopes;
    }

    protected function reallyHasFactory(Model $model): bool
    {
        $factory = $this->reallyHasFactory($model);

        if ($factory === false) return false;

        try {
            $model::factory();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function fillModel(Model &$model): void
    {
        try {
            $model = $model::factory()->make();
        } catch (\Throwable ) {
            return;
        }

        $model->forceFill([
            $model->getKeyName() => $model->getKeyType() === 'string' ? str()->random(5) : random_int(1, 10)
        ]);
    }
}
