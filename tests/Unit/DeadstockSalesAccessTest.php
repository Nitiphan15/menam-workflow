<?php

namespace Tests\Unit;

use App\Models\FormWOS\DeadstockUserSalesAccess;
use App\Models\Users\User;
use App\Support\FormWOS\DeadstockSalesAccess;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DeadstockSalesAccessTest extends TestCase
{
    public function test_import_role_and_database_mapping_limit_user_to_mapped_salesperson(): void
    {
        $user = $this->user(['DS_IMPORT'], [$this->access('D1')]);

        $this->assertTrue(DeadstockSalesAccess::canImportAny($user));
        $this->assertTrue(DeadstockSalesAccess::canImport($user, 'Export Sales 01'));
        $this->assertTrue(DeadstockSalesAccess::canManage($user, 'คุณดิลก สอนแจ้ง'));
        $this->assertFalse(DeadstockSalesAccess::canImport($user, 'Export Sales 02'));
        $this->assertFalse(DeadstockSalesAccess::canManage($user, 'Export Sales 02'));
    }

    public function test_username_alone_is_not_granted_access(): void
    {
        $user = $this->user();
        $user->forceFill(['username' => 'dilok_s']);

        $this->assertFalse(DeadstockSalesAccess::canManageAny($user));
        $this->assertFalse(DeadstockSalesAccess::canImportAny($user));
    }

    public function test_import_all_role_bypasses_mapping_for_import_only(): void
    {
        $user = $this->user(['DS_IMPORT_ALL']);

        $this->assertTrue(DeadstockSalesAccess::canImportAll($user));
        $this->assertTrue(DeadstockSalesAccess::canImport($user, 'Export Sales 01'));
        $this->assertTrue(DeadstockSalesAccess::canImport($user, 'Procurement'));
        $this->assertFalse(DeadstockSalesAccess::hasFullAccess($user));
        $this->assertFalse(DeadstockSalesAccess::canManage($user, 'Export Sales 01'));
    }

    public function test_manage_all_role_can_manage_and_import_every_salesperson(): void
    {
        $user = $this->user(['DS_MANAGE_ALL']);

        $this->assertTrue(DeadstockSalesAccess::hasFullAccess($user));
        $this->assertTrue(DeadstockSalesAccess::canImportAll($user));
        $this->assertTrue(DeadstockSalesAccess::canManage($user, 'Sales Person 03'));
        $this->assertTrue(DeadstockSalesAccess::canImport($user, 'Procurement'));
    }

    public function test_adminweb_role_can_manage_every_salesperson(): void
    {
        $user = $this->user(['ADMINWEB']);

        $this->assertTrue(DeadstockSalesAccess::hasFullAccess($user));
        $this->assertTrue(DeadstockSalesAccess::canImportAll($user));
        $this->assertTrue(DeadstockSalesAccess::canManage($user, 'Sales Person 03'));
        $this->assertTrue(DeadstockSalesAccess::canImport($user, 'Procurement'));
    }

    private function user(array $roles = [], array $accesses = []): User
    {
        $user = new class($roles) extends User
        {
            public function __construct(private array $roleCodes = [])
            {
                parent::__construct();
            }

            public function hasRoleCode(string|array $codes, int $departmentId = null): bool
            {
                return array_intersect($this->roleCodes, (array) $codes) !== [];
            }
        };

        $user->forceFill(['id' => 100, 'username' => 'mapped_user']);
        $user->setRelation('deadstockSalesAccesses', new Collection($accesses));

        return $user;
    }

    private function access(string $salespersonKey, bool $canImport = true, bool $canEdit = true): DeadstockUserSalesAccess
    {
        return new DeadstockUserSalesAccess([
            'salesperson_key' => $salespersonKey,
            'can_import' => $canImport,
            'can_edit' => $canEdit,
            'is_active' => true,
        ]);
    }
}
