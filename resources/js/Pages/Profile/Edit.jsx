import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout>
            <Head title="Profile Settings" />

            <div className="flex flex-col gap-8 max-w-4xl mx-auto">
                {/* Title */}
                <div>
                    <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                        Profile Settings
                    </h1>
                    <p className="text-sm text-slate-400 font-medium mt-1">Manage your staff profile details, avatar image, and security password.</p>
                </div>

                <div className="space-y-6">
                    <div className="bg-[#1e293b] border border-[#334155] rounded-2xl p-6 md:p-8 shadow-xl">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-[#1e293b] border border-[#334155] rounded-2xl p-6 md:p-8 shadow-xl">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-[#1e293b] border border-[#334155] rounded-2xl p-6 md:p-8 shadow-xl border-red-950/40">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
