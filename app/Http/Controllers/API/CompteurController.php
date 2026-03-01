<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\CompteurRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Compteur\CompteurRequest;
use App\Http\Resources\CompteurResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompteurController extends Controller
{
    private $compteurRepository;

    public function __construct(CompteurRepositoryInterface $compteurRepository)
    {
        $this->compteurRepository = $compteurRepository;
    }

    /**
     * 📥 Liste des compteurs
     */
    public function index()
    {
        try {
            $compteurs = $this->compteurRepository->getAll();
            return ApiResponseClass::sendResponse(
                CompteurResource::collection($compteurs),
                'Compteurs récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des compteurs');
        }
    }

    /**
     * 📄 Détail d’un compteur
     */
    public function show($id)
    {
        try {
            $compteur = $this->compteurRepository->getByID($id);
            if (!$compteur) {
                return ApiResponseClass::notFound('Compteur introuvable');
            }

            return ApiResponseClass::sendResponse(
                new CompteurResource($compteur),
                'Compteur récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du compteur');
        }
    }

    /**
     * 🆕 Création d’un compteur
     */
    public function store(CompteurRequest $request)
    {
        DB::beginTransaction();
        try {
            $compteur = $this->compteurRepository->create($request->validated());

            DB::commit();
            return ApiResponseClass::created(
                new CompteurResource($compteur),
                'Compteur créé avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création du compteur");
        }
    }

    /**
     * ✏️ Mise à jour
     */
    public function update($id, CompteurRequest $request)
    {
        DB::beginTransaction();
        try {
            $success = $this->compteurRepository->update($id, $request->validated());

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Compteur mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du compteur");
        }
    }

    /**
     * ❌ Suppression
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->compteurRepository->delete($id);

            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Compteur introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Compteur supprimé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression du compteur");
        }
    }

    /**
     * 🔍 Compteurs par type
     */
    public function findByType($type)
    {
        try {
            $compteurs = $this->compteurRepository->findByTypeCompteur($type);
            return ApiResponseClass::sendResponse(
                CompteurResource::collection($compteurs),
                'Compteurs du type récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération des compteurs par type");
        }
    }

     /**
     * 🔍 Compteurs par type
     */
    public function findByClient($clientId)
    {
        try {
            $compteurs = $this->compteurRepository->findCompteurByClient($clientId);
            return ApiResponseClass::sendResponse(
                CompteurResource::collection($compteurs),
                'Compteurs du client récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération des compteurs par client");
        }
    }

     /**
     * 🔍 Vérifier si un compteur existe pour un utilisateur et un type
     */
    public function checkCompteur(Request $request)
    {
        $request->validate([
            'client_id' => 'required|uuid|exists:users,id',
            'compteur' => 'required|string',
        ]);

        try {
            $exists = $this->compteurRepository->checkCompteurExists(
                $request->compteur,
                $request->client_id
            );

            return ApiResponseClass::sendResponse(
                ['exists' => $exists],
                $exists ? 'Le compteur existe déjà.' : 'Aucun compteur trouvé.'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la vérification du compteur");
        }
    }


}
