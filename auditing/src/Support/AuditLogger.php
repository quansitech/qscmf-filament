<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * 手写自定义审计事件：用于 pivot sync 等不触发 Eloquent 模型事件的操作。
 * 记录进同一张 audits 表，在审计页与模型 CRUD 记录统一可见。
 *
 * @param  Model&Auditable  $model
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function custom(Model $model, string $event, array $oldValues, array $newValues): void
    {
        if (! $model instanceof Auditable) {
            return;
        }

        if ($oldValues === $newValues) {
            return;
        }

        $model->auditCustomOld = $oldValues;
        $model->auditCustomNew = $newValues;
        $model->auditEvent = $event;
        $model->isCustomEvent = true;

        Event::dispatch(new AuditCustom($model));

        $model->auditCustomOld = null;
        $model->auditCustomNew = null;
        $model->auditEvent = null;
        $model->isCustomEvent = false;
    }
}
