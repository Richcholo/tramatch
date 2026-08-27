<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Beach',
            'Mountain',
            'Nature',
            'Historical',
            'Cultural',
            'Adventure',
            'Food and Dining',
            'Shopping',
            'City',
            'Countryside',
            'Family-Friendly',
            'Romantic',
            'Budget-Friendly',
            'Relaxation',
            'Photography',
            'Camping',
            'Water Activities',
        ];

        foreach ($names as $name) {
            Tag::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}