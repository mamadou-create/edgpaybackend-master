<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait TwoFactorAuthenticatable
{
    /**
     * Génère un code de vérification à deux facteurs (pour SMS)
     */
    public function generateTwoFactorCode()
    {
        $this->two_factor_token = rand(100000, 999999); // Code à 6 chiffres pour SMS
        $this->two_factor_expires_at = now()->addMinutes(10);
        $this->save();

        return $this->two_factor_token;
    }

    /**
     * Vérifie le code de vérification à deux facteurs
     */
    public function validateTwoFactorCode($code)
    {
        return $this->two_factor_token === $code &&
            $this->two_factor_expires_at &&
            $this->two_factor_expires_at->isFuture();
    }

    /**
     * Réinitialise le code de vérification à deux facteurs
     */
    public function resetTwoFactorCode()
    {
        $this->two_factor_token = null;
        $this->two_factor_expires_at = null;
        $this->save();
    }

    /**
     * Active l'authentification à deux facteurs
     */
    public function enableTwoFactor()
    {
        $this->two_factor_enabled = true;
        $this->save();
    }

    /**
     * Désactive l'authentification à deux facteurs
     */
    public function disableTwoFactor()
    {
        $this->two_factor_enabled = false;
        $this->resetTwoFactorCode();
        $this->save();
    }

    /**
     * Vérifie si l'authentification à deux facteurs est activée
     */
    public function hasTwoFactorEnabled()
    {
        return $this->two_factor_enabled;
    }
}