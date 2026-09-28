<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\LoginClientRequest;
use App\Http\Requests\Client\RegisterClientRequest;
use App\Http\Resources\Client\ClientUserResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClientAuthController extends Controller
{
    public function register(RegisterClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $client = Client::create([
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        $token = $client->createToken('shop-client')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'client' => new ClientUserResource($client),
        ], 201);
    }

    public function login(LoginClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $client = Client::where('email', $data['email'])->first();

        if (!$client || !$client->password || !Hash::check($data['password'], $client->password)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Email or password is incorrect.'],
            ], 401);
        }

        $token = $client->createToken('shop-client')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'client' => new ClientUserResource($client),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'client' => new ClientUserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }
}
