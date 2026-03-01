<?php

namespace App\Services;

use App\Models\CreditProfile;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de ledger comptable quasi-bancaire.
 *
 * Chaque écriture est :
 *  - Atomique (DB::transaction)
 *  - Signée (hash SHA-256)
 *  - Chainée (précédent_hash)
 *  - Immuable (protégé par le modèle LedgerEntry)
 */
class FinancialLedgerService
{
    /** Clé secrète pour la signature — doit être dans .env */
    private function secretKey(): string
    {
        return config('app.ledger_secret', config('app.key'));
    }

    // ─── Écrire une entrée ────────────────────────────────────────────────

    /**
     * Enregistre une écriture dans le ledger.
     *
     * @param  User   $user          Client débiteur/créditeur
     * @param  string $type          debit|credit|penalite|remise|ajustement_admin
     * @param  float  $montant       Montant de l'écriture
     * @param  string $refType       Classe de la référence (ex: Creance::class)
     * @param  string $refId         UUID de la référence
     * @param  string $description   Libellé comptable
     * @param  string|null $creeParId  Admin ayant déclenché l'écriture
     * @return LedgerEntry
     */
    public function enregistrer(
        User   $user,
        string $type,
        float  $montant,
        string $refType,
        string $refId,
        string $description = '',
        ?string $creeParId = null
    ): LedgerEntry {
        return DB::transaction(function () use ($user, $type, $montant, $refType, $refId, $description, $creeParId) {

            // Verrouiller le profil pour calculer la balance courante
            /** @var CreditProfile $profil */
            $profil = $user->creditProfile()->lockForUpdate()->firstOrFail();

            $balanceAvant = (float) $profil->total_encours;

            // Calculer la nouvelle balance
            $balanceApres = match ($type) {
                'debit'             => $balanceAvant + $montant,  // encours augmente
                'credit'            => $balanceAvant - $montant,  // encours diminue
                'penalite'          => $balanceAvant + $montant,
                'remise'            => $balanceAvant - $montant,
                'ajustement_admin'  => $balanceAvant + $montant,  // peut être +/-
                default             => $balanceAvant,
            };

            $balanceApres = max(0, $balanceApres);

            // Récupérer le dernier hash pour la chaîne
            $dernierHash = LedgerEntry::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('hash_integrite');

            $timestamp = now()->timestamp;

            // Genera hash d'intégrité
            $hash = $this->genererHash($user->id, $montant, $timestamp);

            $entry = LedgerEntry::create([
                'user_id'        => $user->id,
                'type'           => $type,
                'montant'        => $montant,
                'balance_avant'  => $balanceAvant,
                'balance_apres'  => $balanceApres,
                'reference_type' => $refType,
                'reference_id'   => $refId,
                'description'    => $description,
                'hash_integrite' => $hash,
                'precedent_hash' => $dernierHash,
                'cree_par'       => $creeParId,
            ]);

            // Sync la balance dans le profil de crédit
            $profil->update([
                'total_encours'     => $balanceApres,
                'credit_disponible' => max(0, (float) $profil->credit_limite - $balanceApres),
            ]);

            Log::info('[Ledger] Écriture enregistrée', [
                'entry_id'     => $entry->id,
                'user_id'      => $user->id,
                'type'         => $type,
                'montant'      => $montant,
                'balance_apres'=> $balanceApres,
            ]);

            return $entry;
        });
    }

    // ─── Débit (création créance) ─────────────────────────────────────────

    public function debiter(
        User $user,
        float $montant,
        string $refType,
        string $refId,
        string $description = 'Nouvelle créance',
        ?string $creeParId = null
    ): LedgerEntry {
        return $this->enregistrer($user, 'debit', $montant, $refType, $refId, $description, $creeParId);
    }

    // ─── Crédit (paiement reçu) ───────────────────────────────────────────

    public function crediter(
        User $user,
        float $montant,
        string $refType,
        string $refId,
        string $description = 'Paiement reçu',
        ?string $creeParId = null
    ): LedgerEntry {
        return $this->enregistrer($user, 'credit', $montant, $refType, $refId, $description, $creeParId);
    }

    // ─── Pénalité ─────────────────────────────────────────────────────────

    public function penaliser(
        User $user,
        float $montant,
        string $refType,
        string $refId,
        string $description = 'Pénalité de retard',
        ?string $creeParId = null
    ): LedgerEntry {
        return $this->enregistrer($user, 'penalite', $montant, $refType, $refId, $description, $creeParId);
    }

    // ─── Vérification intégrité de la chaîne ─────────────────────────────

    /**
     * Vérifie l'intégrité de toutes les entrées ledger d'un utilisateur.
     * Retourne une liste des entrées corrompues.
     */
    public function verifierIntegrite(User $user): array
    {
        $entrees    = LedgerEntry::where('user_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $corrompues = [];

        foreach ($entrees as $entree) {
            if (! $entree->verifierIntegrite($this->secretKey())) {
                $corrompues[] = [
                    'id'         => $entree->id,
                    'created_at' => $entree->created_at,
                    'type'       => $entree->type,
                    'montant'    => $entree->montant,
                ];
            }
        }

        if (! empty($corrompues)) {
            Log::critical('[Ledger] INTÉGRITÉ COMPROMISE', [
                'user_id'    => $user->id,
                'nb_entrees' => count($corrompues),
            ]);
        }

        return $corrompues;
    }

    // ─── Relevé de compte ─────────────────────────────────────────────────

    /**
     * Retourne le relevé de compte d'un client avec balance courante.
     */
    public function releve(User $user, int $limit = 50): array
    {
        $entrees = LedgerEntry::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'user_id'       => $user->id,
            'balance_actuelle' => (float) ($user->creditProfile->total_encours ?? 0),
            'nb_entrees'    => $entrees->count(),
            'entrees'       => $entrees->toArray(),
        ];
    }

    // ─── Hash d'intégrité ─────────────────────────────────────────────────

    public function genererHash(string $userId, float $montant, int $timestamp): string
    {
        $payload = implode('|', [$userId, $montant, $timestamp, $this->secretKey()]);
        return hash('sha256', $payload);
    }
}
