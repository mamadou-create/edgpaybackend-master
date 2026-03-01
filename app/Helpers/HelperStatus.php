<?php

namespace App\Helpers;

class HelperStatus
{
    // Statuts des demandes pro
    const EN_ATTENTE = 'en attente';
    const ACCEPTE = 'accepté';
    const REFUSE = 'refusé';
    const ANNULE = 'annulé';

      // Valeurs techniques (BDD)
    const PENDING   = 'PENDING';
    const APPROVED  = 'APPROVED';
    const REJECTED  = 'REJECTED';
    const CANCELLED = 'CANCELLED';


    const SOURCE_CASH = 'CASH';
    const SOURCE_EDG = 'EDG';
    const SOURCE_GSS = 'GSS';
    const SOURCE_PARTNER = 'PARTNER';

    // Méthode pour obtenir tous les statuts valides
    public static function getDemandeProStatuses()
    {
        return [
            self::EN_ATTENTE,
            self::ACCEPTE,
            self::REFUSE,
            self::ANNULE
        ];
    }

    public static function getTopupRequestsStatuses()
    {
        return [
            self::PENDING,
            self::APPROVED,
            self::REJECTED,
            self::CANCELLED,
        ];
    }

    // Méthode pour obtenir tous TopupSource
    public static function getTopupSource()
    {
        return [
            self::SOURCE_CASH,
            self::SOURCE_EDG,
            self::SOURCE_GSS,
            self::SOURCE_PARTNER
        ];
    }

    // Mapping des libellés en FR
    public static function labels()
    {
        return [
            self::PENDING   => 'En attente',
            self::APPROVED  => 'Accepté',
            self::REJECTED  => 'Refusé',
            self::CANCELLED => 'Annulé',
        ];
    }




    // Récupérer le libellé FR à partir du code
    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    // Liste des statuts valides (codes techniques)
    public static function validStatuses(): array
    {
        return array_keys(self::labels());
    }
}
