<?php

namespace App\Interfaces;

interface CrudInterface
{
   /**
     * Get All Data
     *
     * @return array All Data Item
     */
    public function getAll();

    /**
     * Create New Item
     *
     * @param array $data
     * @return object Created Item
     */
    public function create(array $data);


    /**
     * Delete Item By Id
     *
     * @param string $guid
     * @return object Deleted
     */
    public function delete(string $guid);

    /**
     * Get Item Details By ID
     *
     * @param string $guid
     * @return object Get
     */
    public function getById(string $guid);

    /**
     * Update Item By Guid and Data
     *
     * @param string $guid
     * @param array $data
     * @return object Updated Item Information
     */
    public function update(string $guid, array $data);

}
