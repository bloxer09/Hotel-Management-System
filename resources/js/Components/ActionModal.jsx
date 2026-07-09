import React, { Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { X } from 'lucide-react';

export default function ActionModal({ isOpen, onClose, title, children }) {
    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-[2000]">
                {/* Backdrop transition */}
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-[#070b13]/80 backdrop-blur-sm" />
                </TransitionChild>

                {/* Dialog Content */}
                <div className="fixed inset-0 flex items-center justify-center p-4">
                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100"
                        leaveTo="opacity-0 scale-95"
                    >
                        <DialogPanel className="bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden flex flex-col relative z-10">
                            <div className="flex items-center justify-between p-4 border-b border-[#334155] bg-[#1e293b]/60">
                                <h3 className="font-outfit font-extrabold text-sm text-slate-100 uppercase tracking-wider">{title || 'Manage Actions'}</h3>
                                <button
                                    onClick={onClose}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-[#334155] transition-colors"
                                >
                                    <X size={16} />
                                </button>
                            </div>
                            <div className="p-3 flex flex-col gap-2">
                                {children}
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
