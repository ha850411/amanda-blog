<?php

namespace Database\Seeders;

use App\Models\Social;
use Illuminate\Database\Seeder;

class SocialSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Social::create([
            'icon' => 'fa-brands fa-facebook',
            'url' => 'https://www.facebook.com',
            'status' => 1,
        ]);

        Social::create([
            'icon' => 'fa-brands fa-instagram',
            'url' => 'https://www.instagram.com',
            'status' => 1,
        ]);

        Social::create([
            'icon' => 'fa-brands fa-youtube',
            'url' => 'https://www.youtube.com',
            'status' => 1,
        ]);
    }
}
