<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('user')?->id ?? $this->route('user');

        return [
            'prenom' => ['sometimes', 'nullable', 'string', 'max:150'],
            'nom' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users','email')->ignore($id)],
            'role' => ['sometimes', 'required', Rule::in(['admin','employee'])],
            'actif' => ['sometimes', Rule::in([0,1,true,false])],
            'password' => ['sometimes','nullable','string','min:6'],
        ];
    }
}
