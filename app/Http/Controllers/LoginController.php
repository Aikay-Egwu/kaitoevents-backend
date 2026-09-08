<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        //handle user login
        $user = User::where("email", $request->email)->first();
        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "Invalid credentials"
            ], 401);
        }

        // Check if password matches (you should use Hash::check() for hashed passwords)
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                "success" => false,
                "message" => "Invalid credentials"
            ], 401);
        }

        // Password matches - create token and return success
        //$token = $user->createToken("user");
        $token =  $user->createToken('user-person')->plainTextToken;
        return response()->json([
            "success" => true,
            "token" => $token,
            "user" => [
                "id" => $user->id,
                "name" => $user->name,
                "email" => $user->email,
                "role" => $user->type
            ]
        ], 200);
    }
}
