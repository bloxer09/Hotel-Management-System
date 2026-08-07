<?php

namespace Tests\Feature;

use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OtpPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_can_request_otp()
    {
        $user = User::factory()->create(['email' => 'guest@example.com']);

        $response = $this->post(route('password.send-otp'), [
            'email' => 'guest@example.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('password_reset_otps', [
            'email' => 'guest@example.com',
        ]);
    }

    public function test_forgot_password_otp_can_be_verified()
    {
        $user = User::factory()->create(['email' => 'guest@example.com']);
        $otp = PasswordResetOtp::create([
            'email' => 'guest@example.com',
            'otp_code' => '654321',
            'expires_at' => now()->addMinutes(10),
            'is_verified' => false,
        ]);

        $response = $this->post(route('password.verify-otp'), [
            'email' => 'guest@example.com',
            'otp_code' => '654321',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('password_reset_otps', [
            'email' => 'guest@example.com',
            'is_verified' => true,
        ]);
    }

    public function test_user_can_reset_password_with_verified_otp()
    {
        $user = User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $otp = PasswordResetOtp::create([
            'email' => 'guest@example.com',
            'otp_code' => '654321',
            'expires_at' => now()->addMinutes(10),
            'is_verified' => true,
        ]);

        $response = $this->post(route('password.reset-otp'), [
            'email' => 'guest@example.com',
            'otp_code' => '654321',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_authenticated_user_can_send_change_password_otp()
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->actingAs($user)->post(route('password.change-send-otp'));

        $response->assertRedirect();
        $this->assertDatabaseHas('password_reset_otps', [
            'email' => 'user@example.com',
        ]);
    }

    public function test_authenticated_user_can_update_password_with_otp()
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('current-pass'),
        ]);

        PasswordResetOtp::create([
            'email' => 'user@example.com',
            'otp_code' => '123456',
            'expires_at' => now()->addMinutes(10),
            'is_verified' => false,
        ]);

        $response = $this->actingAs($user)->put(route('password.update-otp'), [
            'current_password' => 'current-pass',
            'otp_code' => '123456',
            'password' => 'new-updated-pass',
            'password_confirmation' => 'new-updated-pass',
        ]);

        $response->assertRedirect();
        $this->assertTrue(Hash::check('new-updated-pass', $user->fresh()->password));
    }
}
