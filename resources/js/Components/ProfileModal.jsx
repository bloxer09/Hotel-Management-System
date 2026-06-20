import React, { useState } from 'react';
import Modal from '@/Components/Modal';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import { User, KeyRound, X } from 'lucide-react';

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

                    {/* Navigation list - Scrollable on mobile, stacked on desktop */}
                    <nav className="flex flex-row md:flex-col gap-2 p-4 overflow-x-auto md:overflow-x-visible scrollbar-none shrink-0">
                        {tabs.map((tab) => {
                            const IconComponent = tab.icon;
                            const isActive = activeTab === tab.id;

                            return (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`flex items-center md:items-start gap-2.5 md:gap-3.5 px-3 md:px-4 py-2 md:py-3.5 rounded-xl text-left transition-all duration-200 group relative shrink-0 md:shrink md:w-full ${
                                        isActive 
                                            ? 'bg-brand-600/10 text-brand-400 border border-brand-500/20 shadow-lg shadow-brand-600/5' 
                                            : 'text-slate-400 hover:bg-[#334155]/40 hover:text-slate-200 border border-transparent'
                                    }`}
                                >
                                    <div className={`p-1.5 md:p-2 rounded-lg shrink-0 transition-colors ${
                                        isActive ? tab.color : 'bg-[#0f172a] text-slate-400 group-hover:text-slate-200'
                                    }`}>
                                        <IconComponent size={14} className="md:w-4 md:h-4" />
                                    </div>
                                    <div className="min-w-0">
                                        <div className={`text-xs font-outfit font-bold whitespace-nowrap ${
                                            isActive ? 'text-slate-100' : 'text-slate-300 group-hover:text-slate-100'
                                        }`}>
                                            {tab.name}
                                        </div>
                                        <div className="hidden md:block text-[10px] text-slate-400 font-medium truncate mt-0.5 group-hover:text-slate-300">
                                            {tab.description}
                                        </div>
                                    </div>
                                    
                                    {/* Selected Indicator Pill */}
                                    {isActive && (
                                        <span className="absolute left-0 top-3 bottom-3 w-1 bg-brand-500 rounded-r-md hidden md:block" />
                                    )}
                                </button>
                            );
                        })}
                    </nav>
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
