import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function DeleteUserForm({ className = '' }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset();
    };

    return (
        <section className={`space-y-6 ${className}`}>
            <header>
                <h2 className="text-lg font-outfit font-black text-slate-100">
                    Delete Account
                </h2>

                <p className="mt-1 text-xs text-slate-400 font-medium">
                    Once your account is deleted, all of its resources and data
                    will be permanently deleted. Before deleting your account,
                    please download any data or information that you wish to
                    retain.
                </p>
            </header>

            <button
                type="button"
                onClick={confirmUserDeletion}
                className="px-5 py-2.5 bg-red-950/20 border border-red-900/30 text-red-400 hover:bg-red-800/30 rounded-xl text-xs font-bold font-outfit transition-all shadow-md"
            >
                Delete Account
            </button>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6 space-y-4">
                    <h2 className="text-lg font-outfit font-black text-slate-100">
                        Are you sure you want to delete your account?
                    </h2>

                    <p className="text-xs text-slate-400 font-medium">
                        Once your account is deleted, all of its resources and
                        data will be permanently deleted. Please enter your
                        password to confirm you would like to permanently delete
                        your account.
                    </p>

                    <div className="mt-6 flex flex-col gap-1">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Confirm Password</label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            placeholder="Enter your password"
                            className="w-full sm:w-3/4 bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        />
                        {errors.password && <span className="text-[10px] text-red-405 font-semibold">{errors.password}</span>}
                    </div>

                    <div className="mt-6 flex justify-end gap-3 pt-4 border-t border-[#334155]/60">
                        <button
                            type="button"
                            onClick={closeModal}
                            className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-slate-200 border border-[#334155] rounded-xl text-xs font-bold font-outfit transition-all shadow-md"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 bg-red-650 hover:bg-red-500 text-white rounded-xl text-xs font-bold font-outfit shadow-md transition-all disabled:opacity-50"
                        >
                            Confirm Delete Account
                        </button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
