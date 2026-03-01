<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{

    public function index()
    {
        try {
            $roles = Role::orderBy('name', 'asc')->get();

            return ApiResponseClass::sendResponse(RoleResource::collection($roles), 'Rôles récupérés avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des roles');
        }
    }

    public function show($id)
    {
        try {
            $role = Role::findOrFail($id);

            return ApiResponseClass::sendResponse(new RoleResource($role), 'Rôle récupérés avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des role');
        }
    }

    public function getAllPermissions()
    {
        try {
            $permissions = Permission::orderBy('name', 'asc')->get();

            return ApiResponseClass::sendResponse($permissions, 'Permissions récupérés avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des permissions');
        }
    }



    // public function updatePermissions(Request $request, $id)
    // {
    //     $role = Role::findOrFail($id);
    //     $permissions = $request->input('permissions'); // [permission_id => access_level]

    //     $syncData = [];
    //     foreach ($permissions as $id => $access) {
    //         $syncData[$id] = ['access_level' => $access];
    //     }

    //     $role->permissions()->sync($syncData);

    //     return ApiResponseClass::sendResponse(
    //         null,
    //         'Permissions mises à jour avec succès'
    //     );
    // }

    public function updatePermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $permissions = $request->input('permissions'); // liste d'objets avec id et access_level

        $syncData = [];
        foreach ($permissions as $permission) {
            $syncData[$permission['id']] = ['access_level' => $permission['access_level']];
        }

        $role->permissions()->sync($syncData);

        return ApiResponseClass::sendResponse(
            null,
            'Permissions mises à jour avec succès'
        );
    }

    public function detachPermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $permissionIds = $request->input('permission_ids'); // tableau d'IDs à détacher

        if (!$permissionIds || !is_array($permissionIds)) {
            return ApiResponseClass::sendResponse(
                null,
                'Aucune permission à détacher',
                false,
                400
            );
        }

        // Détache les permissions spécifiées
        $role->permissions()->detach($permissionIds);

        return ApiResponseClass::sendResponse(
            null,
            'Permissions détachées avec succès'
        );
    }
}
