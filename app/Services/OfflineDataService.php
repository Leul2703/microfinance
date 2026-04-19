<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class OfflineDataService
{
    /**
     * Store data locally for offline access
     */
    public static function storeData($key, $data)
    {
        try {
            $data = json_encode($data);
            Storage::disk('local')->put("offline_data/{$key}.json", $data);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retrieve stored data
     */
    public static function getData($key, $default = null)
    {
        try {
            $data = Storage::disk('local')->get("offline_data/{$key}.json");
            return $data ? json_decode($data, true) : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Get customer data for offline use
     */
    public static function getCustomerData($customerId)
    {
        return self::getData("customer_{$customerId}", [
            'loans' => [],
            'savings' => [],
            'payments' => [],
            'last_sync' => now()->toISOString()
        ]);
    }

    /**
     * Store customer data for offline use
     */
    public static function storeCustomerData($customerId, $data)
    {
        $existingData = self::getData("customer_{$customerId}");
        $mergedData = array_merge($existingData, $data);
        $mergedData['last_sync'] = now()->toISOString();
        
        return self::storeData("customer_{$customerId}", $mergedData);
    }

    /**
     * Cache frequently accessed data
     */
    public static function cacheData($key, $data, $ttl = 3600) // 1 hour
    {
        Cache::put("offline_cache_{$key}", $data, $ttl);
    }

    /**
     * Get cached data
     */
    public static function getCachedData($key, $default = null)
    {
        return Cache::get("offline_cache_{$key}", $default);
    }

    /**
     * Clear offline data
     */
    public static function clearData($key)
    {
        try {
            Storage::disk('local')->delete("offline_data/{$key}.json");
            Cache::forget("offline_cache_{$key}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all offline data keys
     */
    public static function getAllDataKeys()
    {
        try {
            $files = Storage::disk('local')->files('offline_data');
            return $files->map(function ($file) {
                return str_replace(['offline_data/', '.json'], $file);
            })->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if data is stale (older than specified minutes)
     */
    public static function isDataStale($key, $minutes = 30)
    {
        $data = self::getData($key);
        if ($data && isset($data['last_sync'])) {
            $lastSync = new \DateTime($data['last_sync']);
            $now = new \DateTime();
            $interval = $now->diff($lastSync);
            return $interval->i > $minutes;
        }
        return true; // Assume fresh if no timestamp
    }
}
