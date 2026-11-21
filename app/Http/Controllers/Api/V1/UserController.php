<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Audit;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::where('store_id', $request->user()->store_id)
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json([
            'data' => $users,
            'roles' => Role::where('guard_name', 'web')->orderBy('id')->pluck('name'),
        ]);
    }

    public function store(SaveUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($refusal = $this->refuseRoleChange($request->user(), null, $data['roles'])) {
            return $refusal;
        }

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create($this->attributes($data) + ['store_id' => $request->user()->store_id]);
            $user->syncRoles($data['roles']);
            Audit::log('users.created', $user, new: $this->auditValues($user));

            return $user;
        });

        return response()->json($this->present($user->refresh()), 201);
    }

    public function update(SaveUserRequest $request, User $user): JsonResponse
    {
        abort_unless($user->store_id === $request->user()->store_id, 404);
        $data = $request->validated();

        if ($refusal = $this->refuseRoleChange($request->user(), $user, $data['roles'])) {
            return $refusal;
        }

        $removesOwner = $user->isOwner()
            && (! in_array(RoleName::Owner->value, $data['roles'], true) || ! $data['is_active']);

        if ($removesOwner && $this->isLastActiveOwner($user)) {
            return response()->json([
                'code' => 'last_owner',
                'message' => 'The shop must always have at least one active owner.',
            ], 409);
        }

        DB::transaction(function () use ($user, $data) {
            $before = $this->auditValues($user);
            $user->update($this->attributes($data));
            $user->syncRoles($data['roles']);
            $after = $this->auditValues($user->refresh());

            Audit::log('users.updated', $user, $before, $after + [
                'password_changed' => ! empty($data['password']),
                'pin_changed' => ! empty($data['pin']),
            ]);
        });

        return response()->json($this->present($user->refresh()));
    }

    /**
     * Someone who manages users but is not an owner must not be able to climb:
     * they cannot edit an owner, cannot give the owner role, and cannot give a role
     * that allows more than they are allowed themselves.
     *
     * @param  list<string>  $roles
     */
    private function refuseRoleChange(User $actor, ?User $target, array $roles): ?JsonResponse
    {
        if ($actor->isOwner()) {
            return null;
        }

        $refuse = fn (string $code, string $message) => response()->json(['code' => $code, 'message' => $message], 403);

        if ($target?->isOwner() || in_array(RoleName::Owner->value, $roles, true)) {
            return $refuse('owner_only', 'Only an owner can add or change an owner.');
        }

        $newRoles = array_diff($roles, $target?->getRoleNames()->all() ?? []);
        if ($newRoles !== []) {
            $mine = $actor->effectivePermissions();
            $granted = Role::whereIn('name', $newRoles)->where('guard_name', 'web')->with('permissions:id,name')->get()
                ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique();
            if ($granted->diff($mine)->isNotEmpty()) {
                return $refuse('role_above_own', 'You cannot give a role that allows more than your own.');
            }
        }

        return null;
    }

    private function isLastActiveOwner(User $user): bool
    {
        return User::role(RoleName::Owner->value)
            ->where('store_id', $user->store_id)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'name' => $data['name'],
            'username' => $data['username'],
            'mobile' => $data['mobile'] ?? null,
            'locale' => $data['locale'],
            'is_pharmacist' => $data['is_pharmacist'],
            'pharmacist_reg_no' => $data['is_pharmacist'] ? ($data['pharmacist_reg_no'] ?? null) : null,
            'is_active' => $data['is_active'],
        ];

        // Blank password / PIN on update means "keep the current one".
        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }
        if (! empty($data['pin'])) {
            $attributes['pin'] = $data['pin'];
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'mobile' => $user->mobile,
            'locale' => $user->locale,
            'is_pharmacist' => $user->is_pharmacist,
            'pharmacist_reg_no' => $user->pharmacist_reg_no,
            'is_active' => $user->is_active,
            'has_pin' => $user->pin !== null,
            'roles' => $user->getRoleNames()->sort()->values(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function auditValues(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'is_pharmacist' => $user->is_pharmacist,
            'pharmacist_reg_no' => $user->pharmacist_reg_no,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
        ];
    }
}
