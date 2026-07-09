<?php

namespace Database\Seeders;

use App\Models\TrocCarPrice;
use Illuminate\Database\Seeder;

class TrocCarPriceSeeder extends Seeder
{
    public function run(): void
    {
        // base_price = prix_GNF / taux_de_reference (8 700 GNF = 1 unité de référence)
        // Les prix ci-dessous reflètent le marché guinéen (parc guinéen, occasion importée).
        $rate = (int) config('troc.reference_to_gnf_rate', 8700);
        $gnf  = fn (int $montant): float => round($montant / $rate, 2);

        $prices = [
            // ——— Toyota ————————————————————————————————————
            // Corolla : berline la plus répandue en Guinée
            ['brand' => 'Toyota', 'model' => 'Corolla',   'year' => 2015, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(70_000_000)],  //  ~70 M GNF
            ['brand' => 'Toyota', 'model' => 'Corolla',   'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(86_000_000)],  //  ~86 M GNF
            ['brand' => 'Toyota', 'model' => 'RAV4',      'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(108_000_000)], // ~108 M GNF
            ['brand' => 'Toyota', 'model' => 'RAV4',      'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(140_000_000)], // ~140 M GNF

            // ——— Hyundai ————————————————————————————————————
            ['brand' => 'Hyundai', 'model' => 'Elantra',  'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(60_000_000)],  //  ~60 M GNF
            ['brand' => 'Hyundai', 'model' => 'Tucson',   'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(102_000_000)], // ~102 M GNF

            // ——— Kia ————————————————————————————————————————
            ['brand' => 'Kia', 'model' => 'Sportage',     'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(95_000_000)],  //  ~95 M GNF
            ['brand' => 'Kia', 'model' => 'Sportage',     'year' => 2020, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(122_000_000)], // ~122 M GNF

            // ——— Nissan ————————————————————————————————————
            ['brand' => 'Nissan', 'model' => 'Qashqai',   'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(80_000_000)],  //  ~80 M GNF
            ['brand' => 'Nissan', 'model' => 'X-Trail',   'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(112_000_000)], // ~112 M GNF

            // ——— Honda ————————————————————————————————————
            ['brand' => 'Honda', 'model' => 'Civic',      'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(68_000_000)],  //  ~68 M GNF
            ['brand' => 'Honda', 'model' => 'CR-V',       'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(128_000_000)], // ~128 M GNF

            // ——— Mazda ————————————————————————————————————
            ['brand' => 'Mazda', 'model' => 'CX-5',       'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(113_000_000)], // ~113 M GNF

            // ——— Mitsubishi ————————————————————————————————
            ['brand' => 'Mitsubishi', 'model' => 'Outlander', 'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(106_000_000)], // ~106 M GNF

            // ——— Ford ————————————————————————————————————
            ['brand' => 'Ford', 'model' => 'Escape',      'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(96_000_000)],  //  ~96 M GNF

            // ——— Volkswagen ————————————————————————————————
            ['brand' => 'Volkswagen', 'model' => 'Tiguan', 'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(118_000_000)], // ~118 M GNF

            // ——— Peugeot ————————————————————————————————————
            ['brand' => 'Peugeot', 'model' => '3008',     'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(121_000_000)], // ~121 M GNF

            // ——— Premium berlines / SUV compacts ————————————————
            ['brand' => 'Mercedes-Benz', 'model' => 'C200',         'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(156_000_000)], // ~156 M GNF
            ['brand' => 'BMW',           'model' => 'X3',            'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(182_000_000)], // ~182 M GNF
            ['brand' => 'Audi',          'model' => 'Q5',            'year' => 2018, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(202_000_000)], // ~202 M GNF

            // ════════════════════════════════════════════════════════════
            // 4×4 / GROS PORTEURS — Parc guinéen
            // ════════════════════════════════════════════════════════════

            // ——— Toyota Land Cruiser 200 (V8 — roi du parc guinéen) ———
            ['brand' => 'Toyota', 'model' => 'Land Cruiser 200',  'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(420_000_000)], // ~420 M GNF
            ['brand' => 'Toyota', 'model' => 'Land Cruiser 200',  'year' => 2018, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(520_000_000)], // ~520 M GNF
            ['brand' => 'Toyota', 'model' => 'Land Cruiser 200',  'year' => 2021, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(700_000_000)], // ~700 M GNF

            // ——— Toyota Land Cruiser Prado (très répandu) ————————————
            ['brand' => 'Toyota', 'model' => 'Land Cruiser Prado', 'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(250_000_000)], // ~250 M GNF
            ['brand' => 'Toyota', 'model' => 'Land Cruiser Prado', 'year' => 2019, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(310_000_000)], // ~310 M GNF

            // ——— Toyota Hilux (pickup double cabine, très utilisé) ———
            ['brand' => 'Toyota', 'model' => 'Hilux',             'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(180_000_000)], // ~180 M GNF
            ['brand' => 'Toyota', 'model' => 'Hilux',             'year' => 2019, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(230_000_000)], // ~230 M GNF

            // ——— Toyota Fortuner (SUV 7 places, populaire) ——————————
            ['brand' => 'Toyota', 'model' => 'Fortuner',          'year' => 2017, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(210_000_000)], // ~210 M GNF
            ['brand' => 'Toyota', 'model' => 'Fortuner',          'year' => 2020, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(270_000_000)], // ~270 M GNF

            // ——— Toyota Sequoia (grand SUV V8) ——————————————————————
            ['brand' => 'Toyota', 'model' => 'Sequoia',           'year' => 2017, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(380_000_000)], // ~380 M GNF

            // ——— Nissan Patrol (concurrent direct du Land Cruiser) ———
            ['brand' => 'Nissan', 'model' => 'Patrol',            'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(280_000_000)], // ~280 M GNF
            ['brand' => 'Nissan', 'model' => 'Patrol',            'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(380_000_000)], // ~380 M GNF

            // ——— Nissan Navara (pickup populaire) ———————————————————
            ['brand' => 'Nissan', 'model' => 'Navara',            'year' => 2017, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(175_000_000)], // ~175 M GNF

            // ——— Mitsubishi Pajero (4x4 classique très présent) ——————
            ['brand' => 'Mitsubishi', 'model' => 'Pajero',        'year' => 2015, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(195_000_000)], // ~195 M GNF
            ['brand' => 'Mitsubishi', 'model' => 'Pajero',        'year' => 2018, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(250_000_000)], // ~250 M GNF

            // ——— Mitsubishi L200 / Triton (pickup) ———————————————————
            ['brand' => 'Mitsubishi', 'model' => 'L200 Triton',   'year' => 2017, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(175_000_000)], // ~175 M GNF

            // ——— Ford Explorer (SUV américain 7 places) ——————————————
            ['brand' => 'Ford', 'model' => 'Explorer',            'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(205_000_000)], // ~205 M GNF
            ['brand' => 'Ford', 'model' => 'Explorer',            'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(270_000_000)], // ~270 M GNF

            // ——— Jeep Grand Cherokee ————————————————————————————————
            ['brand' => 'Jeep', 'model' => 'Grand Cherokee',      'year' => 2016, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(240_000_000)], // ~240 M GNF
            ['brand' => 'Jeep', 'model' => 'Grand Cherokee',      'year' => 2019, 'fuel' => 'ESSENCE', 'transmission' => 'AUTO', 'base_price' => $gnf(310_000_000)], // ~310 M GNF

            // ——— Land Rover Discovery (présent chez les entreprises) —
            ['brand' => 'Land Rover', 'model' => 'Discovery 4',   'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(330_000_000)], // ~330 M GNF
            ['brand' => 'Land Rover', 'model' => 'Discovery 5',   'year' => 2018, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(420_000_000)], // ~420 M GNF

            // ——— Land Rover Range Rover Sport ——————————————————————
            ['brand' => 'Land Rover', 'model' => 'Range Rover Sport', 'year' => 2017, 'fuel' => 'DIESEL', 'transmission' => 'AUTO', 'base_price' => $gnf(430_000_000)], // ~430 M GNF

            // ——— Mercedes-Benz GLE / ML (présent à Conakry) ————————
            ['brand' => 'Mercedes-Benz', 'model' => 'GLE 350',    'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(260_000_000)], // ~260 M GNF
            ['brand' => 'Mercedes-Benz', 'model' => 'GLE 350',    'year' => 2019, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(360_000_000)], // ~360 M GNF

            // ——— BMW X5 ——————————————————————————————————————————
            ['brand' => 'BMW', 'model' => 'X5',                   'year' => 2017, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(310_000_000)], // ~310 M GNF
            ['brand' => 'BMW', 'model' => 'X5',                   'year' => 2020, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(420_000_000)], // ~420 M GNF

            // ——— Isuzu D-Max (pickup fiable, ONG & chantiers) ————————
            ['brand' => 'Isuzu', 'model' => 'D-Max',              'year' => 2016, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(155_000_000)], // ~155 M GNF
            ['brand' => 'Isuzu', 'model' => 'D-Max',              'year' => 2019, 'fuel' => 'DIESEL',  'transmission' => 'AUTO', 'base_price' => $gnf(195_000_000)], // ~195 M GNF
        ];

        foreach ($prices as $price) {
            TrocCarPrice::query()->updateOrCreate(
                [
                    'brand'        => $price['brand'],
                    'model'        => $price['model'],
                    'year'         => $price['year'],
                    'fuel'         => $price['fuel'],
                    'transmission' => $price['transmission'],
                ],
                ['base_price' => $price['base_price']],
            );
        }
    }
}
