<?php

namespace Dedoc\Scramble\Support\TypeToSchemaExtensions;

use Carbon\Carbon;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\ResponseExtractor\ModelInfo;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Database\Eloquent\Model;

class ModelToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(Model::class);
    }

    /**
     * @param  ObjectType  $type
     */
    public function toSchema(Type $type)
    {
        $modelName = $type->name;

        $modelInfo = new ModelInfo($modelName);

        /** @var Model $instance */
        $instance = app()->make($modelName);
        $info = $modelInfo->handle();
        $type = $modelInfo->type();

        $arrayableAttributesTypes = $info->get('attributes', collect())
            ->when($instance->getVisible(), fn ($c, $visible) => $c->only($visible))
            ->when($instance->getHidden(), fn ($c, $visible) => $c->except($visible))
            ->filter(fn ($attr) => $attr['appended'] !== false)
            ->map(function ($_, $name) use ($type) {
                $attrType = $type->getPropertyFetchType($name);
                if (
                    $attrType instanceof Union
                    && count($attrType->types) === 2
                    && $attrType->types[0] instanceof NullType
                    && $attrType->types[1]->isInstanceOf(Carbon::class)
                ) {
                    $dateStringType = new StringType();
                    $dateStringType->setAttribute('format', 'date-time');

                    return Union::wrap([new NullType(), $dateStringType]);
                }

                return $type->getPropertyFetchType($name);
            });

        $arrayableRelationsTypes = $info->get('relations', collect())
            ->when($instance->getVisible(), fn ($c, $visible) => $c->only($visible))
            ->when($instance->getHidden(), fn ($c, $visible) => $c->except($visible))
            ->map(function ($_, $name) use ($type) {
                return $type->getPropertyFetchType($name);
            });

        $t = new ArrayType([
            ...$arrayableAttributesTypes->map(fn ($type, $name) => new ArrayItemType_($name, $type))->values()->all(),
            ...$arrayableRelationsTypes->map(fn ($type, $name) => new ArrayItemType_($name, $type, $isOptional = true))->values()->all(),
        ]);
//        dd($t);
        return $this->openApiTransformer->transform($t);

        $type = $this->infer->analyzeClass($type->name);

        return $this->openApiTransformer->transform(
            $type->getMethodCallType('toArray')
        );
    }

    /**
     * @param  ObjectType  $type
     */
    public function toResponse(Type $type)
    {
        return Response::make(200)
            ->description('`'.$this->components->uniqueSchemaName($type->name).'`')
            ->setContent(
                'application/json',
                Schema::fromType($this->openApiTransformer->transform($type)),
            );
    }

    public function reference(ObjectType $type)
    {
        return new Reference('schemas', $type->name, $this->components);
    }
}
