<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $role = $this->faker->randomElement([2, 3, 4, 5]);

        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'phone' => $this->faker->phoneNumber(),
            'pin' => str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'nis' => $role == 2 ? $this->faker->numerify('##########') : null,
            'nip' => in_array($role, [3, 4, 5]) ? $this->faker->numerify('############') : null,
            'batch' => rand(2015, 2025),
            'photo' => null,
            'role_id' => $role,
            'schoolclass_id' => $role == 2 ? rand(1, 9) : null,
        ];
    }
}
