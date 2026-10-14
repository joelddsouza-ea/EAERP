<?php

namespace App\Core\DTOs;

use Illuminate\Support\Arr;

/**
 * Base Data Transfer Object
 *
 * Provides common functionality for DTOs.
 */
abstract class BaseDTO
{
    /**
     * Create DTO from array.
     */
    public static function fromArray(array $data): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $params = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $value = $data[$name] ?? null;

                if ($value === null && $param->isDefaultValueAvailable()) {
                    $value = $param->getDefaultValue();
                } elseif ($value === null && !$param->allowsNull()) {
                    throw new \InvalidArgumentException("Missing required parameter: {$name}");
                }

                $params[] = $value;
            }
        }

        return new static(...$params);
    }

    /**
     * Create DTO from request.
     */
    public static function fromRequest($request): static
    {
        return static::fromArray($request->validated());
    }

    /**
     * Convert DTO to array.
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $this->$name;

            if ($value instanceof self) {
                $value = $value->toArray();
            } elseif (is_array($value)) {
                $value = array_map(function ($item) {
                    return $item instanceof self ? $item->toArray() : $item;
                }, $value);
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Get only specified fields.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->toArray(), $keys);
    }

    /**
     * Get all fields except specified.
     */
    public function except(array $keys): array
    {
        return Arr::except($this->toArray(), $keys);
    }
}
