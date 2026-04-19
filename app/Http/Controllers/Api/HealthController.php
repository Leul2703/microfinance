<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke()
    {
        DB::select('SELECT 1');

        return response()->json([
            'ok' => true,
            'message' => 'Laravel API and database are connected.',
        ]);
    }
}
