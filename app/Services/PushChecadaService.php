<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PushChecadaService
{
    protected $baseUrls;
    
    protected $endpoint = '/checador/pushChecadaFromADMS';

    public function __construct()
    {
        $this->baseUrls = config('services.apis');
        
        if (!$this->baseUrls) {
            throw new \Exception("API configuration for not found.");
        }
    }

    public function getData()
    {

        $response = Http::get($this->baseUrls[0] . $this->endpoint);
        return $response->json();
    }

    public function postData($data): object
    {
        try{
            
            $currentAPI = (object)$this->baseUrls['uamex'];
            // set headers
            $headers = [
                'Authorization' => $currentAPI->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)
                ->post($currentAPI->base_url . $this->endpoint, $data);
            return (object)$response->json();
        } catch (\Exception $e) {
            return (object)[
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }
}