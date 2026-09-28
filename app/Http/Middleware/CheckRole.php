<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $allowedRoles = $user->roles_list;
        if (!in_array($role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Akses ditolak. Anda tidak memiliki izin (role: ' . $role . ') untuk mengakses resource ini.'
            ], 403);
        }

        $activeRoleHeader = $request->header('X-Active-Role');
        if ($activeRoleHeader && $activeRoleHeader !== $role && in_array($activeRoleHeader, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Peran aktif saat ini (' . $activeRoleHeader . ') tidak sesuai untuk resource ini. Silakan beralih ke peran ' . $role . ' terlebih dahulu.'
            ], 403);
        }

        return $next($request);
    }
}
