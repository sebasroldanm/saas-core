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
            $log = activity('auth');
            if ($customer) {
                $log->performedOn($customer);
            }
            $log->event('login_failed')
                ->withProperties(['guard' => 'customer', 'email' => $credentials['email'], 'ip' => $request->ip()])
                ->log('Intento de inicio de sesión fallido en la API');

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // saveQuietly: last_login_at no es un cambio de datos del cliente para la auditoría
        $customer->forceFill(['last_login_at' => now()])->saveQuietly();

        activity('auth')
            ->causedBy($customer)
            ->performedOn($customer)
            ->event('login')
            ->withProperties(['guard' => 'customer', 'device' => $credentials['device_name'] ?? 'api', 'ip' => $request->ip()])
            ->log('Inicio de sesión en la API');

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
        $customer = $request->user('customer');
        $customer->currentAccessToken()->delete();

        activity('auth')
            ->causedBy($customer)
            ->performedOn($customer)
            ->event('logout')
            ->withProperties(['guard' => 'customer', 'ip' => $request->ip()])
            ->log('Cierre de sesión en la API');

        return response()->noContent();
    }
}
