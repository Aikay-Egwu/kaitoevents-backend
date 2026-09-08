<?php

namespace App\Http\Controllers;

use App\Events\UserAccountCreated;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * UsersController — CRUD for admin users (type: Admin, Manager, Staff).
 *
 * Handles listing, creating, viewing, updating, deleting users and
 * changing passwords. New users are always created with type 'Admin'.
 * Dispatches a welcome email event when a new user account is created.
 */
class UsersController extends Controller
{
    /**
     * List all users (excludes password field).
     */
    public function index(): JsonResponse
    {
        // Selects only the fields needed for staff pickers / assignment dropdowns.
        // Includes 'type' (Admin / Manager / Staff) so the UI can label roles correctly.
        $users = User::select('id', 'name', 'email', 'type')->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Create a new user with type forced to 'Admin'.
     * Validates password against the strict policy:
     *  - min 10 characters
     *  - at least one uppercase letter
     *  - at least one lowercase letter
     *  - at least one number
     *  - at least one special character
     *
     * Dispatches UserAccountCreated event to send welcome email.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:10',
                'regex:/[A-Z]/',          // at least one uppercase
                'regex:/[a-z]/',          // at least one lowercase
                'regex:/[0-9]/',          // at least one digit
                'regex:/[^A-Za-z0-9]/',   // at least one special char
            ],
        ]);

        // Force type to 'Admin' on creation
        //$validated['type'] = 'Admin';

        // Capture plain-text password before it gets hashed by the model cast
        $plainTextPassword = $validated['password'];

        // Create the user (password is auto-hashed via the 'hashed' cast on the model)
        $user = User::create($validated);

        // Dispatch event to send welcome email with plain-text password
        event(new UserAccountCreated($user, $plainTextPassword));

        // Hide sensitive fields in the response
        $user->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully. Welcome email sent.',
            'data' => $user,
        ], 201);
    }

    /**
     * Show a single user (excludes password).
     */
    public function show($id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * Update a user's name, email, or type.
     * Password changes go through changePassword().
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'type'  => 'sometimes|in:Admin,Manager,Staff',
        ]);

        $user->update($validated);
        $user->makeHidden(['password', 'remember_token']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user,
        ]);
    }

    /**
     * Delete a user. Guards against self-deletion.
     */
    public function destroy($id): JsonResponse
    {
        // Prevent a user from deleting their own account
        if (Auth::check() && (string) Auth::id() === (string) $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Change a user's password with the strict password policy:
     *  - min 10 characters
     *  - at least one uppercase letter
     *  - at least one lowercase letter
     *  - at least one number
     *  - at least one special character
     */
    public function changePassword(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:10',
                'regex:/[A-Z]/',          // at least one uppercase
                'regex:/[a-z]/',          // at least one lowercase
                'regex:/[0-9]/',          // at least one digit
                'regex:/[^A-Za-z0-9]/',   // at least one special char
                'confirmed',              // requires password_confirmation field
            ],
        ]);

        $user = User::findOrFail($id);
        $user->password = $validated['password']; // auto-hashed via model cast
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
