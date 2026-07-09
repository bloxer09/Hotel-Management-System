import { Head, useForm } from '@inertiajs/react';

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
        <div className="min-h-screen flex items-center justify-center bg-[#0a0f1a] relative px-4 overflow-hidden">
            <Head title="Sign In — Uptown Pension House" />

            {/* Background Ambient Glows */}
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-amber-700/10 rounded-full blur-3xl opacity-60" />
            </div>

            <div className="w-full max-w-md relative z-10 flex flex-col gap-6">
                
                {/* Logo & Brand Header */}
                <div className="text-center">
                    <img
                        src="/images/logo.jpg"
                        alt="Uptown Pension House"
                        className="w-32 mx-auto drop-shadow-xl rounded-2xl mb-4 border border-[#334155]/40"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }}
                    />
                    <h2 className="text-lg font-outfit font-black text-slate-100 tracking-tight">Property Management System</h2>
                    <p className="text-xs text-slate-500 mt-1">Uptown Pension House</p>
                </div>

                {status && (
                    <div className="p-3 rounded-xl bg-emerald-900/30 border border-emerald-700/40 text-emerald-300 text-xs font-medium text-center">
                        {status}
                    </div>
                )}

                {/* Main Card */}
                <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl p-6 sm:p-8">
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label htmlFor="username" className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">
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
                                placeholder="Enter username"
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-4 py-2.5 text-xs focus:outline-none focus:border-amber-500/65 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder:text-slate-650"
                            />
                            {errors.username && <p className="mt-1 text-[11px] text-red-400">{errors.username}</p>}
                        </div>

                        <div>
                            <label htmlFor="password" className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                autoComplete="current-password"
                                onChange={e => setData('password', e.target.value)}
                                placeholder="Enter password"
                                className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-slate-100 px-4 py-2.5 text-xs focus:outline-none focus:border-amber-500/65 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder:text-slate-650"
                            />
                            {errors.password && <p className="mt-1 text-[11px] text-red-400">{errors.password}</p>}
                        </div>

                        <div className="flex items-center">
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="rounded border-slate-600 bg-[#0f172a] text-amber-500 focus:ring-amber-500"
                                />
                                <span className="text-[11px] text-slate-400">Remember me</span>
                            </label>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full py-2.5 mt-2 bg-gradient-to-r from-amber-700 to-amber-600 hover:from-amber-600 hover:to-amber-500 rounded-xl text-white font-bold tracking-wide font-outfit text-xs shadow-lg shadow-amber-900/30 disabled:opacity-50 transition-all duration-200"
                        >
                            {processing ? 'Signing in…' : 'Sign In'}
                        </button>
                    </form>
                </div>

                {/* Quick Demo Access */}
                <div className="p-4 rounded-xl bg-[#1e293b]/40 border border-[#334155]/40 text-center">
                    <p className="text-[9px] text-slate-500 font-bold uppercase tracking-widest mb-2.5">Quick Demo Access</p>
                    <div className="flex flex-wrap gap-2 justify-center">
                        {[
                            { label: 'Admin', username: 'admin' },
                            { label: 'Front Desk', username: 'frontdesk1' },
                            { label: 'Cashier', username: 'cashier1' },
                            { label: 'Housekeeper', username: 'housekeeping1' },
                        ].map(({ label, username }) => (
                            <button
                                key={username}
                                type="button"
                                onClick={() => quickFill(username, 'password')}
                                className="px-2.5 py-1.5 rounded-lg text-[10px] font-bold bg-[#1e293b] border border-[#334155]/60 text-slate-400 hover:text-slate-200 hover:border-brand-500/40 transition-all active:scale-95"
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

            </div>
        </div>
    );
}
