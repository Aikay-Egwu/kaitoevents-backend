# Design Document

## Overview

This design completes the event management system by implementing a comprehensive Laravel API that supports inventory management, enhanced event workflows, service assignments, invoice generation, and image uploads. The system follows Laravel best practices with proper separation of concerns, RESTful API design, and robust data validation.

The architecture supports two primary user types: admin users (event planners) who manage the system and assign resources, and clients who create event requests. The system uses Laravel Sanctum for API authentication and implements proper authorization middleware.

## Architecture

### API Structure

The system follows RESTful conventions with resource-based endpoints:

```
/api/admin/* - Protected admin endpoints requiring authentication
/api/client/* - Public client endpoints for event creation
/api/public/* - Public endpoints for reference data (event types, etc.)
```

### Authentication & Authorization

- **Laravel Sanctum**: Token-based authentication for admin users
- **Middleware Groups**: 
  - `auth:sanctum` for admin-only endpoints
  - Public access for client event creation
  - Rate limiting on public endpoints

### Database Design Enhancements

The existing schema will be enhanced with:

1. **Event Images Table**: Already exists, needs proper relationships
2. **Pivot Tables**: event_inventories and event_services already exist
3. **Invoice Enhancements**: EventInvoice model needs additional fields for itemization
4. **File Storage**: Images stored in Laravel's storage system with database metadata

## Components and Interfaces

### 1. Inventory Management System

**InventoryController** - Full CRUD operations
- `GET /api/admin/inventory` - List all inventory with filtering
- `POST /api/admin/inventory` - Create new inventory item
- `GET /api/admin/inventory/{id}` - Get inventory details with event associations
- `PUT /api/admin/inventory/{id}` - Update inventory item
- `DELETE /api/admin/inventory/{id}` - Delete inventory (with constraint checking)

**InventoryResource** - API response formatting
- Transforms inventory models for consistent API responses
- Includes related data (category, current event assignments)

**InventoryRequest** - Validation
- Validates inventory creation/update data
- Ensures required fields and data types

### 2. Enhanced Event Management

**EventController** - Extended functionality
- `GET /api/admin/events` - Admin dashboard with status filtering
- `GET /api/admin/events/{id}` - Detailed event view with all relationships
- `PUT /api/admin/events/{id}` - Update event details and status
- `POST /api/admin/events/{id}/assign-service` - Assign service to event
- `DELETE /api/admin/events/{id}/services/{serviceId}` - Remove service
- `POST /api/admin/events/{id}/assign-inventory` - Assign inventory to event
- `DELETE /api/admin/events/{id}/inventory/{inventoryId}` - Remove inventory

**ClientEventController** - Client-facing endpoints
- `POST /api/client/events` - Create new event request
- `GET /api/client/events/{id}` - View event status (with token/email verification)

### 3. Service Assignment System

**EventServiceController** - Service management
- Handles many-to-many relationships between events and services
- Calculates pricing impacts when services are added/removed
- Validates service availability and constraints

**Service Assignment Logic**:
```php
// When assigning service to event
$event->services()->attach($serviceId, ['assigned_at' => now()]);
$event->updateTotalCost();
```

### 4. Invoice Generation System

**InvoiceController** - Invoice management
- `POST /api/admin/events/{id}/generate-invoice` - Create invoice
- `GET /api/admin/invoices/{id}` - View invoice details
- `PUT /api/admin/invoices/{id}` - Update invoice (payment status, etc.)

**InvoiceService** - Business logic
- Calculates totals from assigned services and inventory
- Generates invoice numbers
- Handles tax calculations
- Creates itemized invoice records

**Enhanced EventInvoice Model**:
```php
protected $fillable = [
    'event_id', 'invoice_number', 'subtotal', 'tax_amount', 
    'total_amount', 'payment_status', 'generated_at', 'due_date'
];
```

**InvoiceItem Model** (new):
```php
// For itemized invoice entries
protected $fillable = [
    'event_invoice_id', 'item_type', 'item_id', 'description', 
    'quantity', 'unit_price', 'total_price'
];
```

### 5. Image Management System

**EventImageController** - Image operations
- `POST /api/admin/events/{id}/images` - Upload multiple images
- `GET /api/admin/events/{id}/images` - List event images
- `DELETE /api/admin/images/{id}` - Delete specific image

**ImageUploadService** - File handling
- Validates file types and sizes
- Generates unique filenames
- Creates thumbnails
- Stores files in Laravel storage
- Creates database records

