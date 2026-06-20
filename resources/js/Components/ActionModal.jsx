import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { X } from 'lucide-react';

export default function ActionModal({ isOpen, onClose, title, children }) {
    return (
        <AnimatePresence>
            {isOpen && (
                <div className="fixed inset-0 z-[2000] flex items-center justify-center p-4">
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="absolute inset-0 bg-[#070b13]/90"
                        onClick={onClose}
                    />
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95, y: 15 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: 15 }}
                        transition={{ type: "spring", stiffness: 350, damping: 25 }}
                        className="bg-[#0f172a] border border-[#334155] rounded-2xl shadow-2xl w-full max-w-xs relative z-10 overflow-hidden flex flex-col"
                    >
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
                    </motion.div>
                </div>
            )}
        </AnimatePresence>
    );
}
