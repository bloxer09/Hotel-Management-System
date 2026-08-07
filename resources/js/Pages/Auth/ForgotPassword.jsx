import React, { useState } from 'react';
import Button from '@/Components/UI/Button';
import Input from '@/Components/UI/Input';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Mail, KeyRound, ShieldCheck, ArrowRight, RefreshCw } from 'lucide-react';

export default function ForgotPassword({ status }) {
    const [step, setStep] = useState(1); // 1: Email, 2: OTP, 3: New Password

    const form = useForm({
        email: '',
        otp_code: '',
        password: '',
        password_confirmation: '',
    });

    const handleSendOtp = (e) => {
        e.preventDefault();
        form.post(route('password.send-otp'), {
            onSuccess: () => setStep(2),
        });
    };

    const handleVerifyOtp = (e) => {
        e.preventDefault();
        form.post(route('password.verify-otp'), {
            onSuccess: () => setStep(3),
        });
    };

    const handleResetPassword = (e) => {
        e.preventDefault();
        form.post(route('password.reset-otp'));
    };

    return (
        <GuestLayout>
            <Head title="Forgot Password — OTP Verification" />

            {/* Step Indicators */}
            <div className="flex items-center justify-between mb-6 px-2">
                <div className={`flex items-center gap-2 text-xs font-bold ${step >= 1 ? 'text-brand-400' : 'text-slate-500'}`}>
                    <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[10px] ${step >= 1 ? 'bg-brand-500 text-white' : 'bg-slate-800 text-slate-400'}`}>1</div>
                    Email
                </div>
                <div className={`h-0.5 flex-1 mx-2 ${step >= 2 ? 'bg-brand-500' : 'bg-slate-800'}`} />
                <div className={`flex items-center gap-2 text-xs font-bold ${step >= 2 ? 'text-brand-400' : 'text-slate-500'}`}>
                    <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[10px] ${step >= 2 ? 'bg-brand-500 text-white' : 'bg-slate-800 text-slate-400'}`}>2</div>
                    OTP Code
                </div>
                <div className={`h-0.5 flex-1 mx-2 ${step >= 3 ? 'bg-brand-500' : 'bg-slate-800'}`} />
                <div className={`flex items-center gap-2 text-xs font-bold ${step >= 3 ? 'text-brand-400' : 'text-slate-500'}`}>
                    <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[10px] ${step >= 3 ? 'bg-brand-500 text-white' : 'bg-slate-800 text-slate-400'}`}>3</div>
                    New Password
                </div>
            </div>

            {status && (
                <div className="mb-4 p-3 rounded-xl bg-emerald-950/60 border border-emerald-500/40 text-emerald-300 text-xs font-semibold text-center">
                    {status}
                </div>
            )}

            {/* STEP 1: Email Request */}
            {step === 1 && (
                <form onSubmit={handleSendOtp} className="space-y-4">
                    <div className="text-xs text-slate-400 leading-relaxed mb-4">
                        Enter your registered email address. We will send a 6-digit OTP verification code to your inbox via Gmail / SMTP.
                    </div>

                    <Input
                        id="email"
                        type="email"
                        name="email"
                        label="Email Address"
                        value={form.data.email}
                        error={form.errors.email}
                        isFocused={true}
                        onChange={(e) => form.setData('email', e.target.value)}
                        placeholder="you@example.com"
                    />

                    <div className="flex items-center justify-end pt-2">
                        <Button type="submit" isLoading={form.processing} className="w-full justify-center">
                            <Mail size={16} className="mr-2" /> Send OTP to Gmail
                        </Button>
                    </div>
                </form>
            )}

            {/* STEP 2: OTP Verification */}
            {step === 2 && (
                <form onSubmit={handleVerifyOtp} className="space-y-4">
                    <div className="text-xs text-slate-400 leading-relaxed mb-4">
                        We sent a 6-digit verification code to <span className="font-bold text-slate-200">{form.data.email}</span>. Please enter it below.
                    </div>

                    <Input
                        id="otp_code"
                        type="text"
                        name="otp_code"
                        label="6-Digit OTP Code"
                        maxLength="6"
                        value={form.data.otp_code}
                        error={form.errors.otp_code}
                        isFocused={true}
                        onChange={(e) => form.setData('otp_code', e.target.value.replace(/\D/g, ''))}
                        placeholder="123456"
                    />

                    <div className="flex items-center justify-between pt-2 gap-3">
                        <button
                            type="button"
                            onClick={() => setStep(1)}
                            className="text-xs font-semibold text-slate-400 hover:text-slate-200 transition-colors"
                        >
                            Change Email
                        </button>
                        <Button type="submit" isLoading={form.processing}>
                            <ShieldCheck size={16} className="mr-2" /> Verify OTP Code
                        </Button>
                    </div>
                </form>
            )}

            {/* STEP 3: Reset Password */}
            {step === 3 && (
                <form onSubmit={handleResetPassword} className="space-y-4">
                    <div className="text-xs text-slate-400 leading-relaxed mb-4">
                        OTP code verified! Enter your new password below to reset your account credentials.
                    </div>

                    <Input
                        id="password"
                        type="password"
                        name="password"
                        label="New Password"
                        value={form.data.password}
                        error={form.errors.password}
                        isFocused={true}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />

                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        label="Confirm New Password"
                        value={form.data.password_confirmation}
                        error={form.errors.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />

                    <div className="flex items-center justify-end pt-2">
                        <Button type="submit" isLoading={form.processing} className="w-full justify-center">
                            <KeyRound size={16} className="mr-2" /> Reset Password
                        </Button>
                    </div>
                </form>
            )}
        </GuestLayout>
    );
}
