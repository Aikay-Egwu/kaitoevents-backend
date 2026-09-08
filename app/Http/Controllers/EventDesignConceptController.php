<?php

namespace App\Http\Controllers;

use App\Models\AestheticDimension;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EventDesignConceptController — CRUD for the creative design blueprint.
 *
 * Handles create, read, update, and delete operations for the
 * event_design_concepts table. Because each event has at most one design
 * concept, the controller treats the resource as a singleton — index returns
 * a single object, and store/update/destroy operate on the existing record.
 *
 * All responses follow the standardized { success, data, message } JSON
 * envelope consumed by the Next.js server-bridge utility.
 */
class EventDesignConceptController extends Controller
{
    /**
     * Get the design concept, colour palette, aesthetic selections, and
     * available dimensions for an event — all in one response.
     */
    public function index(Event $event): JsonResponse
    {
        $designConcept = $event->designConcept;
        $aestheticSelections = $event->aestheticSelections()
            ->with('aestheticDimension')
            ->get();
        $colorPalette = $event->colorPalettes()->first();
        $aestheticDimensions = AestheticDimension::orderBy('display_order')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'designConcept'       => $designConcept,
                'colorPalette'        => $colorPalette,
                'aestheticSelection'  => $aestheticSelections,
                'aestheticDimension'  => $aestheticDimensions,
            ],
        ]);
    }

    /**
     * Create a design concept, colour palette, and aesthetic selections
     * for an event in a single request.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            // Design Concept
            'concept_name'        => 'required|string|max:255',
            'design_direction'    => 'required|string|max:255',
            'concept_narrative'   => 'required|string',
            'key_reference_notes' => 'nullable|string',

            // Colour Palette (optional flat object)
            'color_palette'                      => 'nullable|array',
            'color_palette.primary_color'        => 'nullable|string|max:255',
            'color_palette.secondary_color'      => 'nullable|string|max:255',
            'color_palette.accent_color'         => 'nullable|string|max:255',
            'color_palette.metal_color'          => 'nullable|string|max:255',
            'color_palette.neutral_color'        => 'nullable|string|max:255',
            'color_palette.avoid_color'          => 'nullable|string|max:255',
            'color_palette.color_palette_notes'  => 'nullable|string',

            // Aesthetic Selections (optional array)
            'aesthetic_selections'                              => 'nullable|array',
            'aesthetic_selections.*.aesthetic_dimension_id'     => 'required_with:aesthetic_selections|exists:aesthetic_dimensions,id',
            'aesthetic_selections.*.design_direction'           => 'required_with:aesthetic_selections|string',
        ]);

        // 1. Save Design Concept
        $designConcept = $event->designConcept()->create([
            'event_id'            => $event->id,
            'concept_name'        => $validated['concept_name'],
            'design_direction'    => $validated['design_direction'],
            'concept_narrative'   => $validated['concept_narrative'],
            'key_reference_notes' => $validated['key_reference_notes'] ?? null,
        ]);

        // 2. Save Colour Palette (at most one per event)
        if (!empty($validated['color_palette'])) {
            $event->colorPalettes()->create($validated['color_palette']);
        }

        // 3. Save Aesthetic Selections
        if (!empty($validated['aesthetic_selections'])) {
            foreach ($validated['aesthetic_selections'] as $selection) {
                $event->aestheticSelections()->create([
                    'aesthetic_dimension_id' => $selection['aesthetic_dimension_id'],
                    'design_direction'       => $selection['design_direction'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $designConcept,
        ], 201);
    }

    /**
     * Update the design concept, colour palette, and aesthetic selections
     * for an event in a single request.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $designConcept = $event->designConcept;

        if (!$designConcept) {
            return response()->json([
                'success' => false,
                'message' => 'No design concept found for this event',
            ], 404);
        }

        $validated = $request->validate([
            // Design Concept
            'concept_name'        => 'sometimes|string|max:255',
            'design_direction'    => 'sometimes|string|max:255',
            'concept_narrative'   => 'sometimes|string',
            'key_reference_notes' => 'nullable|string',

            // Colour Palette
            'color_palette'                      => 'nullable|array',
            'color_palette.primary_color'        => 'nullable|string|max:255',
            'color_palette.secondary_color'      => 'nullable|string|max:255',
            'color_palette.accent_color'         => 'nullable|string|max:255',
            'color_palette.metal_color'          => 'nullable|string|max:255',
            'color_palette.neutral_color'        => 'nullable|string|max:255',
            'color_palette.avoid_color'          => 'nullable|string|max:255',
            'color_palette.color_palette_notes'  => 'nullable|string',

            // Aesthetic Selections
            'aesthetic_selections'                              => 'nullable|array',
            'aesthetic_selections.*.aesthetic_dimension_id'     => 'required_with:aesthetic_selections|exists:aesthetic_dimensions,id',
            'aesthetic_selections.*.design_direction'           => 'required_with:aesthetic_selections|string',
        ]);

        // 1. Update Design Concept
        $designConcept->update([
            'concept_name'        => $validated['concept_name'] ?? $designConcept->concept_name,
            'design_direction'    => $validated['design_direction'] ?? $designConcept->design_direction,
            'concept_narrative'   => $validated['concept_narrative'] ?? $designConcept->concept_narrative,
            'key_reference_notes' => $validated['key_reference_notes'] ?? $designConcept->key_reference_notes,
        ]);

        // 2. Update or create Colour Palette
        if (array_key_exists('color_palette', $validated)) {
            $colorPalette = $event->colorPalettes()->first();
            if ($colorPalette) {
                $colorPalette->update($validated['color_palette']);
            } elseif (!empty($validated['color_palette'])) {
                $event->colorPalettes()->create($validated['color_palette']);
            }
        }

        // 3. Sync Aesthetic Selections (replace all)
        if (array_key_exists('aesthetic_selections', $validated)) {
            $event->aestheticSelections()->delete();
            if (!empty($validated['aesthetic_selections'])) {
                foreach ($validated['aesthetic_selections'] as $selection) {
                    $event->aestheticSelections()->create([
                        'aesthetic_dimension_id' => $selection['aesthetic_dimension_id'],
                        'design_direction'       => $selection['design_direction'],
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $designConcept,
        ]);
    }

    /**
     * Delete the design concept for an event.
     */
    public function destroy(Event $event): JsonResponse
    {
        $designConcept = $event->designConcept;

        if (!$designConcept) {
            return response()->json([
                'success' => false,
                'message' => 'No design concept found for this event',
            ], 404);
        }

        $designConcept->delete();

        return response()->json([
            'success' => true,
            'message' => 'Design concept deleted successfully',
        ]);
    }
}
