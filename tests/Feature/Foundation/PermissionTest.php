<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('seeding creates both helpspot permission groups', function () {
    expect(Role::query()->pluck('name')->all())
        ->toContain('Administrator', 'Help Desk Staff');
});

test('an administrator has every manage permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    foreach (PermissionSeeder::PERMISSIONS as $permission) {
        expect($admin->can($permission))->toBeTrue("Administrator should have '{$permission}'");
    }
});

test('help desk staff lack manage permissions', function () {
    $staff = User::factory()->create();
    $staff->assignRole('Help Desk Staff');

    expect($staff->can('manage automation'))->toBeFalse()
        ->and($staff->can('manage categories'))->toBeFalse()
        ->and($staff->can('manage staff'))->toBeFalse()
        ->and($staff->hasRole('Help Desk Staff'))->toBeTrue();
});

test('the permission seeder is idempotent', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    expect(Role::query()->where('name', 'Administrator')->count())->toBe(1);
});
