import React, { Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { X, ZoomIn } from 'lucide-react';

export default function ImagePreviewModal({ isOpen, imageUrl, onClose, altText = "Image Preview" }) {
    if (!imageUrl) return null;

    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-[9999]">
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
                    <div className="fixed inset-0 bg-[#070b13]/90" />
                </TransitionChild>

                {/* Dialog Content wrapper */}
                <div className="fixed inset-0 flex items-center justify-center p-4 md:p-12">
                    <TransitionChild
                        as={Fragment}
                        enter="ease-out duration-300"
                        enterFrom="opacity-0 scale-95"
                        enterTo="opacity-100 scale-100"
                        leave="ease-in duration-200"
                        leaveFrom="opacity-100 scale-100"
                        leaveTo="opacity-0 scale-95"
                    >
                        <DialogPanel className="relative max-w-4xl max-h-full flex flex-col pointer-events-auto">
                            <div className="flex justify-end mb-4">
                                <button
                                    onClick={onClose}
                                    className="p-2 rounded-xl bg-[#1e293b] border border-[#334155] text-slate-300 hover:text-white hover:bg-red-500/20 hover:border-red-500/50 transition-all shadow-xl"
                                >
                                    <X size={20} />
                                </button>
                            </div>
                            <div className="bg-[#1e293b] p-2 rounded-2xl shadow-2xl border border-[#334155] overflow-hidden flex-1 min-h-0 flex items-center justify-center">
                                <img 
                                    src={imageUrl} 
                                    alt={altText}
                                    className="max-w-full max-h-[75vh] object-contain rounded-xl"
                                />
                            </div>
                            <div className="mt-4 text-center text-slate-400 text-xs font-semibold uppercase tracking-widest flex items-center justify-center gap-2">
                                <ZoomIn size={14} /> {altText}
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </Transition>
    );
}
