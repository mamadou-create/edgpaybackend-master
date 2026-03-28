<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\TrocConditionAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrocImageController extends Controller
{
    public function __construct(private TrocConditionAssessmentService $assessmentService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $path = Storage::disk('public')->putFile('troc', $validated['image']);
        $publicUrl = asset('storage/' . $path);
        $analysis = $this->assessmentService->analyzeStoredImage(
            Storage::disk('public')->path($path),
            $publicUrl,
        );

        return ApiResponseClass::created([
            'path' => $path,
            'url' => $publicUrl,
            'analysis' => $analysis,
        ], 'Image Troc envoyée avec succès');
    }
}