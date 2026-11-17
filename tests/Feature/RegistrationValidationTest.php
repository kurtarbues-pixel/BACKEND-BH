<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationValidationTest extends TestCase
{
    use RefreshDatabase;

    private function registrationData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Juan Dela Cruz',
            'username' => 'juan.dela.cruz',
            'email' => 'juan@example.test',
            'phone' => '09171234567',
            'password' => 'secure-pass-123',
            'password_confirmation' => 'secure-pass-123',
            'role' => 'tenant',
        ], $overrides);
    }

    public function test_registration_rejects_numbers_and_symbols_in_names(): void
    {
        foreach (['Juan2 Cruz', 'Juan! Cruz'] as $index => $name) {
            $this->postJson('/api/register', $this->registrationData([
                'name' => $name,
                'username' => "invalid.name.{$index}",
                'email' => "invalid.name.{$index}@example.test",
            ]))->assertUnprocessable()->assertJsonValidationErrors('name');
        }
    }

    public function test_registration_rejects_non_philippine_or_non_digit_phone_values(): void
    {
        foreach (['0917ABC4567', '12345678901', '+63917123456'] as $index => $phone) {
            $this->postJson('/api/register', $this->registrationData([
                'username' => "invalid.phone.{$index}",
                'email' => "invalid.phone.{$index}@example.test",
                'phone' => $phone,
            ]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
    }

    public function test_registration_rejects_invalid_email_and_missing_required_fields(): void
    {
        $this->postJson('/api/register', $this->registrationData(['email' => 'not-an-email']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'username', 'email', 'password', 'role']);
    }

    public function test_registration_accepts_valid_names_and_both_supported_phone_formats(): void
    {
        foreach ([
            ['name' => "Ana O’Connor-Santos", 'phone' => '09171234567'],
            ['name' => 'Jose Dela Cruz', 'phone' => '+639171234567'],
        ] as $index => $valid) {
            $email = "valid.{$index}@example.test";
            $username = "valid.user.{$index}";
            $this->postJson('/api/register', $this->registrationData([
                ...$valid,
                'username' => $username,
                'email' => $email,
            ]))->assertCreated()->assertJsonPath('user.email', $email);

            $this->assertDatabaseHas('users', ['email' => $email, 'phone' => $valid['phone']]);
        }
    }

    public function test_registration_keeps_password_confirmation_and_role_validation(): void
    {
        $this->postJson('/api/register', $this->registrationData([
            'password_confirmation' => 'a-different-password',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/register', $this->registrationData([
            'username' => 'invalid.role',
            'email' => 'invalid.role@example.test',
            'role' => 'admin',
        ]))->assertUnprocessable()->assertJsonValidationErrors('role');
    }
}
