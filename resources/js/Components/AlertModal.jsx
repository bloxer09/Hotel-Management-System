import React from 'react';
import BaseModal from '@/Components/UI/BaseModal';
import Button from '@/Components/UI/Button';
import { Info } from 'lucide-react';

export default function AlertModal({ isOpen, onClose, title, message }) {
    return (
        <BaseModal
            isOpen={isOpen}
            onClose={onClose}
            maxWidth="max-w-sm"
            showCloseButton={false}
            zIndex="z-[3000]"
            footer={
                <Button variant="primary" onClick={onClose} className="w-full">
                    OK
                </Button>
            }
        >
            <div className="flex flex-col items-center text-center gap-4">
                <div className="p-4 rounded-full bg-brand-500/10 text-brand-400">
                    <Info size={32} />
                </div>
                <div className="flex flex-col gap-2">
                    <h3 className="font-outfit font-extrabold text-lg text-slate-100">
                        {title || 'Information'}
                    </h3>
                    <p className="text-xs text-slate-400 font-medium leading-relaxed">
                        {message}
                    </p>
                </div>
            </div>
        </BaseModal>
    );
}
