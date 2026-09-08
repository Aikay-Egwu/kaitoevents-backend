<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create('/api/admin/users', 'POST', [
    'name' => 'Test',
    'email' => 'test@test.com',
    'password' => 'Password1!',
    'password_confirmation' => 'Password1!',
    'type' => 'Staff',
]);

$response = $kernel->handle($request);

echo $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
