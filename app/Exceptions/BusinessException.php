<?php

namespace App\Exceptions;

/**
 * Exception levée pour des refus "métier" attendus (règles de crédit, éligibilité, limites, etc.).
 *
 * Ces messages peuvent être renvoyés au frontend car ils sont destinés à l'utilisateur.
 */
class BusinessException extends \RuntimeException
{
}
