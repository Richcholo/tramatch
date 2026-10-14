<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Committed data files
    |--------------------------------------------------------------------------
    |
    | Where the catalogue CSVs live. Only `places_csv` is overridable, and only so
    | `PlacesCsvSeederTest` can point the seeder at a temporary file: a test that
    | writes the real `places.csv` and restores it in tearDown() corrupts the
    | repository whenever a test fatals before tearDown runs, which is exactly how
    | a fabricated row called "Merienda Place" ended up in the catalogue file.
    |
    | `destinations_csv` is NOT here. `LuzonLocationsCsvSeeder` is a seeded
    | catalogue with 65 rows that several tests read and one that rewrites, and
    | moving its path is a change to a file that has worked for months without
    | evidence that it needs one. Do not add it for symmetry.
    |
    */

    'places_csv' => env('PLACES_CSV_PATH', database_path('data/places.csv')),

];