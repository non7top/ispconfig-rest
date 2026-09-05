<?php

namespace App\Http\Requests;

/**
 * POST /system/remote-users (api/modules/system/remote-users.yaml):
 * remote_username and remote_password are required; remote_access/
 * remote_ips/remote_functions default to the DB's 'n'/empty.
 */
class StoreRemoteUserRequest extends RemoteUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->sharedRules(), [
            'remote_username' => ['required', 'string', 'max:255'],
            'remote_password' => ['required', 'string', 'min:8'],
        ]);
    }
}
