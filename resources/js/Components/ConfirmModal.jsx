import React from 'react';
import BaseModal from '@/Components/UI/BaseModal';
import { AlertTriangle } from 'lucide-react';

export default function ConfirmModal({
    isOpen,
    onClose,
    onConfirm,
    title,
    message,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    isDanger = true,
}) {
    const handleConfirm = () => {
        onConfirm();
        onClose();
    };

    return (
        <BaseModal
            isOpen={isOpen}
            onClose={onClose}
            maxWidth="max-w-md"
            showCloseButton={false}
            zIndex="z-[3000]"
            footer={
                <>
                    <button
                        onClick={onClose}
                        className="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition-all w-full sm:w-auto"
                    >
                        {cancelText}
                    </button>
                    <button
                        onClick={handleConfirm}
                        className={`px-5 py-2.5 rounded-xl text-xs font-bold text-white transition-all w-full sm:w-auto ${
                            isDanger
                                ? 'bg-rose-600 hover:bg-rose-500'
                                : 'bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-900/20'
                        }`}
                    >
                        {confirmText}
                    </button>
                </>
            }
        >
            <div className="flex flex-col items-center text-center gap-4">
                <div className={`p-4 rounded-full ${isDanger ? 'bg-rose-500/10 text-rose-500' : 'bg-amber-500/10 text-amber-500'}`}>
                    <AlertTriangle size={32} />
                </div>
                <div className="flex flex-col gap-2">
                    <h3 className="font-outfit font-extrabold text-xl text-slate-100">
                        {title || 'Confirm Action'}
                    </h3>
                    <p className="text-sm text-slate-400 font-medium leading-relaxed">
                        {message || 'Are you sure you want to proceed?'}
                    </p>
                </div>
            </div>
        </BaseModal>
    );
}
