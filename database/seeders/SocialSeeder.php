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
            'picture' => '/images/icon-facebook.png',
            'url' => 'https://www.facebook.com',
            'status' => 1,
        ]);

        Social::create([
            'picture' => '/images/icon-instagram.png',
            'url' => 'https://www.instagram.com',
            'status' => 1,
        ]);

        Social::create([
            'picture' => '/images/icon-youtube.png',
            'url' => 'https://www.youtube.com',
            'status' => 1,
        ]);
    }
}
