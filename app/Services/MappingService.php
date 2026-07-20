<?php

namespace App\Services;

use App\Models\Connector;
use App\Models\FieldMapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Applies configured field mappings to an incoming payload, producing the
 * canonical/target shape. Source and target use dot-paths. Transforms are simple,
 * declarative rules stored on each mapping.
 */
class MappingService
{
    /**
     * Resolve the mappings for a connector + entity and apply them to $data.
     *
     * @return array{mapped: array<string, mixed>, missing: array<int, string>}
     */
    public function applyFor(?Connector $connector, ?string $entity, array $data): array
    {
        $query = FieldMapping::query()->where('entity', $entity);
        $query->where('connector_id', $connector?->id);

        return $this->apply($query->get(), $data);
    }

    /**
     * @param  iterable<FieldMapping>  $mappings
     * @return array{mapped: array<string, mixed>, missing: array<int, string>}
     */
    public function apply(iterable $mappings, array $data): array
    {
        $mapped = [];
        $missing = [];

        foreach ($mappings as $mapping) {
            $value = data_get($data, $mapping->source_field, null);

            if ($value === null) {
                $missing[] = $mapping->source_field;
            }

            $value = $this->transform($value, $mapping->transform ?? []);
            Arr::set($mapped, $mapping->target_field, $value);
        }

        return ['mapped' => $mapped, 'missing' => $missing];
    }

    private function transform(mixed $value, array $transform): mixed
    {
        $type = $transform['type'] ?? null;

        return match ($type) {
            'lowercase' => is_string($value) ? Str::lower($value) : $value,
            'uppercase' => is_string($value) ? Str::upper($value) : $value,
            'trim' => is_string($value) ? trim($value) : $value,
            'default' => $value ?? ($transform['value'] ?? null),
            default => $value,
        };
    }
}
