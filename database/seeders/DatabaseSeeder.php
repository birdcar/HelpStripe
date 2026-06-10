<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed` / `migrate:fresh --seed`.
 *
 * Deliberately does NOT use the WithoutModelEvents trait the starter kit
 * shipped with: Model::withoutEvents() applies to every seeder called
 * from inside run(), and DemoSeeder depends on the Request model's
 * `creating` event to generate access keys.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
