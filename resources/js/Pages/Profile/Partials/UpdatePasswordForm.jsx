import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

export default function UpdatePasswordForm({ className = '' }) {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

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
        password: '',
        password_confirmation: '',
    });

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-outfit font-black text-slate-100">
                    Update Password
                </h2>

                <p className="mt-1 text-xs text-slate-400 font-medium">
                    Ensure your account is using a long, random password to stay secure.
                </p>
            </header>

            <form onSubmit={updatePassword} className="mt-6 space-y-4">
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Current Password</label>
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

                <div className="flex flex-col gap-1">
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">New Password</label>
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
                    <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Confirm New Password</label>
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
                        className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-lg hover:shadow-brand-600/20 active:scale-95 transition-all"
                    >
                        Update Password
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
