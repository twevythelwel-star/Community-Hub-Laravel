<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the database with the exact records the original front end held as
 * module-level mock arrays, so every screen looks the same on first run.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CommunitySeeder::class,   // must precede boundary/branding/landmarks
            UserSeeder::class,        // must precede anything with an author or owner
            SecurityAndAdminRosterSeeder::class, // 10 Security officers, 11 Admins who own homes
            SecuritySeeder::class,
            DirectorySeeder::class,
            CommunicationsSeeder::class,
            CommerceSeeder::class,
            RevenueEngineSeeder::class,
            CompanyPropertySeeder::class,
            RentalPortfolioSeeder::class,
        ]);
    }
}
