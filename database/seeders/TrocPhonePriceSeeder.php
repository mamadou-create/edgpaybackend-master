<?php

namespace Database\Seeders;

use App\Models\TrocPhonePrice;
use Illuminate\Database\Seeder;

class TrocPhonePriceSeeder extends Seeder
{
    public function run(): void
    {
        $prices = [
            ['model' => 'iPhone 11', 'storage' => '64GB', 'base_price' => 160],
            ['model' => 'iPhone 12', 'storage' => '128GB', 'base_price' => 270],
            ['model' => 'iPhone 13', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'iPhone 14', 'storage' => '128GB', 'base_price' => 450],
            ['model' => 'iPhone 15', 'storage' => '128GB', 'base_price' => 600],
        ];

        foreach ($prices as $price) {
            TrocPhonePrice::query()->updateOrCreate(
                [
                    'model' => $price['model'],
                    'storage' => $price['storage'],
                ],
                ['base_price' => $price['base_price']],
            );
        }
    }
}