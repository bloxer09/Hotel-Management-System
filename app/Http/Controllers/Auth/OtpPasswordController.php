<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpPasswordResetMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class OtpPasswordController extends Controller
{
    /**
     * Send OTP for Forgot Password workflow.
     */
    public function sendForgotOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return back()->withErrors(['email' => 'We could not find a user with that email address.']);
        }

        $otpCode = sprintf('%06d', mt_rand(100000, 999999));

        PasswordResetOtp::where('email', $user->email)->delete();

        PasswordResetOtp::create([
            'email' => $user->email,
            'otp_code' => $otpCode,
            'expires_at' => now()->addMinutes(10),
            'is_verified' => false,
        ]);

        try {
            Mail::to($user->email)->send(new OtpPasswordResetMail($otpCode, $user->full_name ?? $user->username, 'Password Reset'));
        } catch (\Throwable $e) {
            // Log mail exception if mailer fails
            \Illuminate\Support\Facades\Log::error('OTP Mail dispatch failed: ' . $e->getMessage());
        }

        return back()->with('status', 'A 6-digit OTP code has been sent to your email address.');
    }

    /**
     * Verify OTP code for Forgot Password.
     */
    public function verifyForgotOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'string', 'size:6'],
        ]);

        $otpRecord = PasswordResetOtp::where('email', $request->email)
            ->where('otp_code', $request->otp_code)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return back()->withErrors(['otp_code' => 'Invalid or expired OTP verification code.']);
        }

        $otpRecord->update(['is_verified' => true]);

        return back()->with('status', 'OTP code verified successfully. Please enter your new password.');
    }

    /**
     * Complete Password Reset with verified OTP.
     */
    public function resetPasswordWithOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $otpRecord = PasswordResetOtp::where('email', $request->email)
            ->where('otp_code', $request->otp_code)
            ->where('is_verified', true)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return back()->withErrors(['otp_code' => 'OTP code verification expired. Please request a new OTP.']);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return back()->withErrors(['email' => 'User account not found.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        PasswordResetOtp::where('email', $user->email)->delete();

        return redirect()->route('login')->with('success', 'Your password has been reset successfully. Please log in.');
    }

    /**
     * Send OTP for authenticated user password change.
     */
    public function sendChangePasswordOtp(Request $request)
    {
        $user = $request->user();
        if (!$user->email) {
            return back()->withErrors(['email' => 'Your user account does not have a registered email address.']);
        }

        $otpCode = sprintf('%06d', mt_rand(100000, 999999));

        PasswordResetOtp::where('email', $user->email)->delete();

        PasswordResetOtp::create([
            'email' => $user->email,
            'otp_code' => $otpCode,
            'expires_at' => now()->addMinutes(10),
            'is_verified' => false,
        ]);

        try {
            Mail::to($user->email)->send(new OtpPasswordResetMail($otpCode, $user->full_name ?? $user->username, 'Password Change'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Change Password OTP Mail failed: ' . $e->getMessage());
        }

        return back()->with('status', 'A 6-digit OTP code has been sent to your email address.');
    }

    /**
     * Update password with OTP for authenticated user.
     */
    public function updatePasswordWithOtp(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'otp_code' => ['required', 'string', 'size:6'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $otpRecord = PasswordResetOtp::where('email', $user->email)
            ->where('otp_code', $request->otp_code)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return back()->withErrors(['otp_code' => 'Invalid or expired OTP verification code.']);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        PasswordResetOtp::where('email', $user->email)->delete();

        return back()->with('status', 'password-updated');
    }
}
