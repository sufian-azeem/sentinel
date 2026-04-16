<?php

namespace App\Http\Controllers;

use App\Models\FavoritePair;
use Illuminate\Http\JsonResponse;

class FavoritePairController extends Controller
{
    public function toggle(string $pair): JsonResponse
    {
        $existing = FavoritePair::find($pair);

        if ($existing) {
            $existing->delete();
            $favorited = false;
        } else {
            FavoritePair::create(['pair' => $pair]);
            $favorited = true;
        }

        return response()->json(['favorited' => $favorited]);
    }
}
