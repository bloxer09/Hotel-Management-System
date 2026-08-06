import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen min-h-dvh flex-col items-center justify-center bg-[#0a0f1a] px-4 py-8">
            <div className="w-full max-w-md">
                <div className="mb-6 flex justify-center">
                    <Link href="/">
                        <img
                            src="/images/logo.jpg"
                            alt="Property Logo"
                            className="h-16 w-16 object-contain rounded-xl border border-[#334155]/40 bg-white p-0.5 shadow-xl"
                            onError={(e) => { e.currentTarget.style.display = 'none'; }}
                        />
                    </Link>
                </div>
                <div className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl p-6 sm:p-8">
                    {children}
                </div>
            </div>
        </div>
    );
}
