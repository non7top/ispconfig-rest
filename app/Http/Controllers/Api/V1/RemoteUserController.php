<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRemoteUserRequest;
use App\Http\Requests\UpdateRemoteUserRequest;
use App\Models\RemoteUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Legacy remote-API users (contract: api/modules/system/remote-users.yaml)
 * - accounts for ISPConfig's OTHER (legacy SOAP, interface/web/remote/)
 * API, over the `remote_user` table. See App\Models\RemoteUser for why this
 * is a distinct concern from this app's own ApiKey auth.
 *
 * Admin-only (routes/api/system.php, scope.admin), same as every other
 * system-module resource.
 */
class RemoteUserController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /system/remote-users.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            RemoteUser::query(),
            $request,
            sortable: ['remote_userid', 'remote_username', 'remote_access'],
            defaultSort: 'remote_username',
            filters: [
                'remote_access' => 'boolean',
            ],
        );

        return response()->json($result);
    }

    /**
     * GET /system/remote-users/{id}.
     */
    public function show(RemoteUser $remoteUser): JsonResponse
    {
        return response()->json($remoteUser);
    }

    /**
     * POST /system/remote-users - 201; datalog `i`. Duplicate
     * remote_username is 409 (see RemoteUser for why this is an
     * API-level invariant rather than a confirmed DB UNIQUE KEY).
     */
    public function store(StoreRemoteUserRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->assertUniqueUsername($payload['remote_username']);

        $user = new RemoteUser($payload);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /system/remote-users/{id} - 200; datalog `u`. Keys absent from
     * the request are left unchanged; an empty remote_password keeps the
     * stored hash (RemoteUserRequest::payload). This is the endpoint
     * wr-salt-new's fwgen-fix (#85) parked pending: adding a node's own
     * hairpin IP to remote_ips goes through here instead of raw SQL once
     * this ships.
     */
    public function update(UpdateRemoteUserRequest $request, RemoteUser $remoteUser): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('remote_username', $payload)) {
            $this->assertUniqueUsername($payload['remote_username'], (int) $remoteUser->getKey());
        }

        $remoteUser->fill($payload);

        DB::transaction(function () use ($remoteUser): void {
            $remoteUser->save();
        });

        return response()->json($remoteUser->refresh());
    }

    /**
     * DELETE /system/remote-users/{id} - 204; datalog `d`.
     */
    public function destroy(RemoteUser $remoteUser): Response
    {
        DB::transaction(function () use ($remoteUser): void {
            $remoteUser->delete();
        });

        return response()->noContent();
    }

    protected function assertUniqueUsername(string $username, ?int $excludeId = null): void
    {
        $exists = DB::table('remote_user')
            ->where('remote_username', $username)
            ->when($excludeId !== null, fn ($q) => $q->where('remote_userid', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw new ConflictHttpException("A remote user with remote_username '{$username}' already exists.");
        }
    }
}
