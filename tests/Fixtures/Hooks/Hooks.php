<?php

namespace App\Hooks;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Http\Resources\Json\JsonResource;

class Secret implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        return $value;
    }

    public function set($model, $key, $value, $attributes)
    {
        return $value;
    }
}

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [];
    }
}

class Plain
{
    public function get()
    {
        return 1;
    }

    public function toArray()
    {
        return [];
    }
}

class Caller
{
    public function run($unknown)
    {
        $unknown->get();
        $unknown->set();
        $unknown->toArray();
    }
}
