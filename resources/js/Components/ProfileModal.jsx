import React, { useState } from 'react';
import Modal from '@/Components/Modal';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import CustomSelect from '@/Components/CustomSelect';
import { User, KeyRound, X, Shield, ShieldCheck, Key, Lock, Eye, EyeOff, Save, CheckCircle2, AlertCircle, Camera, ChevronDown } from 'lucide-react';

export default function ProfileModal({ show, onClose }) {
    const [activeTab, setActiveTab] = useState('profile');

    const tabs = [
        {
            id: 'profile',
            name: 'Profile Details',
            description: 'Update public info & avatar',
            icon: User,
            color: 'text-brand-400 bg-brand-500/10 border-brand-500/20',
            activeColor: 'bg-brand-500/10 text-brand-300 border-brand-500/30'
        },
        {
            id: 'security',
            name: 'Security & Password',
            description: 'Keep your credentials safe',
            icon: KeyRound,
            color: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20',
            activeColor: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
        }
    ];

    return (
        <Modal show={show} onClose={onClose} maxWidth="4xl">
            <div className="flex flex-col md:flex-row h-[85vh] md:h-auto md:min-h-[500px] md:max-h-[700px] bg-[#1e293b] text-slate-100 font-sans overflow-hidden">
                
                {/* Left Sidebar / Tabs Selection */}
                <div className="w-full md:w-72 bg-[#0f172a]/40 md:border-r border-[#334155] flex flex-col shrink-0">
                    {/* Sidebar Header */}
                    <div className="p-5 md:p-6 border-b border-[#334155] flex items-center justify-between shrink-0">
                        <div>
                            <h2 className="text-lg md:text-xl font-outfit font-black text-slate-100 tracking-tight">
                                Profile Settings
                            </h2>
                            <p className="text-[10px] md:text-[11px] text-slate-400 font-medium mt-0.5">
                                Manage your staff credentials
                            </p>
                        </div>
                        {/* Close button on mobile next to header */}
                        <button 
                            onClick={onClose}
                            className="md:hidden p-1.5 rounded-lg bg-[#0f172a] hover:bg-[#334155]/60 text-slate-400 hover:text-slate-200 transition-colors border border-[#334155]"
                        >
                            <X size={16} />
                        </button>
                    </div>

                    {/* Navigation CustomSelect Dropdown */}
                    <div className="p-4 shrink-0">
                        <CustomSelect
                            value={activeTab}
                            onChange={setActiveTab}
                            className="bg-[#0f172a] hover:bg-[#0f172a]/80"
                            options={tabs.map(opt => ({
                                key: opt.id,
                                label: `${opt.name} - ${opt.description}`
                            }))}
                        />
                    </div>
                </div>

                {/* Right Content / Active Form Panel */}
                <div className="flex-1 flex flex-col min-w-0 bg-transparent overflow-hidden">
                    {/* Header bar with close button - Hidden on Mobile to save vertical space */}
                    <div className="hidden md:flex h-16 px-6 border-b border-[#334155] items-center justify-between shrink-0 bg-[#0f172a]/20">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] uppercase font-black tracking-widest text-brand-400 font-mono">
                                System / User
                            </span>
                        </div>
                        <button
                            onClick={onClose}
                            className="flex items-center justify-center p-2 rounded-xl bg-[#0f172a] hover:bg-[#334155] text-slate-400 hover:text-slate-200 transition-colors border border-[#334155]"
                        >
                            <X size={16} />
                        </button>
                    </div>

                    {/* Form scrollable viewport */}
                    <div className="flex-1 p-5 md:p-8 overflow-y-auto min-h-0 scrollbar-thin">
                        <div className="max-w-2xl mx-auto pb-6">
                            {activeTab === 'profile' && (
                                <div className="animate-fadeIn">
                                    <UpdateProfileInformationForm />
                                </div>
                            )}

                            {activeTab === 'security' && (
                                <div className="animate-fadeIn">
                                    <UpdatePasswordForm />
                                </div>
                            )}
                        </div>
                    </div>
                </div>

            </div>
        </Modal>
    );
}
