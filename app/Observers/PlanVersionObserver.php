<?php

namespace App\Observers;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PlanVersionObserver
{
    public function creating(Model $model): void
    {
        $model->plan_version ??= 1;
    }

    public function updating(Model $model): void
    {
        if (array_diff(array_keys($model->getDirty()), ['plan_version', 'updated_at']) !== []) {
            $model->plan_version = ((int) $model->getOriginal('plan_version')) + 1;
        }
    }

    public function created(Model $model): void
    {
        $this->bumpParent($model);
    }

    public function updated(Model $model): void
    {
        $this->bumpParent($model);
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Project) {
            DB::table('projects')->where('id', $model->getKey())->increment('plan_version');

            return;
        }

        $this->bumpParent($model);
    }

    public function restored(Model $model): void
    {
        $this->bumpParent($model);
    }

    private function bumpParent(Model $model): void
    {
        if (! $model instanceof Project && $model->getAttribute('project_id')) {
            DB::table('projects')->where('id', $model->getAttribute('project_id'))->increment('plan_version');
        }
    }
}
