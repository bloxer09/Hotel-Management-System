import { Head, Link, useForm } from '@inertiajs/react';
import { Hotel, ClipboardList, Coins, Sparkles } from 'lucide-react';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const quickFill = (username, password) => {
        setData({ username, password, remember: false });
    };

    return (
        <div className="min-h-screen flex bg-[#0a0f1a]">
            <Head title="Sign In — Uptown Pension House" />

            {/* ── Left panel: Logo / Brand (visible md+) ── */}
            <div className="hidden md:flex flex-col items-center justify-center flex-1 relative overflow-hidden bg-[#0d1520]">

                {/* Warm ambient glows matching logo palette */}
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute top-1/4 left-1/4 w-80 h-80 bg-amber-700/10 rounded-full blur-3xl" />
                    <div className="absolute bottom-1/4 right-1/4 w-96 h-96 bg-amber-900/10 rounded-full blur-3xl" />
                    <div className="absolute top-0 right-0 w-64 h-64 bg-brand-900/10 rounded-full blur-3xl" />
                </div>

                {/* Subtle grid pattern */}
                <div className="absolute inset-0 opacity-[0.03]"
                    style={{ backgroundImage: 'radial-gradient(circle, #c4a265 1px, transparent 1px)', backgroundSize: '28px 28px' }}
                />

                <div className="relative z-10 flex flex-col items-center gap-8 px-12 text-center max-w-md">
                    {/* Logo */}
                    <img
                        src="/images/logo.jpg"
                        alt="Uptown Pension House"
                        className="w-64 drop-shadow-2xl"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }}
                    />

                    {/* Tagline */}
                    <div>
                        <div className="h-px w-32 bg-gradient-to-r from-transparent via-amber-600/40 to-transparent mx-auto mb-4" />
                        <p className="text-slate-400 text-sm font-light italic tracking-wide">
                            Property Management System
                        </p>
                        <div className="h-px w-32 bg-gradient-to-r from-transparent via-amber-600/40 to-transparent mx-auto mt-4" />
                    </div>

                    {/* Feature pills */}
                    <div className="grid grid-cols-2 gap-3 w-full mt-4">
                        {[
                            { icon: <Hotel size={14} className="text-amber-500" />, text: 'Room Management' },
                            { icon: <ClipboardList size={14} className="text-amber-500" />, text: 'Booking & Check-In' },
                            { icon: <Coins size={14} className="text-amber-500" />, text: 'Billing & Reports' },
                            { icon: <Sparkles size={14} className="text-amber-500" />, text: 'Housekeeping' },
                        ].map(({ icon, text }) => (
                            <div key={text} className="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-slate-800/50 border border-slate-700/40 text-xs text-slate-400">
                                <span className="flex items-center justify-center">{icon}</span> {text}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* ── Right panel: Login form ── */}
            <div className="flex flex-col items-center justify-center w-full md:w-[460px] md:max-w-[460px] px-6 py-10 relative">

                {/* Mobile ambient */}
                <div className="absolute inset-0 pointer-events-none md:hidden overflow-hidden">
                    <div className="absolute top-0 left-1/2 -translate-x-1/2 w-96 h-96 bg-amber-700/8 rounded-full blur-3xl" />
                </div>

                <div className="relative w-full max-w-sm">

                    {/* Logo shown on mobile only */}
                    <div className="md:hidden text-center mb-8">
                        <img
                            src="/images/logo.jpg"
                            alt="Uptown Pension House"
                            className="w-48 mx-auto drop-shadow-xl"
                            onError={(e) => {
                                e.currentTarget.style.display = 'none';
                                e.currentTarget.nextElementSibling.style.display = 'block';
                            }}
                        />
                        <h1 className="hidden text-xl font-outfit font-extrabold text-slate-100 mt-2">
                            Uptown Pension House
                        </h1>
                    </div>

                    {/* Sign-in heading */}
                    <div className="mb-7">
                        <h2 className="text-2xl font-outfit font-black text-slate-100 tracking-tight">Welcome back</h2>
                        <p className="text-sm text-slate-500 mt-1">Sign in to your PMS account</p>
                    </div>

                    {status && (
                        <div className="mb-5 p-3 rounded-xl bg-emerald-900/30 border border-emerald-700/40 text-emerald-300 text-sm font-medium">
                            {status}
                        </div>
                    )}

                    {/* Main card */}
                    <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl p-7">
                        <form onSubmit={submit} className="space-y-5">

                            <div>
                                <label htmlFor="username" className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">
                                    Username
                                </label>
                                <input
                                    id="username"
                                    type="text"
                                    name="username"
                                    value={data.username}
                                    autoComplete="username"
                                    autoFocus
                                    onChange={e => setData('username', e.target.value)}
                                    placeholder="Enter your username"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-4 py-3 text-sm focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder:text-slate-600"
                                />
                                {errors.username && <p className="mt-1.5 text-xs text-red-400">{errors.username}</p>}
                            </div>

                            <div>
                                <label htmlFor="password" className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">
                                    Password
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    autoComplete="current-password"
                                    onChange={e => setData('password', e.target.value)}
                                    placeholder="Enter your password"
                                    className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-4 py-3 text-sm focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder:text-slate-600"
                                />
                                {errors.password && <p className="mt-1.5 text-xs text-red-400">{errors.password}</p>}
                            </div>

                            <div className="flex items-center justify-between">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        checked={data.remember}
                                        onChange={e => setData('remember', e.target.checked)}
                                        className="rounded border-slate-600 bg-[#0f172a] text-amber-500 focus:ring-amber-500"
                                    />
                                    <span className="text-xs text-slate-400">Remember me</span>
                                </label>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3 bg-gradient-to-r from-amber-700 to-amber-600 hover:from-amber-600 hover:to-amber-500 rounded-xl text-white font-extrabold tracking-wide font-outfit text-sm shadow-lg shadow-amber-900/30 disabled:opacity-50 transition-all duration-200"
                            >
                                {processing ? 'Signing in…' : 'Sign In'}
                            </button>
                        </form>
                    </div>

                    {/* Quick Fill Demo Accounts */}
                    <div className="mt-5 p-4 rounded-2xl bg-[#1e293b]/60 border border-[#334155]/50 backdrop-blur-sm">
                        <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest text-center mb-3">Quick Demo Access</p>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            {[
                                { label: 'Admin', username: 'admin', password: 'password', color: 'brand' },
                                { label: 'Front Desk', username: 'frontdesk1', password: 'password', color: 'emerald' },
                                { label: 'Cashier', username: 'cashier1', password: 'password', color: 'amber' },
                                { label: 'Housekeeping', username: 'housekeeping1', password: 'password', color: 'slate' },
                            ].map(({ label, username, password, color }) => (
                                <button
                                    key={username}
                                    type="button"
                                    onClick={() => quickFill(username, password)}
                                    className={`py-2 px-3 rounded-xl text-[10px] font-bold border transition-all hover:scale-105 bg-${color}-900/20 border-${color}-700/30 text-${color}-400 hover:bg-${color}-800/30`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <p className="text-[10px] text-slate-600 text-center mt-2">All demo passwords: <span className="font-mono text-slate-500">password</span></p>
                    </div>

                </div>
            </div>
        </div>
    );
}
