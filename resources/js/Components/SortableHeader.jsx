import React from 'react';
import { router } from '@inertiajs/react';


export default function SortableHeader({
    sortKey,
    currentSortBy,
    currentSortDir,
    children,
    className = "px-6 py-4 cursor-pointer hover:bg-[#334155]/30 transition-colors group"
}) {
    const isActive = currentSortBy === sortKey;

    const handleSort = () => {
        const urlParams = new URLSearchParams(window.location.search);

        let newSortDir = 'asc';
        if (isActive) {
            newSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
        }

        urlParams.set('sort_by', sortKey);
        urlParams.set('sort_dir', newSortDir);
        urlParams.delete('page');

        router.get(window.location.pathname, Object.fromEntries(urlParams), {
            preserveState: true,
            preserveScroll: true
        });
    };

    return (
        <th className={className} onClick={handleSort}>
            <div className="flex items-center gap-2">
                <span>{children}</span>
            </div>
        </th>
    );
}