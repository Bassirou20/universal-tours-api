<?php

namespace App\Traits;

use App\Models\ActivityLog;

trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            ActivityLog::record(
                'created',
                static::activityDescription('créé', $model),
                $model,
            );
        });

        static::updated(function ($model) {
            $dirty = $model->getDirty();
            $original = array_intersect_key($model->getOriginal(), $dirty);

            // Skip if only timestamps changed
            $meaningful = array_diff_key($dirty, array_flip(['updated_at']));
            if (empty($meaningful)) return;

            $changes = [];
            foreach ($meaningful as $field => $newVal) {
                $changes[$field] = ['avant' => $original[$field] ?? null, 'apres' => $newVal];
            }

            ActivityLog::record(
                'updated',
                static::activityDescription('modifié', $model),
                $model,
                $changes,
            );
        });

        static::deleted(function ($model) {
            ActivityLog::record(
                'deleted',
                static::activityDescription('supprimé', $model),
                $model,
            );
        });
    }

    protected static function activityDescription(string $verb, $model): string
    {
        $type = class_basename($model);
        $label = static::activityLabel($model);
        return "{$type} #{$model->getKey()} {$verb}" . ($label ? " ({$label})" : '');
    }

    protected static function activityLabel($model): string
    {
        // Override in model if needed
        foreach (['numero', 'nom', 'libelle', 'reference', 'titre', 'name'] as $field) {
            if (!empty($model->{$field})) return (string) $model->{$field};
        }
        if (!empty($model->prenom) && !empty($model->nom)) {
            return $model->prenom . ' ' . $model->nom;
        }
        return '';
    }
}
