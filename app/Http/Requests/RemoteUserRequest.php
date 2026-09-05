<?php

namespace App\Http\Requests;

use App\Support\LegacyCrypt;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behavior for legacy remote-API user (remote_user) writes
 * (contract: api/components/schemas/RemoteUser.yaml).
 *
 * remote_access accepts booleans or legacy y/n strings, same normalization
 * DnsCaRequest already applies to its own y/n-backed flags.
 */
abstract class RemoteUserRequest extends FormRequest
{
    /**
     * Authentication happens in the api.key / scope.admin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('remote_access') && is_string($this->input('remote_access'))) {
            $value = filter_var($this->input('remote_access'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value === null) {
                $value = match (strtolower($this->input('remote_access'))) {
                    'y' => true,
                    'n' => false,
                    default => $this->input('remote_access'), // left invalid -> boolean rule fails
                };
            }

            $this->merge(['remote_access' => $value]);
        }
    }

    /**
     * Validated data ready for RemoteUser::fill(): remote_ips/
     * remote_functions arrive as arrays (the model's CsvArray cast accepts
     * an array on set() directly, no join needed here); a present
     * remote_password is hashed here so the model never sees or stores a
     * cleartext value.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('remote_password', $data)) {
            if ($data['remote_password'] === null || $data['remote_password'] === '') {
                unset($data['remote_password']); // update: empty input keeps the stored hash
            } else {
                $data['remote_password'] = LegacyCrypt::passwordHash($data['remote_password']);
            }
        }

        return $data;
    }

    /**
     * Shared field rules both Store and Update apply, only the
     * required/sometimes wrapper differs.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sharedRules(): array
    {
        return [
            'remote_access' => ['sometimes', 'boolean'],
            'remote_ips' => ['sometimes', 'array'],
            'remote_ips.*' => ['ip'],
            'remote_functions' => ['sometimes', 'array'],
            'remote_functions.*' => ['string', 'regex:/^[a-z][a-z0-9_]*$/'],
        ];
    }
}
