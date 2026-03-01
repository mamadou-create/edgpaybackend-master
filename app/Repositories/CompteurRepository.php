<?php

namespace App\Repositories;

use App\Interfaces\CompteurRepositoryInterface;
use App\Models\Compteur;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CompteurRepository implements CompteurRepositoryInterface
{

    /**
     * Authenticated User Instance.
     *
     * @var User
     */
    public ?User $user;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->user = Auth::guard()->user();
    }


    public function getAll()
    {
        try {

            return Compteur::with('client')->latest()->get();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des Compteur: ' . $e->getMessage());
            return [];
        }
    }

    public function create(array $data)
    {
        try {

            return Compteur::create($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la demande pro : ' . $e->getMessage());
            throw $e;
        }
    }

    public function delete(string $id)
    {
        try {
            $Compteur = Compteur::findOrFail($id);
            return $Compteur->delete();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la demande pro : ' . $e->getMessage());
            throw $e;
        }
    }



    public function getByID(string $id)
    {
        try {
            return Compteur::with('client')->findOrFail($id);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la demande pro: ' . $e->getMessage());
            throw $e;
        }
    }

    public function update(string $id, array $data)
    {
        try {
            $Compteur = Compteur::findOrFail($id);

            return $Compteur->update($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la demande pro: ' . $e->getMessage());
            throw $e;
        }
    }



    public function findByTypeCompteur(string $typeCompteur)
    {
        try {
            return Compteur::with('client')->where('type_compteur', $typeCompteur)->latest()->get();
        } catch (\Exception $e) {
            report($e);
            return [];
        }
    }

     public function findCompteurByClient(string $clientId)
    {
        try {
            return Compteur::with('client')->where('client_id', $clientId)->latest()->get();
        } catch (\Exception $e) {
            report($e);
            return [];
        }
    }

    /**
     * Vérifier si un compteur existe pour un utilisateur et un type de compteur
     */
    public function checkCompteurExists(string $compteur, string $userId): bool
    {
        try {
            return Compteur::where('client_id', $userId)
                ->where('compteur', $compteur)
                ->exists();
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la vérification du compteur ($compteur) pour l'utilisateur $userId : " . $e->getMessage());
            return false;
        }
    }
}
