import React, { Fragment } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import { X } from 'lucide-react';

export default function BaseModal({
    isOpen,
    onClose,
    title,
    subtitle,
    children,
    footer,
    maxWidth = 'max-w-lg',
    showCloseButton = true,
    zIndex = 'z-50',
}) {
    const hasHeader = Boolean(title || showCloseButton);

    return (
        <Transition show={isOpen} as={Fragment}>
            <Dialog onClose={onClose} className={`relative ${zIndex}`}>
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-200"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto p-4 sm:p-6 md:p-10">
                    <div className="flex min-h-full items-center justify-center">
                        <TransitionChild
                            as={Fragment}
                            enter="ease-out duration-200"
                            enterFrom="opacity-0 scale-95 translate-y-2"
                            enterTo="opacity-100 scale-100 translate-y-0"
                            leave="ease-in duration-150"
                            leaveFrom="opacity-100 scale-100 translate-y-0"
                            leaveTo="opacity-0 scale-95 translate-y-2"
                        >
                            <DialogPanel
                                className={`w-full ${maxWidth} transform overflow-hidden rounded-2xl bg-slate-900 border border-slate-800 text-left align-middle shadow-xl transition-all`}
                            >
                                {hasHeader && (
                                    <div className="flex items-start justify-between gap-4 px-6 py-4 border-b border-slate-800">
                                        <div>
                                            {title && (
                                                <h3 className="text-lg font-bold text-slate-100">
                                                    {title}
                                                </h3>
                                            )}
                                            {subtitle && (
                                                <p className="text-xs text-slate-400 mt-0.5">
                                                    {subtitle}
                                                </p>
                                            )}
                                        </div>
                                        {showCloseButton && (
                                            <button
                                                onClick={onClose}
                                                className="p-1 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800 transition-colors"
                                                aria-label="Close modal"
                                            >
                                                <X className="w-5 h-5" />
                                            </button>
                                        )}
                                    </div>
                                )}

                                <div className={hasHeader || footer ? 'p-6' : 'p-6'}>
                                    {children}
                                </div>

                                {footer && (
                                    <div className="px-6 py-4 bg-slate-900/50 border-t border-slate-800 flex items-center justify-end gap-3">
                                        {footer}
                                    </div>
                                )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
