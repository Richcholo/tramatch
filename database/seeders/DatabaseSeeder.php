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

            /*
             * PLACES LAST, AND IT DEPENDS ON NOTHING ABOVE.
             *
             * `places.csv` ships with a header and no rows, so this is a no-op on
             * a fresh clone -- which is the correct state, not a missing file. A
             * catalogue of restaurants and hotels is hand-sourced one cited page
             * at a time, and inventing rows to fill the table would defeat the
             * only thing this catalogue is for.
             *
             * Places carry their own `source_url` column rather than a
             * separate sources table, because a restaurant is not a
             * destination. See AGENTS.md.
             *
             * It prints cluster readiness per budget tier when it runs, because
             * "is this cluster big enough to plan a trip in" is the question the
             * panel asked and a seeder that only prints a count hides it.
             */
            PlacesCsvSeeder::class,
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