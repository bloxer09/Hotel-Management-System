import React from 'react';
import BaseModal from '@/Components/UI/BaseModal';

const MAX_WIDTH_MAP = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
};

export default function Modal({
    children,
    show = false,
    maxWidth = '2xl',
    closeable = true,
    onClose = () => {},
}) {
    const maxWidthClass = MAX_WIDTH_MAP[maxWidth] || (maxWidth.startsWith('max-w-') ? maxWidth : 'max-w-2xl');

    return (
        <BaseModal
            isOpen={show}
            onClose={onClose}
            maxWidth={maxWidthClass}
            showCloseButton={closeable}
        >
            {children}
        </BaseModal>
    );
}
