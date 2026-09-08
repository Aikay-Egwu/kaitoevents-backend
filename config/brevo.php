<?php

return [
    'api_key' => env('BREVO_API_KEY'),
    'admin_email' => env('ADMIN_EMAIL', 'admin@kaitoevents.co.uk'),
    'from_email' => env('MAIL_FROM_ADDRESS', 'noreply@kaitoevents.co.uk'),
    'from_name' => env('MAIL_FROM_NAME', 'Kaito Events'),
];