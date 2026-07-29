<?php

namespace App\Enums;

enum ReloadlyProduct: string
{
    case AIRTIME = 'airtime';
    case GIFTCARDS = 'giftcards';
    case UTILITIES = 'utilities';

    public function baseUrl(): string
    {
        $mode = config('services.reloadly.mode', 'sandbox');

        return (string) config("services.reloadly.products.{$this->value}.{$mode}");
    }

    public function tokenCacheKey(): string
    {
        $mode = config('services.reloadly.mode', 'sandbox');

        return "reloadly:token:{$this->value}:{$mode}";
    }
}