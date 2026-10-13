<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TagSeeder::class,
            LuzonLocationsCsvSeeder::class,
            DestinationSourceSeeder::class,
        ]);

        /*
         * BETA USERS LAST, AND NOT BY DEFAULT.
         *
         * They are opt-in: `db:seed` with no argument is what someone runs to
         * get a working catalogue, and silently adding 25 accounts with a
         * shared password to that is the wrong default. A beta group needs them,
         * so ask for them by name:
         *
         *     php artisan db:seed --class=BetaUserSeeder
         *
         * It must run after the catalogue and the tags, which it depends on for
         * every user it creates.
         */
    }
}