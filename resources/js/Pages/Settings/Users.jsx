import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Users2, PlusCircle, Edit2, Shield, X, User } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

export default function Users({ users }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [selectedUser, setSelectedUser] = useState(null);

    // Form: Create Staff
    const addForm = useForm({
        name: '',
        username: '',
        password: '',
        role: 'front_desk',
        is_active: true,
        photo: null
    });

    // Form: Edit Staff
    const editForm = useForm({
        name: '',
        role: 'front_desk',
        is_active: true,
        password: '', // Optional password reset
        photo: null,
        remove_photo: false,
        _method: 'PATCH'
    });

    const openEditModal = (u) => {
        setSelectedUser(u);
        editForm.setData({
            name: u.name,
            role: u.role,
            is_active: u.is_active ? true : false,
            password: '',
            photo: null,
            remove_photo: false,
            _method: 'PATCH'
        });
        setIsEditOpen(true);
    };

    const handleAddSubmit = (e) => {
        e.preventDefault();
        addForm.post(route('settings.users.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                addForm.reset();
            }
        });
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        editForm.post(route('settings.users.update', selectedUser.id), {
            onSuccess: () => {
                setIsEditOpen(false);
            }
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Staff Accounts" />

            <div className="flex flex-col gap-8">

                {/* Header title */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-outfit font-extrabold tracking-tight text-slate-100">
                            Staff Accounts
                        </h1>
                        <p className="text-sm text-slate-400 font-medium mt-1">Add, update, and manage role-based credentials and operational permissions for staff profiles.</p>
                    </div>

                    <button
                        onClick={() => setIsAddOpen(true)}
                        className="inline-flex items-center gap-2 px-5 py-3 bg-brand-600 hover:bg-brand-500 rounded-xl text-slate-50 font-outfit font-extrabold text-xs tracking-wider shadow-lg hover:shadow-brand-600/20 transition-all self-start"
                    >
                        <PlusCircle size={16} /> Add Staff Account
                    </button>
                </div>

                {/* Users Listing Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {users.map((u) => (
                        <div
                            key={u.id}
                            className={`p-6 rounded-2xl bg-[#1e293b] border shadow-xl flex flex-col justify-between gap-4 transition-all hover:scale-[1.01] ${u.is_active ? 'border-[#334155]' : 'border-red-950/80'
                                }`}
                        >
                            <div>
                                {/* Row Header */}
                                <div className="flex items-center justify-between border-b border-[#334155]/60 pb-3">
                                    <span className="text-[10px] uppercase font-mono font-bold bg-[#0f172a] text-slate-300 border border-[#334155]/50 px-2 py-0.5 rounded capitalize">
                                        {u.role.replace('_', ' ')}
                                    </span>

                                    <span className={`px-2 py-0.5 rounded text-[10px] font-extrabold uppercase ${u.is_active
                                            ? 'bg-emerald-950/50 border border-emerald-800/40 text-emerald-400'
                                            : 'bg-red-950/50 border border-red-800/40 text-red-400'
                                        }`}>
                                        {u.is_active ? 'Active Profile' : 'Suspended'}
                                    </span>
                                </div>

                                {/* Body details */}
                                <div className="mt-3 flex items-center gap-4">
                                    {u.avatar_url ? (
                                        <img src={u.avatar_url} alt={u.name} className="w-12 h-12 rounded-full object-cover border border-[#334155]" />
                                    ) : (
                                        <div className="w-12 h-12 rounded-full bg-brand-600/20 border border-brand-500/35 text-brand-400 flex items-center justify-center font-outfit text-sm font-black shrink-0">
                                            {u.name ? u.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() : '?'}
                                        </div>
                                    )}
                                    <div className="flex-1 min-w-0">
                                        <h3 className="font-outfit font-black text-slate-100 text-base leading-tight truncate">
                                            {u.name}
                                        </h3>
                                        <span className="text-xs text-slate-400 font-mono block mt-1">Username: <span className="font-bold text-slate-200">@{u.username}</span></span>
                                    </div>
                                </div>
                            </div>

                            {/* Footer edit button */}
                            <div className="pt-3 border-t border-[#334155]/55 flex items-center justify-between">
                                <span className="text-[10px] text-slate-500 font-medium font-mono">System Account UID-{u.id}</span>

                                <button
                                    onClick={() => openEditModal(u)}
                                    className="px-3 py-1.5 bg-[#0f172a]/60 hover:bg-[#334155] border border-[#334155] text-slate-300 hover:text-slate-50 font-outfit font-extrabold text-[10px] rounded-lg flex items-center gap-1 transition-colors"
                                >
                                    <Edit2 size={10} /> Edit Credentials
                                </button>
                            </div>
                        </div>
                    ))}
                </div>

                {/* MODAL: ADD EMPLOYEE */}
                <AnimatePresence>
                    {isAddOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsAddOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <h2 className="font-outfit font-black text-slate-100 text-lg">Add Staff Account</h2>
                                    <button onClick={() => setIsAddOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleAddSubmit} className="p-6 space-y-4">
                                    {/* Name */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Employee Name</label>
                                        <input
                                            type="text"
                                            value={addForm.data.name}
                                            onChange={e => addForm.setData('name', e.target.value)}
                                            placeholder="John Doe"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {addForm.errors.name && <span className="text-[10px] text-red-400 font-semibold">{addForm.errors.name}</span>}
                                    </div>

                                    {/* Username */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Staff Username</label>
                                        <input
                                            type="text"
                                            value={addForm.data.username}
                                            onChange={e => addForm.setData('username', e.target.value)}
                                            placeholder="johndoe123"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {addForm.errors.username && <span className="text-[10px] text-red-400 font-semibold">{addForm.errors.username}</span>}
                                    </div>

                                    {/* Password */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Secure Password</label>
                                        <input
                                            type="password"
                                            value={addForm.data.password}
                                            onChange={e => addForm.setData('password', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                        {addForm.errors.password && <span className="text-[10px] text-red-400 font-semibold">{addForm.errors.password}</span>}
                                    </div>

                                    {/* Role Selection */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Assigned Role Authorization</label>
                                        <select
                                            value={addForm.data.role}
                                            onChange={e => addForm.setData('role', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        >
                                            <option value="admin">Administrator (Admin)</option>
                                            <option value="front_desk">Front Desk Agent</option>
                                            <option value="cashier">Drawer Cashier</option>
                                            <option value="housekeeping">Room Housekeeping</option>
                                        </select>
                                    </div>

                                    {/* Profile Photo */}
                                    <div className="flex flex-col gap-1.5 pt-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Profile Photo (Optional)</label>
                                        <div className="flex items-center gap-4">
                                            {addForm.data.photo ? (
                                                <img src={URL.createObjectURL(addForm.data.photo)} alt="Preview" className="w-12 h-12 rounded-full object-cover border border-brand-500/40" />
                                            ) : (
                                                <div className="w-12 h-12 rounded-full bg-[#0f172a] border border-[#334155] flex items-center justify-center text-slate-500 text-xs font-bold font-mono">None</div>
                                            )}
                                            <input
                                                type="file"
                                                accept="image/*"
                                                onChange={e => addForm.setData('photo', e.target.files[0])}
                                                className="text-xs text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#0f172a] file:text-brand-400 hover:file:bg-[#334155] cursor-pointer"
                                            />
                                        </div>
                                        {addForm.errors.photo && <span className="text-[10px] text-red-405 font-semibold">{addForm.errors.photo}</span>}
                                    </div>

                                    {/* Active checkbox */}
                                    <label className="flex items-center gap-2 cursor-pointer pt-2">
                                        <input
                                            type="checkbox"
                                            checked={addForm.data.is_active}
                                            onChange={e => addForm.setData('is_active', e.target.checked)}
                                            className="rounded bg-[#0f172a] border-[#334155] text-brand-600 focus:ring-0 focus:ring-offset-0"
                                        />
                                        <span className="text-xs font-semibold text-slate-300">Set Account State as Active</span>
                                    </label>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsAddOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={addForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Add Staff</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

                {/* MODAL: EDIT EMPLOYEE */}
                <AnimatePresence>
                    {isEditOpen && selectedUser && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 0.5 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-black" onClick={() => setIsEditOpen(false)} />
                            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="bg-[#1e293b] border border-[#334155] rounded-2xl w-full max-w-md shadow-2xl relative z-10 overflow-hidden">
                                <div className="p-6 border-b border-[#334155] flex items-center justify-between">
                                    <div className="flex flex-col">
                                        <h2 className="font-outfit font-black text-slate-100 text-lg">Modify credentials</h2>
                                        <span className="text-[10px] text-slate-500">Username profile: @{selectedUser.username}</span>
                                    </div>
                                    <button onClick={() => setIsEditOpen(false)} className="text-slate-400 hover:text-slate-100"><X size={18} /></button>
                                </div>

                                <form onSubmit={handleEditSubmit} className="p-6 space-y-4">
                                    {/* Name */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Employee Name</label>
                                        <input
                                            type="text"
                                            value={editForm.data.name}
                                            onChange={e => editForm.setData('name', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                            required
                                        />
                                    </div>

                                    {/* Role Selection */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Assigned Role Authorization</label>
                                        <select
                                            value={editForm.data.role}
                                            onChange={e => editForm.setData('role', e.target.value)}
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        >
                                            <option value="admin">Administrator (Admin)</option>
                                            <option value="front_desk">Front Desk Agent</option>
                                            <option value="cashier">Drawer Cashier</option>
                                            <option value="housekeeping">Room Housekeeping</option>
                                        </select>
                                    </div>

                                    {/* Optional Reset Password */}
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Reset password (Leave blank to keep current)</label>
                                        <input
                                            type="password"
                                            value={editForm.data.password}
                                            onChange={e => editForm.setData('password', e.target.value)}
                                            placeholder="••••••"
                                            className="w-full bg-[#0f172a] border border-[#334155] rounded-xl text-xs text-slate-100 px-4 py-2.5 focus:outline-none focus:border-brand-500"
                                        />
                                    </div>

                                    {/* Profile Photo */}
                                    <div className="flex flex-col gap-1.5 pt-1">
                                        <label className="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Profile Photo</label>
                                        <div className="flex items-center gap-4">
                                            {editForm.data.photo ? (
                                                <img src={URL.createObjectURL(editForm.data.photo)} alt="Preview" className="w-12 h-12 rounded-full object-cover border border-brand-500/40" />
                                            ) : selectedUser.avatar_url && !editForm.data.remove_photo ? (
                                                <img src={selectedUser.avatar_url} alt="Current" className="w-12 h-12 rounded-full object-cover border border-brand-500/40" />
                                            ) : (
                                                <div className="w-12 h-12 rounded-full bg-[#0f172a] border border-[#334155] flex items-center justify-center text-slate-500 text-xs font-bold font-mono">None</div>
                                            )}
                                            <div className="flex flex-col gap-2">
                                                <input
                                                    type="file"
                                                    accept="image/*"
                                                    onChange={e => editForm.setData(prev => ({ ...prev, photo: e.target.files[0], remove_photo: false }))}
                                                    className="text-xs text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#0f172a] file:text-brand-400 hover:file:bg-[#334155] cursor-pointer"
                                                />
                                                {(selectedUser.avatar_url || editForm.data.photo) && !editForm.data.remove_photo && (
                                                    <button
                                                        type="button"
                                                        onClick={() => editForm.setData(prev => ({ ...prev, photo: null, remove_photo: true }))}
                                                        className="text-[10px] text-red-400 hover:text-red-300 font-bold self-start"
                                                    >
                                                        Remove Photo
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {editForm.errors.photo && <span className="text-[10px] text-red-405 font-semibold">{editForm.errors.photo}</span>}
                                    </div>

                                    {/* Active checkbox */}
                                    <label className="flex items-center gap-2 cursor-pointer pt-2">
                                        <input
                                            type="checkbox"
                                            checked={editForm.data.is_active}
                                            onChange={e => editForm.setData('is_active', e.target.checked)}
                                            className="rounded bg-[#0f172a] border-[#334155] text-brand-600 focus:ring-0 focus:ring-offset-0"
                                        />
                                        <span className="text-xs font-semibold text-slate-300">Set Account State as Active</span>
                                    </label>

                                    <div className="pt-4 border-t border-[#334155]/60 flex justify-end gap-3">
                                        <button type="button" onClick={() => setIsEditOpen(false)} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold font-outfit">Cancel</button>
                                        <button type="submit" disabled={editForm.processing} className="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-slate-50 rounded-xl text-xs font-bold font-outfit shadow-md">Save Changes</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            </div>
        </AuthenticatedLayout>
    );
}
