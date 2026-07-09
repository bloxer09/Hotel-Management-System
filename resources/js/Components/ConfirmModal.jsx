import React, { Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { AlertTriangle } from 'lucide-react';

export default function ConfirmModal({ isOpen, onClose, onConfirm, title, message, confirmText = "Confirm", cancelText = "Cancel", isDanger = true }) {
    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-[3000]">
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
                        <DialogPanel className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col relative z-10">
                            <div className="p-6 flex flex-col items-center text-center gap-4">
                                <div className={`p-4 rounded-full ${isDanger ? 'bg-red-500/10 text-red-500' : 'bg-amber-500/10 text-amber-500'}`}>
                                    <AlertTriangle size={32} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <h3 className="font-outfit font-extrabold text-xl text-slate-100">{title || 'Confirm Action'}</h3>
                                    <p className="text-sm text-slate-400 font-medium leading-relaxed">{message || 'Are you sure you want to proceed?'}</p>
                                </div>
                            </div>
                            <div className="p-5 border-t border-[#334155]/60 bg-[#0f172a]/40 flex justify-end gap-3">
                                <button onClick={onClose} className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition-all w-full sm:w-auto">
                                    {cancelText}
                                </button>
                                <button onClick={() => { onConfirm(); onClose(); }} className={`px-5 py-2.5 rounded-xl text-xs font-bold text-white transition-all w-full sm:w-auto ${isDanger ? 'bg-red-600 hover:bg-red-500' : 'bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-900/20'}`}>
                                    {confirmText}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
