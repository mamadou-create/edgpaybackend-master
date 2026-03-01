<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

class UploadHelper
{
    /**
     * Upload a file and return its relative path.
     */
    public static function upload($file, string $filename, string $targetLocation): ?string
    {
        // Si on reçoit un UploadedFile (depuis une requête)
        if ($file instanceof UploadedFile) {
            $extension = $file->getClientOriginalExtension();
            $finalName = $filename . '.' . $extension;

            $folder = $targetLocation . '/' . date('Y') . '/' . date('m');
            $relativePath = $folder . '/' . $finalName;

            // Chemin complet dans le répertoire public
            $fullPath = public_path($relativePath);

            // Créer les répertoires si nécessaire
            $directory = dirname($fullPath);
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            // Déplacer le fichier
            $file->move($directory, $finalName);

            return $relativePath;
        }

        // Si on reçoit un chemin de fichier (string)
        elseif (is_string($file)) {
            // Logique existante pour la requête
            if (request()->hasFile($file)) {
                $uploadedFile = request()->file($file);
                $extension = $uploadedFile->getClientOriginalExtension();
                $finalName = $filename . '.' . $extension;

                $folder = $targetLocation . '/' . date('Y') . '/' . date('m');
                $relativePath = $folder . '/' . $finalName;

                $fullPath = public_path($relativePath);

                $directory = dirname($fullPath);
                if (!File::exists($directory)) {
                    File::makeDirectory($directory, 0755, true);
                }

                $uploadedFile->move($directory, $finalName);

                return $relativePath;
            }
        }

        return null;
    }

    /**
     * Update an existing file and return new relative path.
     */
    public static function update($file, string $filename, string $targetLocation, string $oldRelativePath): ?string
    {
        // Supprimer l'ancien fichier
        self::deleteFile($oldRelativePath);

        // Uploader le nouveau fichier
        return self::upload($file, $filename, $targetLocation);
    }

    /**
     * Delete a file by its relative path.
     */
    public static function deleteFile(string $relativePath): void
    {
        $fullPath = public_path($relativePath);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }

    /**
     * Get full URL from relative path
     */
    public static function getUrl(string $relativePath): string
    {
        $relativePath = stripslashes($relativePath);
        return rtrim(URL::to('/'), '/') . '/' . ltrim($relativePath, '/');
    }

}