**EventImage Model Enhancement**:
```php
protected $fillable = [
    'event_id', 'filename', 'original_name', 'file_path', 
    'thumbnail_path', 'file_size', 'mime_type', 'uploaded_at'
];
```

## Data Models

### Enhanced Models

**Event Model** - Additional methods
```php
public function calculateTotalCost()
{
    $serviceCost = $this->services()->sum('price');
    $inventoryCost = $this->inventories()->sum('price');
    return $serviceCost + $inventoryCost;
}

public function updateTotalCost()
{
    $this->update(['total_cost' => $this->calculateTotalCost()]);
}
```

**EventInvoice Model** - Enhanced with relationships
```php
public function items()
{
    return $this->hasMany(InvoiceItem::class);
}

public function generateInvoiceNumber()
{
    return 'INV-' . date('Y') . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
}
```

### New Models

**InvoiceItem Model**
```php
class InvoiceItem extends Model
{
    protected $fillable = [
        'event_invoice_id', 'item_type', 'item_id', 'description',
        'quantity', 'unit_price', 'total_price'
    ];

    public function invoice()
    {
        return $this->belongsTo(EventInvoice::class, 'event_invoice_id');
    }
}
```

## Error Handling

### Validation Strategy

**Form Request Classes** for each operation:
- `StoreInventoryRequest` / `UpdateInventoryRequest`
- `CreateEventRequest` / `UpdateEventRequest`
- `AssignServiceRequest` / `AssignInventoryRequest`
- `GenerateInvoiceRequest`
- `UploadImageRequest`

**Custom Validation Rules**:
```php
// Check inventory availability for events
'inventory_id' => ['required', 'exists:inventories,id', new InventoryAvailable($eventDate)]

// Validate event date is in future
'event_date' => ['required', 'date', 'after:today']

// Image upload validation
'images.*' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120']
```

### Exception Handling

**Custom Exception Classes**:
- `InventoryNotAvailableException`
- `InvalidEventStatusTransitionException`
- `InvoiceAlreadyGeneratedException`
- `ImageUploadFailedException`

**Global Exception Handler** enhancements:
```php
public function render($request, Throwable $exception)
{
    if ($exception instanceof InventoryNotAvailableException) {
        return response()->json([
            'error' => 'Inventory item not available for selected date',
            'available_dates' => $exception->getAvailableDates()
        ], 422);
    }
    
    return parent::render($request, $exception);
}
```

## Testing Strategy

### Unit Tests

**Model Tests**:
- Event cost calculation methods
- Invoice generation logic
- Image file handling
- Relationship integrity

**Service Tests**:
- InvoiceService calculations
- ImageUploadService file operations
- Event status transition logic

### Feature Tests

**API Endpoint Tests**:
- Inventory CRUD operations with authentication
- Event creation and management workflows
- Service and inventory assignment
- Invoice generation and retrieval
- Image upload and deletion

**Integration Tests**:
- Complete event lifecycle (creation → assignment → invoice → completion)
- File upload with database consistency
- Authentication and authorization flows

### Database Tests

**Migration Tests**:
- Schema integrity
- Foreign key constraints
- Index performance

**Factory and Seeder Tests**:
- Data generation consistency
- Relationship creation

## Security Considerations

### Authentication
- Sanctum token validation on admin endpoints
- Rate limiting on public endpoints
- CSRF protection for web routes

### File Upload Security
- MIME type validation
- File size limits
- Secure file storage outside web root
- Virus scanning integration (future enhancement)

### Data Protection
- Input sanitization
- SQL injection prevention through Eloquent ORM
- XSS protection in API responses
- Sensitive data exclusion from API responses

## Performance Optimizations

### Database Optimization
- Eager loading for relationships to prevent N+1 queries
- Database indexes on frequently queried fields
- Pagination for large datasets

### File Handling
- Thumbnail generation for images
- Lazy loading for image galleries
- CDN integration for file serving (future enhancement)

### Caching Strategy
- Cache frequently accessed reference data (event types, services)
- Cache calculated totals for events
- Redis integration for session and cache storage

## API Response Format

### Standard Response Structure
```json
{
    "success": true,
    "data": {
        // Resource data
    },
    "message": "Operation completed successfully",
    "meta": {
        "pagination": {
            "current_page": 1,
            "total_pages": 5,
            "total_items": 50
        }
    }
}
```

### Error Response Structure
```json
{
    "success": false,
    "error": {
        "message": "Validation failed",
        "code": "VALIDATION_ERROR",
        "details": {
            "field_name": ["Field is required"]
        }
    }
}
```

This design provides a comprehensive foundation for implementing all required features while maintaining Laravel best practices and ensuring scalability for future enhancements.