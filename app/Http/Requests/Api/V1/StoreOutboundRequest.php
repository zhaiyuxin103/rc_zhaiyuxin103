<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutboundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'target_url' => ['required', 'url:http,https', 'max:2048'],
            'http_method' => ['sometimes', 'string', 'uppercase', Rule::in(['POST', 'PUT', 'PATCH'])],
            'headers' => ['sometimes', 'array'],
            'headers.*' => ['string', 'max:8192'],
            'payload' => ['required', 'array'],
        ];
    }

    /**
     * Copy the transport idempotency key into the validated request data.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * Get the validated attributes required to create an outbound.
     *
     * @return array{
     *     idempotency_key: string,
     *     target_url: string,
     *     http_method: string,
     *     headers: array<string, string>,
     *     payload: array<mixed>
     * }
     */
    public function outboundAttributes(): array
    {
        $headers = [];

        foreach ((array) $this->validated('headers', []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        $payload = $this->validated('payload');

        return [
            'idempotency_key' => (string) $this->validated('idempotency_key'),
            'target_url' => (string) $this->validated('target_url'),
            'http_method' => (string) ($this->validated('http_method') ?? 'POST'),
            'headers' => $headers,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }
}
