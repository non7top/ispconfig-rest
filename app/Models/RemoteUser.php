<?php

namespace App\Models;

use App\Casts\CsvArray;
use App\Casts\YesNoBoolean;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * remote_user - an account for ISPConfig's LEGACY remote/SOAP API
 * (interface/web/remote/index.php), completely separate from this REST
 * app's own auth (ApiKey/api_keys) and from the REST app's own endpoint
 * inventory: remote/index.php's remoting_lib exposes ~140 functions
 * (client/dns/mail/sites/openvz CRUD) that this REST API does not wrap and
 * has no plan to. Adding this resource does not change how anything
 * authenticates against THIS API - it lets this API manage accounts for
 * the OTHER one.
 *
 * Column set (sys_userid, sys_groupid, sys_perm_*, remote_username,
 * remote_password, remote_access, remote_ips, remote_functions) taken from
 * wr-salt-new's ispconfig3/server_roles.sls (ispconfig_remote_api_user),
 * itself checked against two live ISPConfig 3.3.1 nodes' real remote_user
 * rows - NOT independently verified here against
 * source_code/install/sql/ispconfig3.sql (untracked, not present in this
 * checkout). Flag if the real DDL disagrees.
 *
 * Datalog note (documented Principle II superset, same shape as
 * DnsSslCa/spec 008): remote/index.php's login() reads this table directly
 * at request time; no server daemon polls sys_datalog for it. Routing
 * writes through BaseModel is a harmless superset adopted for auditability,
 * not something legacy itself does.
 *
 * remote_username uniqueness is enforced by this API (409, in the
 * controller) as a sane invariant - remote/index.php's login() looks a
 * caller up by exact username - but is not confirmed as a DB UNIQUE KEY
 * (same caveat as the column set above).
 */
class RemoteUser extends BaseModel
{
    /**
     * @var string
     */
    protected $table = 'remote_user';

    /**
     * @var string
     */
    protected $primaryKey = 'remote_userid';

    /**
     * remote_password is accepted write-only via the FormRequest (hashed
     * there with LegacyCrypt::passwordHash before it ever reaches the
     * model) - never mass-assigned as cleartext.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'remote_username',
        'remote_password',
        'remote_access',
        'remote_ips',
        'remote_functions',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'remote_access' => YesNoBoolean::class,
        'remote_ips' => CsvArray::class,
        'remote_functions' => CsvArray::class,
    ];

    /**
     * sys_* fields are ISPConfig's own internal ownership bookkeeping for
     * this account (always sys_userid=1/sys_groupid=1 - admin - on every
     * row this fleet has ever created), not something a REST consumer
     * manages; the raw pk is replaced by the appended `id`; the hash never
     * leaves the API at all. Same shape as DnsSslCa (interface-only,
     * admin-managed, no per-row client scoping that would need sys_* to be
     * visible).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remote_userid',
        'remote_password',
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sys_perm_user' => 'riud',
        'sys_perm_group' => 'riud',
        'sys_perm_other' => '',
        'remote_access' => 'n',
        'remote_ips' => '',
        'remote_functions' => '',
    ];

    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }
}
