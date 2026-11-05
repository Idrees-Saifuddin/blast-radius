<?php

namespace App\Forms;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrder extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}

class OrderController
{
    public function store(StoreOrder $request): void
    {
    }

    public function other(string $name): void
    {
    }
}
