<?php

namespace App\Http\Controllers;

use App\Models\Prop;
use App\Models\PropImage;
use App\Http\Requests\StorePropRequest;
use App\Http\Requests\UpdatePropRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropController extends Controller
{
    public function index(Request $request)
    {
        $query = Prop::with(['category', 'images']);

        // Search functionality
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category_id', $request->category);
        }

        // Filter by availability
        if ($request->has('available') && $request->available) {
            $query->where('is_active', true)
                  ->where('quantity_available', '>', 0);
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $props = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'props' => $props,
            'meta' => [
                'total' => $props->total(),
                'per_page' => $props->perPage(),
                'current_page' => $props->currentPage(),
                'last_page' => $props->lastPage()
            ]
        ]);
    }

    public function store(StorePropRequest $request)
    {
        $validatedData = $request->validated();
        $validatedData['slug'] = Str::slug($validatedData['name']);

        // Handle dimensions as array
        if (isset($validatedData['dimensions'])) {
            $validatedData['dimensions'] = json_decode($validatedData['dimensions'], true);
        }

        $prop = Prop::create($validatedData);

        // Handle image uploads
        if ($request->hasFile('images')) {
            $this->uploadPropImages($prop, $request->file('images'));
        }

        $prop->load(['category', 'images']);

        return response()->json([
            'message' => 'Prop created successfully',
            'prop' => $prop
        ], 201);
    }

    public function show(Prop $prop)
    {
        $prop->load(['category', 'images', 'bookingItems.booking']);
        
        return response()->json([
            'prop' => $prop
        ]);
    }

    public function update(UpdatePropRequest $request, Prop $prop)
    {
        $validatedData = $request->validated();
        
        if (isset($validatedData['name'])) {
            $validatedData['slug'] = Str::slug($validatedData['name']);
        }

        // Handle dimensions as array
        if (isset($validatedData['dimensions'])) {
            $validatedData['dimensions'] = json_decode($validatedData['dimensions'], true);
        }

        $prop->update($validatedData);

        // Handle new image uploads
        if ($request->hasFile('images')) {
            $this->uploadPropImages($prop, $request->file('images'));
        }

        $prop->load(['category', 'images']);

        return response()->json([
            'message' => 'Prop updated successfully',
            'prop' => $prop
        ]);
    }

    public function destroy(Prop $prop)
    {
        // Check if prop has active bookings
        $activeBookings = $prop->bookingItems()
            ->whereHas('booking', function ($query) {
                $query->whereIn('status', ['pending', 'confirmed', 'delivered']);
            })
            ->count();

        if ($activeBookings > 0) {
            return response()->json([
                'message' => 'Cannot delete prop with active bookings'
            ], 422);
        }

        // Delete associated images
        foreach ($prop->images as $image) {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();
        }

        $prop->delete();

        return response()->json([
            'message' => 'Prop deleted successfully'
        ]);
    }

    public function uploadImages(Request $request, Prop $prop)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($request->hasFile('images')) {
            $uploadedImages = $this->uploadPropImages($prop, $request->file('images'));
            
            return response()->json([
                'message' => 'Images uploaded successfully',
                'images' => $uploadedImages
            ]);
        }

        return response()->json([
            'message' => 'No images provided'
        ], 422);
    }

    public function deleteImage(Request $request, Prop $prop, $imageId)
    {
        $image = PropImage::where('prop_id', $prop->id)
                         ->where('id', $imageId)
                         ->firstOrFail();

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json([
            'message' => 'Image deleted successfully'
        ]);
    }

    public function updateAvailability(Request $request, Prop $prop)
    {
        $request->validate([
            'quantity_available' => 'required|integer|min:0|max:' . $prop->quantity_total,
            'is_active' => 'boolean'
        ]);

        $prop->update($request->only(['quantity_available', 'is_active']));

        return response()->json([
            'message' => 'Availability updated successfully',
            'prop' => $prop
        ]);
    }

    public function byCategory($categoryId)
    {
        $props = Prop::with(['category', 'images'])
                    ->where('category_id', $categoryId)
                    ->where('is_active', true)
                    ->where('quantity_available', '>', 0)
                    ->get();

        return response()->json([
            'props' => $props
        ]);
    }

    public function search($query)
    {
        $props = Prop::with(['category', 'images'])
                    ->where('is_active', true)
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('description', 'like', "%{$query}%");
                    })
                    ->limit(20)
                    ->get();

        return response()->json([
            'props' => $props
        ]);
    }

    public function availableProps(Request $request)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Prop::with(['category', 'images'])
                    ->where('is_active', true)
                    ->where('quantity_available', '>', 0);

        if ($startDate && $endDate) {
            $query->whereHas('bookingItems', function ($q) use ($startDate, $endDate) {
                $q->whereHas('booking', function ($bookingQuery) use ($startDate, $endDate) {
                    $bookingQuery->where('status', '!=', 'cancelled')
                                ->where(function ($dateQuery) use ($startDate, $endDate) {
                                    $dateQuery->whereBetween('event_date', [$startDate, $endDate])
                                            ->orWhereBetween('return_date', [$startDate, $endDate])
                                            ->orWhere(function ($overlapQuery) use ($startDate, $endDate) {
                                                $overlapQuery->where('event_date', '<=', $startDate)
                                                           ->where('return_date', '>=', $endDate);
                                            });
                                });
                });
            }, '=', 0);
        }

        $props = $query->get();

        return response()->json([
            'props' => $props
        ]);
    }

    private function uploadPropImages(Prop $prop, array $images)
    {
        $uploadedImages = [];
        $isFirstImage = $prop->images()->count() === 0;

        foreach ($images as $index => $image) {
            $path = $image->store('props/' . $prop->id, 'public');
            
            $propImage = $prop->images()->create([
                'image_path' => $path,
                'alt_text' => $prop->name . ' image ' . ($index + 1),
                'is_primary' => $isFirstImage && $index === 0,
                'sort_order' => $prop->images()->count() + $index + 1
            ]);

            $uploadedImages[] = $propImage;
        }

        return $uploadedImages;
    }
}