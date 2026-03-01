<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case MOMO = 'MOMO';          // MTN Mobile Money
    case OM = 'OM';              // Orange Money
    case KULU = 'KULU';          // Kulu de Digital Pay
    case SOUTOURA = 'SOUTOURA';  // Soutoura Money (Disponible bientôt)
    case PAYCARD = 'PAYCARD';    // PayCard (Disponible bientôt)
    case YMO = 'YMO';            // Ymo (Disponible bientôt)
    case VISA = 'VISA';          // VISA (Disponible bientôt)
    case MC = 'MC';              // MasterCard (Disponible bientôt)
    case AMEX = 'AMEX';          // American Express (Disponible bientôt)
}
