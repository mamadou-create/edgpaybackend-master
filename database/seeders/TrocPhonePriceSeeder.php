<?php

namespace Database\Seeders;

use App\Models\TrocPhonePrice;
use Illuminate\Database\Seeder;

class TrocPhonePriceSeeder extends Seeder
{
    public function run(): void
    {
        $prices = [
            ['model' => 'iPhone SE 2020', 'storage' => '64GB', 'base_price' => 110],
            ['model' => 'iPhone X', 'storage' => '64GB', 'base_price' => 120],
            ['model' => 'iPhone XR', 'storage' => '64GB', 'base_price' => 140],
            ['model' => 'iPhone XS', 'storage' => '64GB', 'base_price' => 150],
            ['model' => 'iPhone XS Max', 'storage' => '64GB', 'base_price' => 180],
            ['model' => 'iPhone 11', 'storage' => '64GB', 'base_price' => 160],
            ['model' => 'iPhone 11 Pro', 'storage' => '64GB', 'base_price' => 220],
            ['model' => 'iPhone 11 Pro Max', 'storage' => '64GB', 'base_price' => 250],
            ['model' => 'iPhone 12 mini', 'storage' => '128GB', 'base_price' => 230],
            ['model' => 'iPhone 12', 'storage' => '128GB', 'base_price' => 270],
            ['model' => 'iPhone 12 Pro', 'storage' => '128GB', 'base_price' => 330],
            ['model' => 'iPhone 12 Pro Max', 'storage' => '128GB', 'base_price' => 380],
            ['model' => 'iPhone 13 mini', 'storage' => '128GB', 'base_price' => 310],
            ['model' => 'iPhone 13', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'iPhone 13 Pro', 'storage' => '128GB', 'base_price' => 430],
            ['model' => 'iPhone 13 Pro Max', 'storage' => '128GB', 'base_price' => 500],
            ['model' => 'iPhone 14', 'storage' => '128GB', 'base_price' => 450],
            ['model' => 'iPhone 14 Plus', 'storage' => '128GB', 'base_price' => 500],
            ['model' => 'iPhone 14 Pro', 'storage' => '128GB', 'base_price' => 560],
            ['model' => 'iPhone 14 Pro Max', 'storage' => '128GB', 'base_price' => 650],
            ['model' => 'iPhone 15', 'storage' => '128GB', 'base_price' => 600],
            ['model' => 'iPhone 15 Plus', 'storage' => '128GB', 'base_price' => 680],
            ['model' => 'iPhone 15 Pro', 'storage' => '128GB', 'base_price' => 820],
            ['model' => 'iPhone 15 Pro Max', 'storage' => '256GB', 'base_price' => 930],
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