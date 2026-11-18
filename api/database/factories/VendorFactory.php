<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => $this->faker->unique()->company(), 'default_currency' => 'USD'];
    }
}
