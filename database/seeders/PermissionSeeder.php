<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mirrors HelpSpot's permission groups using spatie/laravel-permission.
 *
 * Two roles:
 *  - Administrator: every helpdesk permission.
 *  - Help Desk Staff: no manage-* permissions. Staff can still work the
 *    request queue — in HelpSpot, queue actions are gated by *membership*
 *    (being staff at all), not by a permission, so request actions check
 *    `auth()->check()` style membership rather than `can()`.
 */
class PermissionSeeder extends Seeder
{
    /**
     * The full helpdesk permission list. A constant so tests and future
     * phases reference one source of truth instead of re-typing strings.
     *
     * @var list<string>
     */
    public const array PERMISSIONS = [
        'manage categories',
        'manage knowledge base',
        'manage automation',
        'view reports',
        'manage staff',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Spatie caches the permission table aggressively (every can()
        // check hits the cache). Reset it before seeding so freshly
        // created permissions are visible immediately — without this,
        // role checks right after seeding can fail against a stale cache.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // firstOrCreate makes the seeder idempotent: re-running it finds
        // the existing rows instead of violating unique constraints.
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $administrator = Role::firstOrCreate(['name' => 'Administrator']);
        $administrator->syncPermissions(self::PERMISSIONS);

        // Created with zero permissions on purpose — see class docblock.
        Role::firstOrCreate(['name' => 'Help Desk Staff']);
    }
}
