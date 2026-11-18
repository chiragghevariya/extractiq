<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->company()).'-'.Str::lower(Str::random(4));

        return ['name' => Str::headline($slug), 'slug' => $slug, 'base_currency' => 'USD'];
    }
}
