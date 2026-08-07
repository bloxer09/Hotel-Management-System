import { Transition } from '@headlessui/react';
import { useForm, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Mail, ShieldCheck } from 'lucide-react';

export default function UpdatePasswordForm({ className = '' }) {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();
    const [otpSent, setOtpSent] = useState(false);
    const [otpSending, setOtpSending] = useState(false);
    const [otpMessage, setOtpMessage] = useState('');

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        otp_code: '',
        password: '',
        password_confirmation: '',
    });

    const handleSendOtp = () => {
        setOtpSending(true);
        setOtpMessage('');
        router.post(route('password.change-send-otp'), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setOtpSending(false);
                setOtpSent(true);
                setOtpMessage('A 6-digit OTP code has been sent to your email address.');
            },
            onError: () => {
                setOtpSending(false);
            }
        });
    };

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update-otp'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOtpSent(false);
                setOtpMessage('');
            },
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    if (passwordInput.current) passwordInput.current.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    if (currentPasswordInput.current) currentPasswordInput.current.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-outfit font-black text-slate-100">
                    Update Password (Gmail OTP Verification)
                </h2>

                <p className="mt-1 text-xs text-slate-400 font-medium">
                    To change your password, request a 6-digit OTP verification code sent to your registered email address.
                </p>
            </header>

            {otpMessage && (
                <div className="mt-4 p-3 rounded-xl bg-emerald-950/60 border border-emerald-500/40 text-emerald-300 text-xs font-semibold">
                    {otpMessage}
                </div>
            )}

            <form onSubmit={updatePassword} className="mt-6 space-y-4">
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Current Password *</label>
                    <input
                        id="current_password"
                        ref={currentPasswordInput}
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        type="password"
                        required
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        autoComplete="current-password"
                    />
                    {errors.current_password && <span className="text-[10px] text-red-400 font-semibold">{errors.current_password}</span>}
                </div>

                {/* OTP Verification Code Section */}
                <div className="flex flex-col gap-1.5 p-4 rounded-xl bg-[#0f172a]/60 border border-[#334155]">
                    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                        <div>
                            <label className="text-[10px] uppercase font-bold text-brand-400 tracking-wider block">Gmail OTP Code *</label>
                            <span className="text-[11px] text-slate-400">Request code sent to your email to verify password update.</span>
                        </div>
                        <button
                            type="button"
                            onClick={handleSendOtp}
                            disabled={otpSending}
                            className="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-brand-300 border border-[#334155] rounded-lg text-xs font-bold transition-all shrink-0 flex items-center gap-1.5"
                        >
                            <Mail size={13} /> {otpSending ? 'Sending OTP...' : otpSent ? 'Resend OTP' : 'Send OTP to Email'}
                        </button>
                    </div>
                    <input
                        id="otp_code"
                        value={data.otp_code}
                        onChange={(e) => setData('otp_code', e.target.value.replace(/\D/g, ''))}
                        type="text"
                        maxLength="6"
                        required
                        placeholder="123456"
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500 font-mono tracking-widest text-emerald-400 font-bold"
                    />
                    {errors.otp_code && <span className="text-[10px] text-red-400 font-semibold">{errors.otp_code}</span>}
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">New Password *</label>
                    <input
                        id="password"
                        ref={passwordInput}
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        type="password"
                        required
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        autoComplete="new-password"
                    />
                    {errors.password && <span className="text-[10px] text-red-400 font-semibold">{errors.password}</span>}
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Confirm New Password *</label>
                    <input
                        id="password_confirmation"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        type="password"
                        required
                        className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        autoComplete="new-password"
                    />
                    {errors.password_confirmation && <span className="text-[10px] text-red-400 font-semibold">{errors.password_confirmation}</span>}
                </div>

                <div className="flex items-center gap-4 pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-lg hover:shadow-brand-600/20 active:scale-95 transition-all flex items-center gap-2"
                    >
                        <ShieldCheck size={16} /> Update Password with OTP
                    </button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out duration-300"
                        enterFrom="opacity-0 translate-x-2"
                        enterTo="opacity-100 translate-x-0"
                        leave="transition ease-in-out duration-300"
                        leaveTo="opacity-0 translate-x-2"
                    >
                        <p className="text-xs text-emerald-400 font-bold font-outfit">Password updated successfully!</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
