import React, { Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { Info } from 'lucide-react';

export default function AlertModal({ isOpen, onClose, title, message }) {
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
                    <div className="fixed inset-0 bg-[#070b13]/80" />
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
                        <DialogPanel className="bg-[#1e293b] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden flex flex-col relative z-10">
                            <div className="p-6 flex flex-col items-center text-center gap-4">
                                <div className="p-4 rounded-full bg-brand-500/10 text-brand-500">
                                    <Info size={32} />
                                </div>
                                <div className="flex flex-col gap-2">
                                    <h3 className="font-outfit font-extrabold text-lg text-slate-100">{title || 'Information'}</h3>
                                    <p className="text-xs text-slate-400 font-medium leading-relaxed">{message}</p>
                                </div>
                            </div>
                            <div className="p-4 border-t border-[#334155]/60 bg-[#0f172a]/40 flex justify-center">
                                <button onClick={onClose} className="px-6 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition-all w-full shadow-md shadow-brand-900/20">
                                    OK
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
