<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Audit;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which permissions each role holds. Only an owner may change this (checked here, on the server):
 * anyone else who could edit roles could give themselves every permission.
 * The Owner role itself is fixed: it always holds everything.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ownerOnly($request);

        $counts = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('users.store_id', $request->user()->store_id)->whereNull('users.deleted_at')
            ->groupBy('model_has_roles.role_id')
            ->selectRaw('model_has_roles.role_id, COUNT(*) as users')
            ->pluck('users', 'role_id');

        $roles = Role::where('guard_name', 'web')->with('permissions:id,name')->orderBy('id')->get()
            ->map(fn (Role $role) => $this->present($role, (int) ($counts[$role->id] ?? 0)));

        return response()->json(['data' => $roles, 'permissions' => Permission::all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $this->validated($request, null);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions']);
            Audit::log('roles.created', $role, new: ['name' => $role->name, 'permissions' => $data['permissions']]);

            return $role;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json($this->present($role->load('permissions'), 0), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->ownerOnly($request);
        abort_if($role->name === RoleName::Owner->value, 409, 'The owner role cannot be changed.');
        $data = $this->validated($request, $role);

        DB::transaction(function () use ($role, $data) {
            $before = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
            if (! $this->isBuiltIn($role)) {
                $role->update(['name' => $data['name']]);
            }
            $role->syncPermissions($data['permissions']);
            $after = ['name' => $role->name, 'permissions' => collect($data['permissions'])->sort()->values()->all()];
            Audit::log('roles.updated', $role, $before, $after);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json($this->present($role->refresh()->load('permissions'), $role->users()->count()));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ownerOnly($request);

        if ($this->isBuiltIn($role)) {
            return response()->json(['code' => 'role_builtin', 'message' => 'Built-in roles cannot be deleted.'], 409);
        }
        if ($role->users()->withTrashed()->exists()) {
            return response()->json(['code' => 'role_in_use', 'message' => 'This role is still given to a user.'], 409);
        }

        DB::transaction(function () use ($role) {
            Audit::log('roles.deleted', $role, old: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()]);
            $role->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(null, 204);
    }

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()->isOwner(), 403);
    }

    private function isBuiltIn(Role $role): bool
    {
        return RoleName::tryFrom($role->name) !== null;
    }

    /** @return array{name: string, permissions: list<string>} */
    private function validated(Request $request, ?Role $role): array
    {
        $builtIn = $role !== null && $this->isBuiltIn($role);

        $data = $request->validate([
            'name' => $builtIn ? ['nullable'] : [
                'required', 'string', 'min:2', 'max:40', 'regex:/^[\p{L}\p{M}\p{N} ._-]+$/u',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::all())],
        ]);
        $data['permissions'] = array_values(array_unique($data['permissions']));
        $data['name'] = $builtIn ? $role->name : trim($data['name']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Role $role, int $users): array
    {
        $isOwner = $role->name === RoleName::Owner->value;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'builtin' => $this->isBuiltIn($role),
            'locked' => $isOwner,
            'permissions' => $isOwner ? Permission::all() : $role->permissions->pluck('name')->values()->all(),
            'users' => $users,
        ];
    }
}
