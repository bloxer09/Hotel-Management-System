import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';

export default function UpdateProfileInformation({
    className = '',
}) {
    const user = usePage().props.auth.user;

    const { data, setData, post, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            photo: null,
            remove_photo: false,
            _method: 'PATCH'
        });

    const submit = (e) => {
        e.preventDefault();
        post(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-outfit font-black text-slate-100">
                    Profile Information
                </h2>

                <p className="mt-1 text-xs text-slate-400 font-medium">
                    Update your account's profile information, avatar photo, and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 flex flex-col md:flex-row gap-8">
                {/* Left Side: Avatar Upload Section */}
                <div className="flex flex-col items-center gap-3 shrink-0">
                    <span className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Profile Picture</span>
                    
                    {data.photo ? (
                        <img src={URL.createObjectURL(data.photo)} alt="Preview" className="w-24 h-24 rounded-full object-cover border-2 border-brand-500 shadow-xl" />
                    ) : user.avatar_url && !data.remove_photo ? (
                        <img src={user.avatar_url} alt="Avatar" className="w-24 h-24 rounded-full object-cover border-2 border-brand-500 shadow-xl" />
                    ) : (
                        <div className="w-24 h-24 rounded-full bg-[#0f172a] border border-[#334155] flex items-center justify-center text-slate-500 text-2xl font-outfit font-black shadow-inner">
                            {user.name ? user.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() : '?'}
                        </div>
                    )}

                    <div className="flex flex-col items-center gap-1.5 mt-2">
                        <label className="px-4 py-2 bg-[#0f172a] hover:bg-[#334155] border border-[#334155] text-brand-400 hover:text-brand-300 text-xs font-bold font-outfit rounded-xl cursor-pointer transition-colors shadow-md">
                            Change Image
                            <input
                                type="file"
                                accept="image/*"
                                onChange={e => setData(prev => ({ ...prev, photo: e.target.files[0], remove_photo: false }))}
                                className="hidden"
                            />
                        </label>

                        {(user.avatar_url || data.photo) && !data.remove_photo && (
                            <button
                                type="button"
                                onClick={() => setData(prev => ({ ...prev, photo: null, remove_photo: true }))}
                                className="text-[10px] text-red-400 hover:text-red-300 font-bold mt-1"
                            >
                                Remove Image
                            </button>
                        )}
                    </div>
                    {errors.photo && <span className="text-[10px] text-red-400 font-semibold mt-1">{errors.photo}</span>}
                </div>

                {/* Right Side: Text Fields */}
                <div className="flex-1 space-y-4">
                    <div className="flex flex-col gap-1">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Full Name</label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        />
                        {errors.name && <span className="text-[10px] text-red-400 font-semibold">{errors.name}</span>}
                    </div>

                    <div className="flex flex-col gap-1">
                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Email Address</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                        />
                        {errors.email && <span className="text-[10px] text-red-400 font-semibold">{errors.email}</span>}
                    </div>

                    <div className="flex items-center gap-4 pt-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-lg hover:shadow-brand-600/20 active:scale-95 transition-all"
                        >
                            Save Settings
                        </button>

                        <Transition
                            show={recentlySuccessful}
                            enter="transition ease-in-out duration-300"
                            enterFrom="opacity-0 translate-x-2"
                            enterTo="opacity-100 translate-x-0"
                            leave="transition ease-in-out duration-300"
                            leaveTo="opacity-0 translate-x-2"
                        >
                            <p className="text-xs text-emerald-400 font-bold font-outfit">Saved successfully!</p>
                        </Transition>
                    </div>
                </div>
            </form>
        </section>
    );
}
