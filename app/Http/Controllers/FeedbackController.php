<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Http\Requests\UpdateFeedbackRequest;
use App\Models\Feedback;
use App\Models\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    /**
     * Public endpoint: clients submit post-event feedback without logging in.
     */
    public function store(StoreFeedbackRequest $request)
    {
        $feedback = Feedback::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your time — your feedback has been received and will help us grow.',
            'data' => $feedback,
        ], 201);
    }

    /**
     * Admin: paginated list of feedback submissions with optional filters.
     */
    public function index(Request $request)
    {
        $query = Feedback::query()->with(['client', 'event', 'eventType'])->latest();

        if ($request->filled('status')) {
            $query->byStatus($request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $query->searchClient($request->string('search')->toString());
        }

        if ($request->filled('event_type_id')) {
            $query->where('event_type_id', (int) $request->query('event_type_id'));
        }

        $feedbacks = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $feedbacks->items(),
            'meta' => [
                'pagination' => [
                    'current_page' => $feedbacks->currentPage(),
                    'per_page' => $feedbacks->perPage(),
                    'total' => $feedbacks->total(),
                    'last_page' => $feedbacks->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Admin: aggregate summary of all feedback, optionally filtered by event type.
     *
     * Returns per-dimension averages, boolean breakdowns, experience comparison
     * distribution, and an overall composite score.
     */
    public function summary(Request $request)
    {
        $query = Feedback::query();

        if ($request->filled('event_type_id')) {
            $query->where('event_type_id', (int) $request->query('event_type_id'));
        }

        $total = $query->count();

        if ($total === 0) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_feedback' => 0,
                    'overall_averages' => collect(Feedback::RATING_FIELDS)->mapWithKeys(
                        fn (string $f) => [$f => 0]
                    ),
                    'overall_score' => 0,
                    'experience_breakdown' => [
                        'below_expectations' => 0,
                        'met_expectations' => 0,
                        'exceeded_expectations' => 0,
                    ],
                    'likely_to_return' => ['yes' => 0, 'no' => 0, 'yes_pct' => 0],
                    'would_recommend' => ['yes' => 0, 'no' => 0, 'yes_pct' => 0],
                    'by_event_type' => [],
                ],
            ]);
        }

        // Per-dimension averages (all 9 rating fields).
        $avgSelects = collect(Feedback::RATING_FIELDS)->map(
            fn (string $f) => DB::raw("ROUND(AVG({$f}), 2) as {$f}")
        );

        $averagesRow = (clone $query)
            ->select($avgSelects->all())
            ->first();

        $overallAverages = collect(Feedback::RATING_FIELDS)->mapWithKeys(
            fn (string $f) => [$f => round((float) ($averagesRow->{$f} ?? 0), 2)]
        );

        // Composite overall score (mean of all dimension averages).
        $overallScore = round($overallAverages->avg(), 2);

        // Experience comparison breakdown.
        $experienceRows = (clone $query)
            ->select('experience_comparison', DB::raw('COUNT(*) as cnt'))
            ->groupBy('experience_comparison')
            ->pluck('cnt', 'experience_comparison');

        $experienceBreakdown = [
            'below_expectations' => (int) ($experienceRows['below_expectations'] ?? 0),
            'met_expectations' => (int) ($experienceRows['met_expectations'] ?? 0),
            'exceeded_expectations' => (int) ($experienceRows['exceeded_expectations'] ?? 0),
        ];

        // Boolean breakdowns.
        $likelyYes = (clone $query)->where('likely_to_return', true)->count();
        $recommendYes = (clone $query)->where('would_recommend', true)->count();

        $likelyToReturn = [
            'yes' => $likelyYes,
            'no' => $total - $likelyYes,
            'yes_pct' => $total > 0 ? round($likelyYes / $total * 100, 1) : 0,
        ];

        $wouldRecommend = [
            'yes' => $recommendYes,
            'no' => $total - $recommendYes,
            'yes_pct' => $total > 0 ? round($recommendYes / $total * 100, 1) : 0,
        ];

        // Per-event-type breakdown.
        $byEventType = Feedback::query()
            ->select(
                'event_type_id',
                DB::raw('COUNT(*) as feedback_count'),
                ...collect(Feedback::RATING_FIELDS)->map(
                    fn (string $f) => DB::raw("ROUND(AVG({$f}), 2) as {$f}")
                )
            )
            ->join('event_types', 'event_types.id', '=', 'feedbacks.event_type_id')
            ->addSelect('event_types.title as event_type_name')
            ->groupBy('feedbacks.event_type_id', 'event_types.title')
            ->get()
            ->map(function ($row) {
                $avgs = collect(Feedback::RATING_FIELDS)->mapWithKeys(
                    fn (string $f) => [$f => round((float) ($row->{$f} ?? 0), 2)]
                );
                return [
                    'event_type_id' => $row->event_type_id,
                    'event_type_name' => $row->event_type_name,
                    'feedback_count' => (int) $row->feedback_count,
                    'averages' => $avgs,
                    'overall_score' => round($avgs->avg(), 2),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total_feedback' => $total,
                'overall_averages' => $overallAverages,
                'overall_score' => $overallScore,
                'experience_breakdown' => $experienceBreakdown,
                'likely_to_return' => $likelyToReturn,
                'would_recommend' => $wouldRecommend,
                'by_event_type' => $byEventType,
            ],
        ]);
    }

    /**
     * Admin: single feedback submission.
     */
    public function show(Feedback $feedback)
    {
        $feedback->load(['client', 'event', 'eventType']);

        return response()->json([
            'success' => true,
            'data' => $feedback,
        ]);
    }

    /**
     * Admin: partial or full update of a feedback record.
     */
    public function update(UpdateFeedbackRequest $request, Feedback $feedback)
    {
        $feedback->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Feedback updated successfully.',
            'data' => $feedback->fresh(),
        ]);
    }

    /**
     * Admin: remove a feedback submission.
     */
    public function destroy(Feedback $feedback)
    {
        $feedback->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feedback deleted successfully.',
        ]);
    }
}
