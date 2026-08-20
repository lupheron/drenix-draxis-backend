<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ClientEmployeePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $employee = User::query()
            ->where('username', $validated['username'])
            ->first();

        if (
            ! $employee
            || ! $employee->password
            || ! Hash::check($validated['password'], $employee->password)
        ) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        $employee->tokens()->delete();

        $token = $employee->createToken(
            'employee-token',
            ['employee'],
            now()->addDays(30),
        )->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user_type' => 'employee',
                'employee' => ClientEmployeePresenter::present($employee),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'ok']);
    }
}
