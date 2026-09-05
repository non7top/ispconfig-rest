<?php

namespace App\Http\Requests;

/**
 * PUT /system/remote-users/{id} (api/modules/system/remote-users.yaml).
 * Keys absent from the request are left unchanged; an empty/absent
 * remote_password keeps the stored hash (RemoteUserRequest::payload).
 */
class UpdateRemoteUserRequest extends RemoteUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->sharedRules(), [
            'remote_username' => ['sometimes', 'filled', 'string', 'max:255'],
            'remote_password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);
    }
}
