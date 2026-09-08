<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    /** @use HasFactory<\Database\Factories\FeedbackFactory> */
    use HasFactory;

    /**
     * "Feedback" is uncountable so Laravel would otherwise infer
     * the table name as "feedback" instead of "feedbacks".
     */
    protected $table = 'feedbacks';

    /**
     * The columns that hold the 1-6 rating scores.
     * Eight "Core Values" ratings plus the communication rating.
     */
    public const RATING_FIELDS = [
        'creativity',
        'excellence',
        'transcendence',
        'bespoke',
        'integrity',
        'exactitude',
        'intentionality',
        'genuine_connection',
        'communication_rating',
    ];

    /**
     * Valid values for the experience comparison question.
     */
    public const EXPERIENCE_COMPARISONS = [
        'below_expectations',
        'met_expectations',
        'exceeded_expectations',
    ];

    /**
     * Valid admin triage statuses.
     */
    public const STATUSES = ['new', 'reviewed', 'archived'];

    protected $fillable = [
        'client_id',
        'event_id',
        'event_type_id',
        'client_name',
        'event_date',
        'creativity',
        'excellence',
        'transcendence',
        'bespoke',
        'integrity',
        'exactitude',
        'intentionality',
        'genuine_connection',
        'communication_rating',
        'experience_comparison',
        'likely_to_return',
        'would_recommend',
        'unmet_expectations',
        'stood_out',
        'status',
    ];

    protected $casts = [
        'event_date' => 'date',
        'event_type_id' => 'integer',
        'likely_to_return' => 'boolean',
        'would_recommend' => 'boolean',
        'creativity' => 'integer',
        'excellence' => 'integer',
        'transcendence' => 'integer',
        'bespoke' => 'integer',
        'integrity' => 'integer',
        'exactitude' => 'integer',
        'intentionality' => 'integer',
        'genuine_connection' => 'integer',
        'communication_rating' => 'integer',
    ];

    /**
     * Validation rules shared by the Store/Update form requests.
     *
     * @return array<string, string>
     */
    public static function rules(?int $excludeId = null): array
    {
        $rules = [
            'client_id' => 'nullable|integer|exists:clients,id',
            'event_id' => 'nullable|integer|exists:events,id',
            'event_type_id' => 'required|integer|exists:event_types,id',
            'client_name' => 'required|string|max:255',
            'event_date' => 'required|date|before_or_equal:today',
            'experience_comparison' => 'required|in:' . implode(',', self::EXPERIENCE_COMPARISONS),
            'likely_to_return' => 'required|boolean',
            'would_recommend' => 'required|boolean',
            'unmet_expectations' => 'nullable|string|max:2000',
            'stood_out' => 'nullable|string|max:2000',
            'status' => 'sometimes|string|in:' . implode(',', self::STATUSES),
        ];

        foreach (self::RATING_FIELDS as $field) {
            $rules[$field] = 'required|integer|between:1,6';
        }

        return $rules;
    }

    // Relationships
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    // Scopes
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearchClient($query, string $search)
    {
        return $query->where('client_name', 'like', "%{$search}%");
    }

    /**
     * Average of all rating scores, rounded to two decimals.
     */
    public function averageRating(): float
    {
        $scores = collect(self::RATING_FIELDS)
            ->map(fn (string $field) => (int) $this->getAttribute($field))
            ->filter(fn (int $score) => $score >= 1 && $score <= 6);

        return $scores->isEmpty() ? 0 : round($scores->avg(), 2);
    }
}
