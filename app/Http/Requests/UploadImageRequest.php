<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadImageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'images' => 'required|array|min:1|max:20',
            'images.*' => [
                'required',
                'image',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
                    ->max(5 * 1024), // 5MB max per image
            ],
            'event_id' => 'required|exists:events,id',
            'category' => 'sometimes|string|in:venue,setup,food,decorations,other',
            'description' => 'sometimes|string|max:500',
            'tags' => 'sometimes|array|max:10',
            'tags.*' => 'string|max:50',
            'is_primary' => 'sometimes|boolean',
            'optimize' => 'sometimes|boolean',
            'thumbnail_sizes' => 'sometimes|array|max:5',
            'thumbnail_sizes.*' => 'string|in:small,medium,large,xl',
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Please select at least one image to upload.',
            'images.array' => 'Images must be provided as an array.',
            'images.min' => 'Please select at least one image to upload.',
            'images.max' => 'You can upload a maximum of 20 images at once.',
            'images.*.image' => 'Each file must be a valid image.',
            'images.*.mimes' => 'Only JPG, JPEG, PNG, GIF, and WebP images are allowed.',
            'images.*.max' => 'Each image must be smaller than 5MB.',
            'event_id.required' => 'Event ID is required.',
            'event_id.exists' => 'The specified event does not exist.',
            'category.in' => 'Category must be one of: venue, setup, food, decorations, other.',
            'description.max' => 'Description must not exceed 500 characters.',
            'tags.array' => 'Tags must be provided as an array.',
            'tags.max' => 'You can add up to 10 tags per image.',
            'tags.*.max' => 'Each tag must not exceed 50 characters.',
            'is_primary.boolean' => 'Primary flag must be true or false.',
            'optimize.boolean' => 'Optimize flag must be true or false.',
            'thumbnail_sizes.array' => 'Thumbnail sizes must be provided as an array.',
            'thumbnail_sizes.*.in' => 'Thumbnail sizes must be one of: small, medium, large, xl.',
        ];
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'images' => 'images',
            'images.*' => 'image',
            'event_id' => 'event',
            'category' => 'category',
            'description' => 'description',
            'tags' => 'tags',
            'tags.*' => 'tag',
            'is_primary' => 'primary image',
            'optimize' => 'optimize images',
            'thumbnail_sizes' => 'thumbnail sizes',
            'thumbnail_sizes.*' => 'thumbnail size',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Merge event route parameter into validation data
        if ($this->route('event')) {
            $this->merge([
                'event_id' => $this->route('event')->id,
            ]);
        }

        if ($this->has('tags') && is_string($this->tags)) {
            $this->merge([
                'tags' => explode(',', $this->tags),
            ]);
        }

        if ($this->has('thumbnail_sizes') && is_string($this->thumbnail_sizes)) {
            $this->merge([
                'thumbnail_sizes' => explode(',', $this->thumbnail_sizes),
            ]);
        }

        // Set default thumbnail sizes if not provided
        if (!$this->has('thumbnail_sizes')) {
            $this->merge([
                'thumbnail_sizes' => ['small', 'medium', 'large'],
            ]);
        }
    }
}