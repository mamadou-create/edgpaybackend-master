<?php

namespace App\Repositories;

use App\Interfaces\CommissionRepositoryInterface;
use App\Models\Commission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class CommissionRepository implements CommissionRepositoryInterface
{

    /**
     * Get all commissions
     */
    public function getAll()
    {
        try {
             return Commission::orderBy('key')->get();
        } catch (Exception $e) {
            Log::error('Error fetching all commissions: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Get commission by ID
     */
    public function getByID($id)
    {
        try {
            return Commission::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            Log::warning('Commission not found with ID: ' . $id);
            return null;
        } catch (Exception $e) {
            Log::error('Error fetching commission by ID ' . $id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get commission by key
     */
    public function getByKey($key)
    {
        try {
            return Commission::where('key', $key)->first();
        } catch (Exception $e) {
            Log::error('Error fetching commission by key ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new commission
     */
    public function create(array $data)
    {
        DB::beginTransaction();
        
        try {
            // Check if key already exists
            if (Commission::where('key', $data['key'])->exists()) {
                throw new Exception('Commission with this key already exists');
            }

            $commission = Commission::create([
                'key' => $data['key'],
                'value' => $data['value']
            ]);

            DB::commit();
            return $commission;
            
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error creating commission: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update commission by ID
     */
    public function update($id, array $data)
    {
        DB::beginTransaction();
        
        try {
            $commission = Commission::find($id);
            
            if (!$commission) {
                throw new Exception('Commission not found');
            }

            // Check if key is being changed and if new key already exists
            if (isset($data['key']) && $data['key'] !== $commission->key) {
                if (Commission::where('key', $data['key'])->where('id', '!=', $id)->exists()) {
                    throw new Exception('Commission with this key already exists');
                }
            }

            $commission->update($data);

            DB::commit();
            return $commission;
            
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error updating commission ' . $id . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update commission by key
     */
    public function updateByKey($key, $value)
    {
        DB::beginTransaction();
        
        try {
            $commission = Commission::where('key', $key)->first();

            if (!$commission) {
                // Create if doesn't exist
                $commission = $this->create(['key' => $key, 'value' => $value]);
                DB::commit();
                return $commission;
            }

            $commission->value = $value;
            $commission->save();

            DB::commit();
            return $commission;
            
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error updating commission by key ' . $key . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get multiple commissions by keys
     */
    public function getMultipleByKeys(array $keys)
    {
        try {
            return Commission::whereIn('key', $keys)->get();
        } catch (Exception $e) {
            Log::error('Error fetching multiple commissions: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Delete commission by ID
     */
    public function delete($id)
    {
        DB::beginTransaction();
        
        try {
            $commission = Commission::find($id);
            
            if (!$commission) {
                return false;
            }
            
            $result = $commission->delete();

            DB::commit();
            return $result;
            
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error deleting commission ' . $id . ': ' . $e->getMessage());
            return false;
        }
    }



}