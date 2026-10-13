<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// `php artisan db:seed`-ийн үндсэн seeder.
class DatabaseSeeder extends Seeder
{
    // Бусад seeder-үүдийг дуудна (одоогоор зөвхөн багцууд).
    public function run(): void
    {
        $this->call(PlanSeeder::class);
    }
}
