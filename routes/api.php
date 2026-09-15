<?php


use App\Helpers\Routes\RoutesHelper;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingItemController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientEventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventImageController;
use App\Http\Controllers\EventInventoryController;
use App\Http\Controllers\EventServiceController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PropCartController;
use App\Http\Controllers\PropCartItemController;
use App\Http\Controllers\PropCategoriesController;
use App\Http\Controllers\PropController;
use App\Http\Controllers\PropImageController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TestController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post("admin/login", LoginController::class)->name("main.login");

// Public routes (no authentication required)
Route::prefix('public')->group(function () {
    // Client event creation
    Route::post('/events', [ClientEventController::class, 'requestConsultation']);
    Route::get('/events/{event}', [ClientEventController::class, 'show']);
    Route::get('event_types', [EventTypeController::class, 'index']);
    Route::get('test_email', [TestController::class, 'testEmail']);
    Route::post('test_custom_email', [TestController::class, 'testCustomEmail']);

    // Public inventory listing (read-only)
    Route::get('/inventory', [InventoryController::class, 'publicIndex']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'publicShow']);
    Route::get('/categories', [PropCategoriesController::class, 'index']);

    // Client post-event feedback submission
    Route::post('/feedback', [FeedbackController::class, 'store']);
});

// Admin routes (require authentication)
//Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('admin')->group(function () {
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    RoutesHelper::includeRouteFiles(base_path('routes/paths')); 
    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'getStats']);
        Route::get('/analytics', [DashboardController::class, 'getAnalytics']);
    });

    // Consultations
    Route::prefix('consultations')->group(function () {
        Route::get('/', [EventController::class, 'getConsultations']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::put('/{id}', [EventController::class, 'updateConsultationStatus']);
    });

    //manage clients
    Route::prefix('clients')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::post('/', [ClientController::class, 'store']);
        Route::get('/{client}', [ClientController::class, 'show']);
        Route::put('/{client}', [ClientController::class, 'update']);
        Route::delete('/{client}', [ClientController::class, 'destroy']);
    });

    // Inventory Management
    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::post('/', [InventoryController::class, 'store']);
        Route::get('/{inventory}', [InventoryController::class, 'show']);
        Route::put('/{inventory}', [InventoryController::class, 'update']);
        Route::delete('/{inventory}', [InventoryController::class, 'destroy']);
        Route::get('/{inventory}/availability', [InventoryController::class, 'checkAvailability']);
    });

    
    // Event Management
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::post('/', [EventController::class, 'store']);
        Route::get('/{event}', [EventController::class, 'show']);
        Route::put('/{event}', [EventController::class, 'update']);
        Route::delete('/{event}', [EventController::class, 'destroy']);
        Route::patch('/{event}/status', [EventController::class, 'updateStatus']);

        // Event Services
        Route::prefix('{event}/services')->group(function () {
            Route::get('/available', [EventServiceController::class, 'available']);
            Route::get('/', [EventServiceController::class, 'index']);
            Route::post('/', [EventServiceController::class, 'assign']);
            Route::put('/{service}', [EventServiceController::class, 'update']);
            Route::delete('/{service}', [EventServiceController::class, 'remove']);
        });

        // Event Inventory
        Route::prefix('{event}/inventory')->group(function () {
            Route::get('/catalog',   [EventInventoryController::class, 'catalog']);
            Route::get('/available', [EventInventoryController::class, 'available']);
            Route::get('/',          [EventInventoryController::class, 'index']);
            Route::post('/',         [EventInventoryController::class, 'assign']);
            Route::put('/{inventory}',  [EventInventoryController::class, 'update']);
            Route::delete('/{inventory}', [EventInventoryController::class, 'remove']);
        });

        // Event Images
        Route::prefix('{event}/images')->group(function () {
            Route::get('/', [EventImageController::class, 'getEventImages']);
            Route::post('/', [EventImageController::class, 'upload']);
            Route::get('/statistics', [EventImageController::class, 'statistics']);
            Route::post('/reorder', [EventImageController::class, 'reorder']);
            Route::post('/bulk-delete', [EventImageController::class, 'bulkDelete']);
        });
    });

    // Individual image operations
    Route::prefix('images')->group(function () {
        Route::get('/{image}', [EventImageController::class, 'show']);
        Route::put('/{image}', [EventImageController::class, 'update']);
        Route::delete('/{image}', [EventImageController::class, 'destroy']);
        Route::post('/{image}/primary', [EventImageController::class, 'setPrimary']);
    });

    // Invoice Management
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/events', [InvoiceController::class, 'eventsWithInvoices']);
        Route::post('/events/{event}/generate', [InvoiceController::class, 'generateInvoice']);
        Route::get('/{invoice}', [InvoiceController::class, 'show']);
        Route::put('/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/{invoice}/payment', [InvoiceController::class, 'processPayment']);
        Route::post('/{invoice}/send', [InvoiceController::class, 'sendToClient']);
        Route::post('/{invoice}/approve', [InvoiceController::class, 'approve']);
        Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel']);
        Route::post('/{invoice}/regenerate', [InvoiceController::class, 'regenerate']);
        Route::get('/statistics', [InvoiceController::class, 'statistics']);
        Route::get('/events/{event}/preview', [InvoiceController::class, 'preview']);
        Route::get('/{invoice}/breakdown', [InvoiceController::class, 'breakdown']);
    });

    // Feedback management (admin only)
    Route::prefix('feedback')->group(function () {
        Route::get('/', [FeedbackController::class, 'index']);
        Route::get('/summary', [FeedbackController::class, 'summary']);
        Route::get('/{feedback}', [FeedbackController::class, 'show']);
        Route::put('/{feedback}', [FeedbackController::class, 'update']);
        Route::delete('/{feedback}', [FeedbackController::class, 'destroy']);
    });

    // Event Types Management
    Route::prefix('event-types')->group(function () {
        Route::get('/', [EventTypeController::class, 'index']);
        Route::post('/', [EventTypeController::class, 'store']);
        Route::get('/statistics', [EventTypeController::class, 'statistics']);
        Route::get('/{eventType}', [EventTypeController::class, 'show']);
        Route::put('/{eventType}', [EventTypeController::class, 'update']);
        Route::patch('/{eventType}/toggle-status', [EventTypeController::class, 'toggleStatus']);
        Route::delete('/{eventType}', [EventTypeController::class, 'destroy']);
    });

    // User routes
    // Categories
    //Route::apiResource('prop-categories', PropCategoriesController::class);
