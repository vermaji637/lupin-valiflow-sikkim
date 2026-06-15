<?php

// app/Observers/AuditObserver.php

namespace App\Observers;

use App\Services\AuditLogger;

class AuditObserver
{
    public function created($model)
    {
        $this->log('created', $model, null, $this->filterSensitiveFields($model->toArray()));
    }

    public function updated($model)
    {
        $this->log(
            'updated',
            $model,
            $this->filterSensitiveFields($model->getOriginal()),
            $this->filterSensitiveFields($model->getChanges())
        );
    }

    public function deleted($model)
    {
        $this->log('deleted', $model, $this->filterSensitiveFields($model->toArray()), null);
    }

    protected function log($event, $model, $old, $new)
    {
        AuditLogger::logModel($event, $model, $old, $new);
    }

    /**
     * Filter out sensitive fields from audit data
     */
    protected function filterSensitiveFields($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $passwordIgnoredFields = [
            'password',
            'remember_token',
            'password_setup_token',
            'password_setup_token_created_at',
            'password_set',
            'password_setup_used_at',
            'password_setup_expires_at',
        ];

        return array_diff_key($data, array_flip($passwordIgnoredFields));
    }
}
