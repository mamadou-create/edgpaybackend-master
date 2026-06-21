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
            ['model' => 'iPhone SE 2020', 'storage' => '128GB', 'base_price' => 130],
            ['model' => 'iPhone SE 2022', 'storage' => '64GB', 'base_price' => 170],
            ['model' => 'iPhone SE 2022', 'storage' => '128GB', 'base_price' => 195],
            ['model' => 'iPhone X', 'storage' => '64GB', 'base_price' => 120],
            ['model' => 'iPhone X', 'storage' => '256GB', 'base_price' => 145],
            ['model' => 'iPhone XR', 'storage' => '64GB', 'base_price' => 140],
            ['model' => 'iPhone XR', 'storage' => '128GB', 'base_price' => 155],
            ['model' => 'iPhone XS', 'storage' => '64GB', 'base_price' => 150],
            ['model' => 'iPhone XS', 'storage' => '256GB', 'base_price' => 175],
            ['model' => 'iPhone XS Max', 'storage' => '64GB', 'base_price' => 180],
            ['model' => 'iPhone XS Max', 'storage' => '256GB', 'base_price' => 210],
            ['model' => 'iPhone 11', 'storage' => '64GB', 'base_price' => 160],
            ['model' => 'iPhone 11', 'storage' => '128GB', 'base_price' => 180],
            ['model' => 'iPhone 11 Pro', 'storage' => '64GB', 'base_price' => 220],
            ['model' => 'iPhone 11 Pro', 'storage' => '256GB', 'base_price' => 250],
            ['model' => 'iPhone 11 Pro Max', 'storage' => '64GB', 'base_price' => 250],
            ['model' => 'iPhone 11 Pro Max', 'storage' => '256GB', 'base_price' => 285],
            ['model' => 'iPhone 12 mini', 'storage' => '64GB', 'base_price' => 210],
            ['model' => 'iPhone 12 mini', 'storage' => '128GB', 'base_price' => 230],
            ['model' => 'iPhone 12 mini', 'storage' => '256GB', 'base_price' => 255],
            ['model' => 'iPhone 12', 'storage' => '64GB', 'base_price' => 245],
            ['model' => 'iPhone 12', 'storage' => '128GB', 'base_price' => 270],
            ['model' => 'iPhone 12', 'storage' => '256GB', 'base_price' => 300],
            ['model' => 'iPhone 12 Pro', 'storage' => '128GB', 'base_price' => 330],
            ['model' => 'iPhone 12 Pro', 'storage' => '256GB', 'base_price' => 360],
            ['model' => 'iPhone 12 Pro Max', 'storage' => '128GB', 'base_price' => 380],
            ['model' => 'iPhone 12 Pro Max', 'storage' => '256GB', 'base_price' => 420],
            ['model' => 'iPhone 13 mini', 'storage' => '128GB', 'base_price' => 310],
            ['model' => 'iPhone 13', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'iPhone 13', 'storage' => '256GB', 'base_price' => 390],
            ['model' => 'iPhone 13 Pro', 'storage' => '128GB', 'base_price' => 430],
            ['model' => 'iPhone 13 Pro', 'storage' => '256GB', 'base_price' => 470],
            ['model' => 'iPhone 13 Pro Max', 'storage' => '128GB', 'base_price' => 500],
            ['model' => 'iPhone 13 Pro Max', 'storage' => '256GB', 'base_price' => 545],
            ['model' => 'iPhone 14', 'storage' => '128GB', 'base_price' => 450],
            ['model' => 'iPhone 14', 'storage' => '256GB', 'base_price' => 490],
            ['model' => 'iPhone 14 Plus', 'storage' => '128GB', 'base_price' => 500],
            ['model' => 'iPhone 14 Plus', 'storage' => '256GB', 'base_price' => 540],
            ['model' => 'iPhone 14 Pro', 'storage' => '128GB', 'base_price' => 560],
            ['model' => 'iPhone 14 Pro', 'storage' => '256GB', 'base_price' => 610],
            ['model' => 'iPhone 14 Pro Max', 'storage' => '128GB', 'base_price' => 650],
            ['model' => 'iPhone 14 Pro Max', 'storage' => '256GB', 'base_price' => 700],
            ['model' => 'iPhone 15', 'storage' => '128GB', 'base_price' => 600],
            ['model' => 'iPhone 15', 'storage' => '256GB', 'base_price' => 655],
            ['model' => 'iPhone 15 Plus', 'storage' => '128GB', 'base_price' => 680],
            ['model' => 'iPhone 15 Plus', 'storage' => '256GB', 'base_price' => 735],
            ['model' => 'iPhone 15 Pro', 'storage' => '128GB', 'base_price' => 820],
            ['model' => 'iPhone 15 Pro', 'storage' => '256GB', 'base_price' => 880],
            ['model' => 'iPhone 15 Pro', 'storage' => '512GB', 'base_price' => 960],
            ['model' => 'iPhone 15 Pro Max', 'storage' => '256GB', 'base_price' => 930],
            ['model' => 'iPhone 15 Pro Max', 'storage' => '512GB', 'base_price' => 1030],
            ['model' => 'iPhone 16', 'storage' => '128GB', 'base_price' => 760],
            ['model' => 'iPhone 16', 'storage' => '256GB', 'base_price' => 830],
            ['model' => 'iPhone 16 Plus', 'storage' => '128GB', 'base_price' => 860],
            ['model' => 'iPhone 16 Plus', 'storage' => '256GB', 'base_price' => 925],
            ['model' => 'iPhone 16 Pro', 'storage' => '256GB', 'base_price' => 1080],
            ['model' => 'iPhone 16 Pro', 'storage' => '512GB', 'base_price' => 1170],
            ['model' => 'iPhone 16 Pro Max', 'storage' => '256GB', 'base_price' => 1220],
            ['model' => 'iPhone 16 Pro Max', 'storage' => '512GB', 'base_price' => 1320],
            
            // Samsung Galaxy S Series
            ['model' => 'Samsung Galaxy S20', 'storage' => '128GB', 'base_price' => 220],
            ['model' => 'Samsung Galaxy S20', 'storage' => '256GB', 'base_price' => 250],
            ['model' => 'Samsung Galaxy S21', 'storage' => '128GB', 'base_price' => 250],
            ['model' => 'Samsung Galaxy S21', 'storage' => '256GB', 'base_price' => 290],
            ['model' => 'Samsung Galaxy S22', 'storage' => '128GB', 'base_price' => 280],
            ['model' => 'Samsung Galaxy S22', 'storage' => '256GB', 'base_price' => 320],
            ['model' => 'Samsung Galaxy S23', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'Samsung Galaxy S23', 'storage' => '256GB', 'base_price' => 400],
            ['model' => 'Samsung Galaxy S24', 'storage' => '128GB', 'base_price' => 450],
            ['model' => 'Samsung Galaxy S24', 'storage' => '256GB', 'base_price' => 510],
            ['model' => 'Samsung Galaxy S24 Ultra', 'storage' => '256GB', 'base_price' => 750],
            ['model' => 'Samsung Galaxy S24 Ultra', 'storage' => '512GB', 'base_price' => 850],
            ['model' => 'Samsung Galaxy A50', 'storage' => '128GB', 'base_price' => 130],
            ['model' => 'Samsung Galaxy A51', 'storage' => '128GB', 'base_price' => 150],
            ['model' => 'Samsung Galaxy A52', 'storage' => '128GB', 'base_price' => 170],
            ['model' => 'Samsung Galaxy A53', 'storage' => '128GB', 'base_price' => 200],
            
            // Xiaomi Redmi
            ['model' => 'Xiaomi Redmi Note 10', 'storage' => '128GB', 'base_price' => 140],
            ['model' => 'Xiaomi Redmi Note 11', 'storage' => '128GB', 'base_price' => 160],
            ['model' => 'Xiaomi Redmi Note 12', 'storage' => '128GB', 'base_price' => 190],
            ['model' => 'Xiaomi Redmi Note 13', 'storage' => '128GB', 'base_price' => 220],
            ['model' => 'Xiaomi 13', 'storage' => '256GB', 'base_price' => 380],
            ['model' => 'Xiaomi 14', 'storage' => '256GB', 'base_price' => 420],
            
            // Huawei
            ['model' => 'Huawei P40', 'storage' => '128GB', 'base_price' => 280],
            ['model' => 'Huawei P40', 'storage' => '256GB', 'base_price' => 320],
            ['model' => 'Huawei P50', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'Huawei P50', 'storage' => '256GB', 'base_price' => 400],
            ['model' => 'Huawei Mate 50', 'storage' => '256GB', 'base_price' => 480],
            
            // OnePlus
            ['model' => 'OnePlus 9', 'storage' => '128GB', 'base_price' => 190],
            ['model' => 'OnePlus 9', 'storage' => '256GB', 'base_price' => 220],
            ['model' => 'OnePlus 10', 'storage' => '128GB', 'base_price' => 240],
            ['model' => 'OnePlus 10', 'storage' => '256GB', 'base_price' => 280],
            ['model' => 'OnePlus 12', 'storage' => '256GB', 'base_price' => 420],
            
            // Google Pixel
            ['model' => 'Google Pixel 6', 'storage' => '128GB', 'base_price' => 180],
            ['model' => 'Google Pixel 7', 'storage' => '128GB', 'base_price' => 240],
            ['model' => 'Google Pixel 8', 'storage' => '128GB', 'base_price' => 350],
            ['model' => 'Google Pixel 8 Pro', 'storage' => '128GB', 'base_price' => 450],
            
            // Realme
            ['model' => 'Realme 8', 'storage' => '128GB', 'base_price' => 110],
            ['model' => 'Realme 9', 'storage' => '128GB', 'base_price' => 130],
            ['model' => 'Realme 10', 'storage' => '128GB', 'base_price' => 150],
            ['model' => 'Realme GT', 'storage' => '128GB', 'base_price' => 280],
            
            // Motorola
            ['model' => 'Motorola G20', 'storage' => '64GB', 'base_price' => 90],
            ['model' => 'Motorola G30', 'storage' => '128GB', 'base_price' => 110],
            ['model' => 'Motorola G40', 'storage' => '128GB', 'base_price' => 140],
            ['model' => 'Motorola Edge 20', 'storage' => '128GB', 'base_price' => 220],
            
            // Honor
            ['model' => 'Honor 50', 'storage' => '256GB', 'base_price' => 320],
            ['model' => 'Honor 70', 'storage' => '256GB', 'base_price' => 420],
            
            // Nothing
            ['model' => 'Nothing Phone 1', 'storage' => '128GB', 'base_price' => 280],
            ['model' => 'Nothing Phone 2', 'storage' => '128GB', 'base_price' => 350],
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