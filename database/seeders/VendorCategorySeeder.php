<?php

namespace Database\Seeders;

use App\Models\VendorType;
use Illuminate\Support\Str;
use App\Models\VendorCategory;
use Illuminate\Database\Seeder;

class VendorCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            "Venue and Location" => [
                "Event Venue",
                "Hotel",
                "Conference Center",
                "Banquet Hall",
                "Outdoor / Garden Venue",
                "Beach Venue",
                "Private Residence",
                "Coworking / Business Space"
            ],
            "Catering and Beverages" => [
                "Catering Company",
                "Private Chef",
                "Food Truck",
                "Beverage Supplier",
                "Bartending Service",
                "Coffee / Tea Service",
                "Dessert Vendor",
                "Cake Designer",
                "Specialty Cuisine Vendor"
            ],
            "Decor and Design" => [
                "Event Decorator",
                "Floral Designer",
                "Balloon Stylist",
                "Event Stylist",
                "Lighting Decor",
                "Furniture Rental",
                "Tableware / Linen Rental",
                "Backdrop & Stage Decor"
            ],
            "Entertainment" => [
                "DJ",
                "Live Band",
                "MC / Host",
                "Comedian",
                "Dancer / Dance Group",
                "Cultural Performers",
                "Kids Entertainer",
                "Magician",
                "Celebrity Talent"
            ],
            "Audio Visual and Technology" => [
                "Sound System Provider",
                "Lighting Technician",
                "LED Wall / Screen Provider",
                "Projection & AV Equipment",
                "Live Streaming Provider",
                "Hybrid Event Technology",
                "Event App Provider"
            ],
            "Photography and Videography" => [
                "Photographer",
                "Videographer",
                "Drone Operator",
                "Photo Booth Provider",
                "360 Video Booth",
                "Instant Print Service"
            ],
            "Production and Staging" => [
                "Stage Builder",
                "Truss & Rigging",
                "Event Production Company",
                "Set Designer",
                "Power Generator Supplier"
            ],
            "Logistics and Operations" => [
                "Event Staffing Agency",
                "Security Services",
                "Ushers / Hostesses",
                "Valet Parking",
                "Transportation Provider",
                "Logistics & Load-in Crew"
            ],
            "Fashion and Personal Services" => [
                "Bridal Wear Designer",
                "Groom Wear",
                "Costume Designer",
                "Makeup Artist",
                "Hair Stylist",
                "Stylist / Image Consultant"
            ],
            "Printing and Branding" => [
                "Printing Company",
                "Invitation Designer",
                "Signage & Wayfinding",
                "Branding & Graphics Vendor",
                "Corporate Gifting Vendor"
            ],
            "Planning and Support Services" => [
                "Event Planner",
                "Event Coordinator",
                "Wedding Planner",
                "Event Consultant",
                "On-site Event Manager"
            ],
            "Legal and Compliance" => [
                "Event Insurance Provider",
                "Permit & Licensing Service",
                "Health & Safety Compliance"
            ],
            "Specialty and Experiential" => [
                "Fireworks Provider",
                "Special Effects",
                "Themed Experience Vendor",
                "Interactive Installations",
                "Virtual / AR Experience"
            ],
            "Post Event Services" => [
                "Cleaning Services",
                "Waste Management",
                "Equipment Return Logistics",
                "Media Editing & Delivery"
            ]
        ];

        foreach ($data as $categoryName => $types) {
            $category = VendorCategory::firstOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName]
            );

            foreach ($types as $type) {
                VendorType::firstOrCreate(
                    [
                        'vendor_category_id' => $category->id,
                        'slug' => Str::slug($type)
                    ],
                    ['name' => $type]
                );
            }
        }
    }
}
