<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BaseApiService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.djomy.base_url');
        $this->apiKey = config('services.djomy.api_key');
    }

    protected function post($endpoint, $data = [], $headers = [])
    {
        return $this->request('POST', $endpoint, $data, $headers);
    }

    protected function get($endpoint, $headers = [])
    {
        return $this->request('GET', $endpoint, [], $headers);
    }

    protected function request($method, $endpoint, $data = [], $headers = [])
    {
        $url = $this->baseUrl . $endpoint;

        $defaultHeaders = [
            'X-API-KEY' => $this->apiKey,
        ];

        $headers = array_merge($defaultHeaders, $headers);

        return Http::withHeaders($headers)
            ->$method($url, $data);
    }
}