//
    //Route::apiResource('props', PropController::class);
    //Route::post('/props/{prop}/images', [PropController::class, 'uploadImages']);
    //Route::delete('/props/{prop}/images/{imageId}', [PropController::class, 'deleteImage']);
    //Route::patch('/props/{prop}/availability', [PropController::class, 'updateAvailability']);
    //Route::get('/props/category/{category}', [PropController::class, 'byCategory']);
    //Route::get('/props/search/{query}', [PropController::class, 'search']);
//
    //Route::apiResource('bookings', BookingController::class);
    //Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
    //Route::get('/bookings/calendar/{year}/{month}', [BookingController::class, 'calendar']);
    //Route::get('/bookings/date/{date}', [BookingController::class, 'byDate']);
    //Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm']);
    //Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete']);
    //Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    Route::apiResources([
        'prop-categories' => PropCategoriesController::class,
        'props' => PropController::class,
        'prop-images' => PropImageController::class, // accepts ?prop_id= filter
        //'customers'    => CustomerController::class,
        'prop-carts' => PropCartController::class,
        'prop-cart-items' => PropCartItemController::class,  // accepts ?cart_id= filter
        'bookings' => BookingController::class,
        'order-items' => BookingItemController::class, // accepts ?order_id= filter
        'reservations' => ReservationController::class,
    ]);
});

Route::get('/user', function (Request $request) {
    return response()->json(User::all());
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'environment' => app()->environment(),
    ]);
});

