<?php

namespace App\Enums;

enum PaymentLinkStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case ENABLED = 'ENABLED';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
}
