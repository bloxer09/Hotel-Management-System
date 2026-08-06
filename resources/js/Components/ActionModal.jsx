import React from 'react';
import BaseModal from '@/Components/UI/BaseModal';

export default function ActionModal({ isOpen, onClose, title, children }) {
    return (
        <BaseModal
            isOpen={isOpen}
            onClose={onClose}
            title={title || 'Manage Actions'}
            maxWidth="max-w-xs"
            zIndex="z-[2000]"
        >
            <div className="flex flex-col gap-2">
                {children}
            </div>
        </BaseModal>
    );
}
