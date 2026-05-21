<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Users\User;
use Illuminate\Pagination\Paginator;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {

        Paginator::useBootstrapFive();
        $this->registerPolicies();
        // (ออปชัน) super admin ผ่านทุกอย่าง
        Gate::before(fn(User $u, string $ability) => ($u->is_superadmin ?? false) ? true : null);

        // ===== Ability ระดับระบบ (ไม่ขึ้นกับแผนก) =====
        Gate::define('ADMINWEB', fn(User $u) => $u->hasRoleCode(['ADMINWEB']));
        Gate::define('PRADMIN',  fn(User $u) => $u->hasRoleCode(['PR_ADMIN']));
        Gate::define('PR',       fn(User $u) => $u->hasRoleCode(['PR']));
        Gate::define('PA',       fn(User $u) => $u->hasRoleCode(['PA']));
        Gate::define('PAADMIN',       fn(User $u) => $u->hasRoleCode(['PAADMIN']));
        Gate::define('PAHR',       fn(User $u) => $u->hasRoleCode(['PAHR']));
        Gate::define('PP',       fn(User $u) => $u->hasRoleCode(['PP']));
        Gate::define('WLM',       fn(User $u) => $u->hasRoleCode(['WLM']));
        Gate::define('WOCR',      fn(User $u) => $u->hasRoleCode(['WOCR']));
        Gate::define('WR',      fn(User $u) => $u->hasRoleCode(['WR']));
        Gate::define('EXAM',      fn(User $u) => $u->hasRoleCode(['EXAM']));
        Gate::define('DP',      fn(User $u) => $u->hasRoleCode(['DP']));
        Gate::define('DPA',      fn(User $u) => $u->hasRoleCode(['DPA']));
        Gate::define('DPEMAIL',      fn(User $u) => $u->hasRoleCode(['DPEMAIL', 'DPMAIL']));
        Gate::define('ISR',      fn(User $u) => $u->hasRoleCode(['ISR']));
        Gate::define('D1',      fn(User $u) => $u->hasRoleCode(['D1']));
        Gate::define('D2',      fn(User $u) => $u->hasRoleCode(['D2']));
        Gate::define('D3',      fn(User $u) => $u->hasRoleCode(['D3']));
        Gate::define('D4',      fn(User $u) => $u->hasRoleCode(['D4']));
        Gate::define('D5',      fn(User $u) => $u->hasRoleCode(['D5']));
        Gate::define('D6',      fn(User $u) => $u->hasRoleCode(['D6']));
        Gate::define('D7',      fn(User $u) => $u->hasRoleCode(['D7']));
        Gate::define('D8',      fn(User $u) => $u->hasRoleCode(['D8']));
        Gate::define('D9',      fn(User $u) => $u->hasRoleCode(['D9']));
        Gate::define('FC',      fn(User $u) => $u->hasRoleCode(['FC']));
        Gate::define('FC_PLN',      fn(User $u) => $u->hasRoleCode(['FC_PLN']));
        Gate::define('FCM', fn(User $u) => $u->hasRoleCode(['FCM']));
        Gate::define('FCAPPROVE', fn(User $u) => $u->hasRoleCode(['FCAPPROVE']));
        Gate::define('PO',      fn(User $u) => $u->hasRoleCode(['PO']));
        Gate::define('POPUR',   fn(User $u) => $u->hasRoleCode(['POPUR']));
        // ===== Ability แบบมี context แผนก (ผ่านพารามิเตอร์) =====
        // ใช้: Gate::forUser($u)->check('role-in-dept', ['codes'=>['PR_ADMIN'],'dept_id'=>2])
        Gate::define('role-in-dept', function (User $u, array $args) {
            $codes  = $args['codes'] ?? [];
            $deptId = $args['dept_id'] ?? null;
            if (!$deptId || empty($codes)) return false;
            return $u->hasRoleCode($codes, (int)$deptId);
        });
    }
}
