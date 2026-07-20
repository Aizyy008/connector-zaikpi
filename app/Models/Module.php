<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Module extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'type',
        'description',
        'actions',
        'input_schema',
        'output_schema',
        'execution_method',
        'scopes',
        'health_status',
        'version',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'input_schema' => 'array',
            'output_schema' => 'array',
            'scopes' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Required input fields (declared in `input_schema`) that are absent or empty
     * in the given (already-mapped) input. Used to validate a job BOTH on initial
     * dispatch and on every retry, so an incomplete payload can never be completed.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, string> missing field names (empty = valid)
     */
    public function missingRequiredInput(array $input): array
    {
        $missing = [];

        foreach (array_keys($this->input_schema ?? []) as $field) {
            $value = data_get($input, $field);

            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
