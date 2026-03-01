<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class EnvService
{
    private $envPath;

    public function __construct()
    {
        $this->envPath = base_path('.env');
    }

    /**
     * Mettre à jour une variable d'environnement
     */
    public function updateEnvVariable(string $key, string $value): bool
    {
        try {
            // Vérifier si le fichier .env existe et est accessible en écriture
            if (!File::exists($this->envPath)) {
                Log::error('Fichier .env non trouvé: ' . $this->envPath);
                return false;
            }

            if (!File::isWritable($this->envPath)) {
                Log::error('Fichier .env non accessible en écriture: ' . $this->envPath);
                return false;
            }

            $envContent = File::get($this->envPath);
            
            // Nettoyer la valeur (supprimer les guillemets existants)
            $value = trim($value, '"\'');
            
            // Pattern pour trouver la variable (avec ou sans guillemets)
            $pattern = "/^{$key}=(\"|'?)(.*?)(\"|'?)$/m";
            
            if (preg_match($pattern, $envContent)) {
                // Remplacer la valeur existante
                $updatedContent = preg_replace(
                    $pattern,
                    "{$key}=\"{$value}\"",
                    $envContent
                );
            } else {
                // Ajouter la nouvelle variable à la fin du fichier
                $updatedContent = $envContent . "\n{$key}=\"{$value}\"";
            }
            
            // Sauvegarder le fichier
            $result = File::put($this->envPath, $updatedContent);
            
            if ($result === false) {
                Log::error('Échec de l\'écriture dans le fichier .env');
                return false;
            }
            
            Log::info("Variable {$key} mise à jour dans .env");
            
            // Recharger la configuration
            $this->reloadConfig();
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour .env: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recharger la configuration
     */
    private function reloadConfig(): void
    {
        try {
            // Vider le cache de configuration
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            // Recharger les variables d'environnement
            if (function_exists('apache_getenv') && function_exists('apache_setenv')) {
                // Pour Apache
            }
            
            // Pour Laravel, on peut essayer de recharger la configuration
            app()->config->set('services.dml.token', $this->getEnvVariable('DML_TOKEN'));
            
        } catch (\Exception $e) {
            Log::error('Erreur rechargement configuration: ' . $e->getMessage());
        }
    }

    /**
     * Obtenir une variable d'environnement
     */
    public function getEnvVariable(string $key): ?string
    {
        return env($key);
    }

    /**
     * Vérifier si une variable existe
     */
    public function hasEnvVariable(string $key): bool
    {
        $value = $this->getEnvVariable($key);
        return !empty($value);
    }

    /**
     * Obtenir le contenu complet du .env (pour debug)
     */
    public function getEnvContent(): string
    {
        try {
            return File::get($this->envPath);
        } catch (\Exception $e) {
            Log::error('Erreur lecture .env: ' . $e->getMessage());
            return '';
        }
    }
}