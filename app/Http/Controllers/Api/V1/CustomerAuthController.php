<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de clientes del tenant por token (Sanctum). El tenant se identifica por el
 * dominio de la petición, así que el token solo sirve en la API de su tenant.
 */
class CustomerAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $customer = Customer::query()->where('email', $credentials['email'])->first();

        // Mismo mensaje para email inexistente, contraseña errónea o cliente inactivo
        if (! $customer || ! $customer->is_active || ! Hash::check($credentials['password'], $customer->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $customer->forceFill(['last_login_at' => now()])->save();

        $token = $customer->createToken($credentials['device_name'] ?? 'api');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function me(Request $request): CustomerResource
    {
        return new CustomerResource($request->user('customer'));
    }

    public function logout(Request $request): Response
    {
        $request->user('customer')->currentAccessToken()->delete();

        return response()->noContent();
    }
}
