<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = Team::query()->find((int) $this->route('team'));

        return $team !== null && ($this->user()?->can('update', $team) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('teams', 'name')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->ignore((int) $this->route('team')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('status') || $this->input('status') !== 'inactive') {
                return;
            }

            $team = Team::query()->whereKey((int) $this->route('team'))->first();
            if ($team !== null && $team->status === 'active' && $team->companies()->exists()) {
                $validator->errors()->add('status', 'Transfira as empresas da equipe antes de desativá-la.');
            }

            if ($team !== null && $team->status === 'active' && $team->openInvitations()->exists()) {
                $validator->errors()->add('status', 'Revogue os convites pendentes ou expirados antes de desativar a equipe.');
            }
        }];
    }
}
