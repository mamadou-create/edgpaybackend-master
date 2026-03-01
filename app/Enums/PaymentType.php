<?php

namespace App\Enums;

enum PaymentType: string
{
    case DIRECT = 'DIRECT';       // Paiement direct
    case GATEWAY = 'GATEWAY';           // Paiement via passerelle
}
